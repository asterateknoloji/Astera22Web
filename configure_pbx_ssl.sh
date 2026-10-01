#!/usr/bin/env bash
# Issue and activate the TLS certificate for a new Astera PBX.
#
# Interactive:
#   sudo bash configure_pbx_ssl.sh pbx002
#
# Non-interactive:
#   sudo LETSENCRYPT_EMAIL=admin@example.com \
#     CLOUDFLARE_API_TOKEN='...' \
#     bash configure_pbx_ssl.sh pbx002

set -Eeuo pipefail
umask 077

BASE_DOMAIN="${ASTERA_BASE_DOMAIN:-asterapbx.net}"
PBX_NAME="${1:-}"
LETSENCRYPT_EMAIL="${LETSENCRYPT_EMAIL:-}"
CLOUDFLARE_API_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
CLOUDFLARE_CREDENTIALS="${CLOUDFLARE_CREDENTIALS:-/etc/letsencrypt/cloudflare.ini}"
DEPLOY_HOOK="/usr/local/sbin/astera-cert-deploy.sh"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_HOOK="$SCRIPT_DIR/ops/ssl/astera-cert-deploy.sh"

die() {
    printf 'HATA: %s\n' "$*" >&2
    exit 1
}

if [[ $EUID -ne 0 ]]; then
    die "Root olarak çalıştırın: sudo bash configure_pbx_ssl.sh pbx002"
fi
if [[ -z "$PBX_NAME" ]]; then
    die "Santral adı gerekli. Örnek: sudo bash configure_pbx_ssl.sh pbx002"
fi

PBX_NAME="${PBX_NAME,,}"
if [[ "$PBX_NAME" == *.* ]]; then
    DOMAIN="$PBX_NAME"
else
    DOMAIN="$PBX_NAME.$BASE_DOMAIN"
fi
if [[ ! "$DOMAIN" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.${BASE_DOMAIN//./\\.}$ ]]; then
    die "Geçersiz santral alan adı: $DOMAIN"
fi

for command_name in certbot nginx openssl python3 systemctl curl; do
    command -v "$command_name" >/dev/null 2>&1 \
        || die "Gerekli komut bulunamadı: $command_name"
done
[[ -f "$SOURCE_HOOK" ]] \
    || die "Sertifika dağıtım betiği bulunamadı: $SOURCE_HOOK"
[[ -f /etc/nginx/sites-available/astera ]] \
    || die "Astera Nginx yapılandırması bulunamadı."

install -o root -g root -m 0750 "$SOURCE_HOOK" "$DEPLOY_HOOK"

if [[ ! -s "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" \
    || ! -s "/etc/letsencrypt/live/$DOMAIN/privkey.pem" ]]; then
    if ! certbot plugins 2>/dev/null | grep -q 'dns-cloudflare'; then
        apt-get update
        DEBIAN_FRONTEND=noninteractive apt-get install -y \
            python3-certbot-dns-cloudflare
    fi

    if [[ ! -s "$CLOUDFLARE_CREDENTIALS" ]]; then
        if [[ -z "$CLOUDFLARE_API_TOKEN" && -t 0 ]]; then
            read -r -s -p "Cloudflare API Token: " CLOUDFLARE_API_TOKEN
            echo
        fi
        [[ -n "$CLOUDFLARE_API_TOKEN" ]] \
            || die "CLOUDFLARE_API_TOKEN gerekli."
        install -d -o root -g root -m 0700 \
            "$(dirname "$CLOUDFLARE_CREDENTIALS")"
        printf 'dns_cloudflare_api_token = %s\n' "$CLOUDFLARE_API_TOKEN" \
            >"$CLOUDFLARE_CREDENTIALS"
        chown root:root "$CLOUDFLARE_CREDENTIALS"
        chmod 0600 "$CLOUDFLARE_CREDENTIALS"
    fi

    if [[ -z "$LETSENCRYPT_EMAIL" && -t 0 ]]; then
        read -r -p "Let's Encrypt bildirim e-postası: " LETSENCRYPT_EMAIL
    fi
    [[ "$LETSENCRYPT_EMAIL" == *@*.* ]] \
        || die "Geçerli LETSENCRYPT_EMAIL gerekli."

    certbot certonly \
        --dns-cloudflare \
        --dns-cloudflare-credentials "$CLOUDFLARE_CREDENTIALS" \
        --dns-cloudflare-propagation-seconds 30 \
        --non-interactive \
        --agree-tos \
        --keep-until-expiring \
        --email "$LETSENCRYPT_EMAIL" \
        --cert-name "$DOMAIN" \
        -d "$DOMAIN" \
        --deploy-hook "$DEPLOY_HOOK"
fi

"$DEPLOY_HOOK" "$DOMAIN"
systemctl enable --now certbot.timer

HTTP_STATUS="$(
    curl --silent --show-error \
        --output /dev/null \
        --write-out '%{http_code}' \
        --resolve "$DOMAIN:443:127.0.0.1" \
        "https://$DOMAIN/softphone_presence.php"
)"
if [[ "$HTTP_STATUS" != "401" && "$HTTP_STATUS" != "200" ]]; then
    die "HTTPS doğrulaması beklenmeyen HTTP durumuyla döndü: $HTTP_STATUS"
fi

printf '\nBaşarılı:\n'
printf '  Alan adı:   %s\n' "$DOMAIN"
printf '  Sertifika:  /etc/letsencrypt/live/%s/fullchain.pem\n' "$DOMAIN"
printf '  Meşguliyet: https://%s/softphone_presence.php\n' "$DOMAIN"
