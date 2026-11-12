#!/bin/bash
set -Eeuo pipefail

BASE_DOMAIN="${ASTERA_BASE_DOMAIN:-asterapbx.net}"
ASTERISK_DIR="/etc/asterisk/keys/letsencrypt"
ASTERISK_COMBINED="/etc/asterisk/keys/asterisk.pem"
ASTERISK_KEY="/etc/asterisk/keys/asterisk.key"
LOG="/var/log/asterisk/cert-renewal.log"

DOMAIN="${1:-}"
if [ -z "$DOMAIN" ] && [ -n "${RENEWED_DOMAINS:-}" ]; then
    DOMAIN="$(awk '{print $1}' <<<"$RENEWED_DOMAINS")"
fi
DOMAIN="${DOMAIN,,}"

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"
}

if [[ ! "$DOMAIN" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.${BASE_DOMAIN//./\\.}$ ]]; then
    log "ERROR: Geçersiz domain: ${DOMAIN:-boş}"
    exit 1
fi

LE_DIR="/etc/letsencrypt/live/${DOMAIN}"
FULLCHAIN="${LE_DIR}/fullchain.pem"
PRIVKEY="${LE_DIR}/privkey.pem"

if [ ! -s "$FULLCHAIN" ] || [ ! -s "$PRIVKEY" ]; then
    log "ERROR: Sertifika veya private key bulunamadı: ${LE_DIR}"
    exit 1
fi

CERT_HASH="$(
    openssl x509 -in "$FULLCHAIN" -pubkey -noout |
        openssl pkey -pubin -outform DER |
        sha256sum | awk '{print $1}'
)"
KEY_HASH="$(
    openssl pkey -in "$PRIVKEY" -pubout |
        openssl pkey -pubin -outform DER |
        sha256sum | awk '{print $1}'
)"
if [ "$CERT_HASH" != "$KEY_HASH" ]; then
    log "ERROR: Sertifika ve private key eşleşmiyor: ${DOMAIN}"
    exit 1
fi
if ! openssl x509 -in "$FULLCHAIN" -noout -checkend 86400; then
    log "ERROR: Sertifikanın geçerlilik süresi 24 saatten az: ${DOMAIN}"
    exit 1
fi

NGINX_SITE=""
if [ -f /etc/nginx/sites-available/astera ]; then
    NGINX_SITE="/etc/nginx/sites-available/astera"
elif [ -f /etc/nginx/sites-enabled/astera ]; then
    NGINX_SITE="/etc/nginx/sites-enabled/astera"
fi
if [ -z "$NGINX_SITE" ]; then
    log "ERROR: Astera Nginx site yapılandırması bulunamadı."
    exit 1
fi

TMP_NGINX="$(mktemp)"
if ! python3 - "$NGINX_SITE" "$DOMAIN" >"$TMP_NGINX" <<'PY'
import ipaddress
import pathlib
import re
import sys

site = pathlib.Path(sys.argv[1])
domain = sys.argv[2]
source = site.read_text(encoding="utf-8")

def server_names(match):
    retained = []
    for value in match.group(2).split():
        try:
            ipaddress.ip_address(value)
            retained.append(value)
        except ValueError:
            pass
    return match.group(1) + " ".join([domain, *retained]) + match.group(3)

updated, server_count = re.subn(
    r"(?m)^(\s*server_name\s+)([^;\n]+)(\s*;[^\n]*)$",
    server_names,
    source,
)
updated, cert_count = re.subn(
    r"(?m)^(\s*ssl_certificate\s+)\S+(\s*;[^\n]*)$",
    rf"\g<1>/etc/letsencrypt/live/{domain}/fullchain.pem\g<2>",
    updated,
)
updated, key_count = re.subn(
    r"(?m)^(\s*ssl_certificate_key\s+)\S+(\s*;[^\n]*)$",
    rf"\g<1>/etc/letsencrypt/live/{domain}/privkey.pem\g<2>",
    updated,
)
updated, panel_domain_count = re.subn(
    r"(?m)^(\s*fastcgi_param\s+ASTERA_PANEL_DOMAIN\s+)\S+(\s*;\s*)$",
    rf"\g<1>{domain}\g<2>",
    updated,
)
if panel_domain_count == 0:
    def add_panel_domain(match):
        indent = match.group(1)
        return (
            match.group(0)
            + f"\n{indent}fastcgi_param ASTERA_PANEL_DOMAIN {domain};"
        )

    updated, panel_domain_count = re.subn(
        r"(?m)^([ \t]*)fastcgi_pass\s+\S+\s*;[ \t]*$",
        add_panel_domain,
        updated,
        count=1,
    )
if server_count == 0 or cert_count == 0 or key_count == 0 or panel_domain_count == 0:
    raise SystemExit("Nginx server_name/sertifika satırları bulunamadı")
sys.stdout.write(updated)
PY
then
    rm -f "$TMP_NGINX"
    log "ERROR: Nginx alan adı ve sertifika yolları hazırlanamadı."
    exit 1
fi

NGINX_CHANGED=0
NGINX_BACKUP=""
if ! cmp -s "$TMP_NGINX" "$NGINX_SITE"; then
    install -d -o root -g root -m 0700 /var/backups/astera/nginx
    NGINX_BACKUP="/var/backups/astera/nginx/astera-$(date +%Y%m%d-%H%M%S).conf"
    cp -a "$NGINX_SITE" "$NGINX_BACKUP"
    install -o root -g root -m 0644 "$TMP_NGINX" "$NGINX_SITE"
    NGINX_CHANGED=1
fi
rm -f "$TMP_NGINX"

if ! nginx -t; then
    if [ -n "$NGINX_BACKUP" ]; then
        cp -a "$NGINX_BACKUP" "$NGINX_SITE"
    fi
    log "ERROR: Nginx yapılandırması doğrulanamadı; önceki yapılandırma geri yüklendi."
    exit 1
fi

install -d -o asterisk -g asterisk -m 0750 "$ASTERISK_DIR"
install -o asterisk -g asterisk -m 0644 "$FULLCHAIN" "${ASTERISK_DIR}/fullchain.pem"
install -o asterisk -g asterisk -m 0640 "$PRIVKEY" "${ASTERISK_DIR}/privkey.pem"

TMP_COMBINED="$(mktemp /etc/asterisk/keys/.asterisk.pem.XXXXXX)"
BACKUP=""
KEY_BACKUP=""
ASTERISK_CHANGED=0
trap 'rm -f "$TMP_COMBINED"' EXIT
cat "$FULLCHAIN" "$PRIVKEY" > "$TMP_COMBINED"
chown asterisk:asterisk "$TMP_COMBINED"
chmod 0640 "$TMP_COMBINED"

if [ -f "$ASTERISK_COMBINED" ] && cmp -s "$TMP_COMBINED" "$ASTERISK_COMBINED"; then
    rm -f "$TMP_COMBINED"
    trap - EXIT
    log "${DOMAIN} sertifikası Asterisk üzerinde zaten güncel."
else
    if [ -f "$ASTERISK_COMBINED" ]; then
        BACKUP="${ASTERISK_COMBINED}.backup-$(date +%Y%m%d-%H%M%S)"
        cp -a "$ASTERISK_COMBINED" "$BACKUP"
    fi
    mv -f "$TMP_COMBINED" "$ASTERISK_COMBINED"
    trap - EXIT
    ASTERISK_CHANGED=1
    log "${DOMAIN} sertifikası doğrulandı ve Asterisk dizinine kuruldu."
fi

if [ ! -f "$ASTERISK_KEY" ] || ! cmp -s "$PRIVKEY" "$ASTERISK_KEY"; then
    if [ -f "$ASTERISK_KEY" ]; then
        KEY_BACKUP="${ASTERISK_KEY}.backup-$(date +%Y%m%d-%H%M%S)"
        cp -a "$ASTERISK_KEY" "$KEY_BACKUP"
    fi
    install -o asterisk -g asterisk -m 0640 "$PRIVKEY" "$ASTERISK_KEY"
    ASTERISK_CHANGED=1
    log "${DOMAIN} private key dosyası Asterisk üzerinde güncellendi."
fi

openssl x509 -in "$FULLCHAIN" -noout -subject -issuer -dates | tee -a "$LOG"

if [ "$ASTERISK_CHANGED" -eq 1 ]; then
    if ! systemctl restart asterisk || ! systemctl is-active --quiet asterisk; then
        log "ERROR: Asterisk yeni sertifikayla başlatılamadı; önceki sertifika geri yükleniyor."
        if [ -n "$BACKUP" ] && [ -f "$BACKUP" ]; then
            cp -a "$BACKUP" "$ASTERISK_COMBINED"
        fi
        if [ -n "$KEY_BACKUP" ] && [ -f "$KEY_BACKUP" ]; then
            cp -a "$KEY_BACKUP" "$ASTERISK_KEY"
        fi
        systemctl restart asterisk || true
        exit 1
    fi
    log "Asterisk yeni sertifikayla başarıyla başlatıldı."
fi

if systemctl reload nginx && systemctl is-active --quiet nginx; then
    if [ "$NGINX_CHANGED" -eq 1 ]; then
        log "Nginx alan adı ${DOMAIN} olarak ayarlandı ve sertifika etkinleştirildi."
    else
        log "Nginx yeni ${DOMAIN} sertifikasıyla yeniden yüklendi."
    fi
else
    log "ERROR: Nginx yeniden yüklenemedi."
    exit 1
fi
