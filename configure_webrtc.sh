#!/usr/bin/env bash
# Configure and verify WebRTC for an Astera PBX installed by install_new_pbx.sh.
# Browser TLS terminates at Nginx; Nginx proxies WebSocket to local Asterisk WS.

if [ -z "${BASH_VERSION:-}" ]; then
    if command -v bash >/dev/null 2>&1; then
        exec bash "$0" "$@"
    fi
    echo "Bu betik Bash gerektirir." >&2
    exit 1
fi

set -Eeuo pipefail
umask 027

PANEL_DOMAIN="${PANEL_DOMAIN:-pbx001.asterapbx.net}"
NGINX_SITE="${NGINX_SITE:-/etc/nginx/sites-available/astera}"
APP_DIR="${APP_DIR:-/var/www/astera}"
BACKUP_DIR="/root/astera-webrtc-backup-$(date +%Y%m%d-%H%M%S)"
completed=0

die() {
    echo "HATA: $*" >&2
    exit 1
}

if [[ $EUID -ne 0 ]]; then
    die "Root olarak calistirin: sudo bash configure_webrtc.sh"
fi

for command_name in asterisk curl nginx python3 systemctl; do
    command -v "$command_name" >/dev/null 2>&1 \
        || die "Gerekli komut bulunamadi: $command_name"
done

[[ -f /etc/asterisk/asterisk.conf ]] || die "/etc/asterisk/asterisk.conf bulunamadi."
[[ -f /etc/asterisk/pjsip.conf ]] || die "/etc/asterisk/pjsip.conf bulunamadi."
[[ -f "$NGINX_SITE" ]] || die "Nginx site dosyasi bulunamadi: $NGINX_SITE"

mkdir -p "$BACKUP_DIR"
cp -a /etc/asterisk/http.conf "$BACKUP_DIR/http.conf" 2>/dev/null || true
cp -a /etc/asterisk/pjsip.conf "$BACKUP_DIR/pjsip.conf"
cp -a /etc/asterisk/pjsip_endpoints.conf "$BACKUP_DIR/pjsip_endpoints.conf" \
    2>/dev/null || true
cp -a /etc/asterisk/rtp.conf "$BACKUP_DIR/rtp.conf" 2>/dev/null || true
cp -a "$NGINX_SITE" "$BACKUP_DIR/nginx-site"
if [[ -f "$APP_DIR/includes/generator.php" ]]; then
    cp -a "$APP_DIR/includes/generator.php" "$BACKUP_DIR/generator.php"
fi

restore_on_error() {
    local code=$?
    if ((code != 0 && completed == 0)); then
        echo "WebRTC ayari basarisiz; onceki dosyalar geri yukleniyor." >&2
        [[ -f "$BACKUP_DIR/http.conf" ]] \
            && cp -a "$BACKUP_DIR/http.conf" /etc/asterisk/http.conf
        cp -a "$BACKUP_DIR/pjsip.conf" /etc/asterisk/pjsip.conf
        [[ -f "$BACKUP_DIR/pjsip_endpoints.conf" ]] \
            && cp -a "$BACKUP_DIR/pjsip_endpoints.conf" \
                /etc/asterisk/pjsip_endpoints.conf
        [[ -f "$BACKUP_DIR/rtp.conf" ]] \
            && cp -a "$BACKUP_DIR/rtp.conf" /etc/asterisk/rtp.conf
        cp -a "$BACKUP_DIR/nginx-site" "$NGINX_SITE"
        [[ -f "$BACKUP_DIR/generator.php" ]] \
            && cp -a "$BACKUP_DIR/generator.php" "$APP_DIR/includes/generator.php"
        nginx -t >/dev/null 2>&1 && systemctl reload nginx 2>/dev/null || true
        systemctl restart asterisk 2>/dev/null || true
    fi
    exit "$code"
}
trap restore_on_error EXIT

echo "WebRTC yedegi: $BACKUP_DIR"

ASTERISK_MODULE_DIR="$(
    awk -F'=>|=' '
        /^[[:space:]]*astmoddir[[:space:]]*(=>|=)/ {
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2)
            print $2
            exit
        }
    ' /etc/asterisk/asterisk.conf
)"
if [[ -z "$ASTERISK_MODULE_DIR" || ! -d "$ASTERISK_MODULE_DIR" ]]; then
    module_file="$(find /usr/lib /usr/lib64 /usr/local/lib \
        -type f -name res_http_websocket.so -print -quit 2>/dev/null || true)"
    [[ -n "$module_file" ]] || die "Asterisk WebSocket modulu bulunamadi."
    ASTERISK_MODULE_DIR="$(dirname "$module_file")"
fi

for module_name in \
    res_http_websocket.so \
    res_pjsip_transport_websocket.so \
    res_pjsip.so \
    chan_pjsip.so; do
    [[ -r "$ASTERISK_MODULE_DIR/$module_name" ]] \
        || die "Asterisk modulu eksik: $ASTERISK_MODULE_DIR/$module_name"
done

CODEC_NOTE="Opus ceviricisi mevcut"
if [[ ! -r "$ASTERISK_MODULE_DIR/codec_opus.so" ]]; then
    CODEC_NOTE="codec_opus yok; WebRTC ulaw/alaw ortak codec kullanacak"
    if [[ -f /etc/asterisk/pjsip_endpoints.conf ]]; then
        python3 - /etc/asterisk/pjsip_endpoints.conf <<'PY'
from pathlib import Path
import re
import sys

path = Path(sys.argv[1])
lines = []
for line in path.read_text(encoding="utf-8").splitlines(keepends=True):
    match = re.match(r"^(\s*allow\s*=\s*)([^;\r\n]+)(.*)$", line, re.I)
    if match is None:
        lines.append(line)
        continue
    codecs = [item.strip() for item in match.group(2).split(",")]
    if not any(item.lower() == "opus" for item in codecs):
        lines.append(line)
        continue
    codecs = [item for item in codecs if item.lower() != "opus"]
    if not codecs:
        codecs = ["ulaw", "alaw"]
    newline = "\n" if line.endswith("\n") else ""
    lines.append(match.group(1) + ",".join(codecs) + newline)
path.write_text("".join(lines), encoding="utf-8")
PY
        chown asterisk:asterisk /etc/asterisk/pjsip_endpoints.conf
        chmod 0640 /etc/asterisk/pjsip_endpoints.conf
    fi

    if [[ -f "$APP_DIR/includes/generator.php" ]]; then
        python3 - "$APP_DIR/includes/generator.php" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
body = path.read_text(encoding="utf-8")
old = """            if (!in_array('opus', $codecs, true)) {
                array_unshift($codecs, 'opus');
            }
"""
new = """            // ASTERA: codec_opus translator is unavailable on this PBX.
            $codecs = array_values(array_filter(
                $codecs,
                static fn($codec) => strtolower((string) $codec) !== 'opus'
            ));
            if (!$codecs) {
                $codecs = ['ulaw', 'alaw'];
            }
"""
if "codec_opus translator is unavailable" not in body:
    if old not in body:
        raise SystemExit("generator.php Opus blogu beklenen bicimde degil")
    body = body.replace(old, new, 1)
    path.write_text(body, encoding="utf-8")
PY
        chown astera-panel:www-data "$APP_DIR/includes/generator.php"
        chmod 0640 "$APP_DIR/includes/generator.php"
        php -l "$APP_DIR/includes/generator.php" >/dev/null
    fi
fi

chown -R root:asterisk "$ASTERISK_MODULE_DIR"
find "$ASTERISK_MODULE_DIR" -type d -exec chmod 0755 {} +
find "$ASTERISK_MODULE_DIR" -type f -name '*.so' -exec chmod 0644 {} +

install -d -m 0750 -o asterisk -g asterisk /etc/asterisk/keys
if [[ ! -s /etc/asterisk/keys/asterisk.crt \
    || ! -s /etc/asterisk/keys/asterisk.key ]]; then
    openssl req -x509 -nodes -days 825 -newkey rsa:3072 \
        -keyout /etc/asterisk/keys/asterisk.key \
        -out /etc/asterisk/keys/asterisk.crt \
        -subj "/CN=$PANEL_DOMAIN" \
        -addext "subjectAltName=DNS:$PANEL_DOMAIN"
fi
cat /etc/asterisk/keys/asterisk.crt /etc/asterisk/keys/asterisk.key \
    >/etc/asterisk/keys/asterisk.pem
chown asterisk:asterisk /etc/asterisk/keys/asterisk.crt \
    /etc/asterisk/keys/asterisk.key /etc/asterisk/keys/asterisk.pem
chmod 0640 /etc/asterisk/keys/asterisk.crt \
    /etc/asterisk/keys/asterisk.key /etc/asterisk/keys/asterisk.pem

cat >/etc/asterisk/http.conf <<'ASTERISK_HTTP'
; ASTERA local HTTP / WebSocket endpoint.
; Public WSS terminates at Nginx and is proxied to this loopback socket.
[general]
enabled=yes
bindaddr=127.0.0.1
bindport=8088
enablestatic=no
enable_status=yes
sessionlimit=200
tlsenable=no
ASTERISK_HTTP
chown asterisk:asterisk /etc/asterisk/http.conf
chmod 0640 /etc/asterisk/http.conf

if [[ -f /etc/asterisk/rtp.conf ]]; then
    if grep -Eq '^[[:space:]]*icesupport[[:space:]]*=' /etc/asterisk/rtp.conf; then
        sed -i -E \
            's/^[[:space:]]*icesupport[[:space:]]*=.*/icesupport=yes/' \
            /etc/asterisk/rtp.conf
    elif grep -Eq '^[[:space:]]*\[general\]' /etc/asterisk/rtp.conf; then
        sed -i '/^[[:space:]]*\[general\]/a icesupport=yes' /etc/asterisk/rtp.conf
    else
        printf '\n[general]\nicesupport=yes\n' >>/etc/asterisk/rtp.conf
    fi
else
    printf '[general]\nicesupport=yes\nrtpstart=10000\nrtpend=20000\n' \
        >/etc/asterisk/rtp.conf
fi
chown asterisk:asterisk /etc/asterisk/rtp.conf
chmod 0640 /etc/asterisk/rtp.conf

python3 - /etc/asterisk/pjsip.conf <<'PY'
from pathlib import Path
import re
import sys

path = Path(sys.argv[1])
body = path.read_text(encoding="utf-8")
transport = """[transport-ws]
type=transport
protocol=ws
bind=0.0.0.0
allow_reload=yes
"""
pattern = re.compile(
    r"(?ms)^\[transport-ws\]\s*\n.*?(?=^\[[^\]]+\]\s*(?:\([^)]*\))?\s*$|\Z)"
)
if pattern.search(body):
    body = pattern.sub(transport + "\n", body, count=1)
else:
    body = body.rstrip() + "\n\n" + transport + "\n"
path.write_text(body, encoding="utf-8")
PY
chown asterisk:asterisk /etc/asterisk/pjsip.conf
chmod 0640 /etc/asterisk/pjsip.conf

python3 - "$NGINX_SITE" <<'PY'
from pathlib import Path
import re
import sys

path = Path(sys.argv[1])
body = path.read_text(encoding="utf-8")
location = """
    location = /asterisk-ws {
        proxy_pass http://127.0.0.1:8088/ws;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_buffering off;
        proxy_read_timeout 86400;
        proxy_send_timeout 86400;
    }
"""
pattern = re.compile(
    r"(?ms)\n[ \t]*location[ \t]+=[ \t]+/asterisk-ws[ \t]*\{.*?^[ \t]*\}"
)
if pattern.search(body):
    body = pattern.sub(location.rstrip(), body, count=1)
else:
    marker = re.search(r"(?m)^[ \t]*location[ \t]+/[ \t]*\{", body)
    if marker is None:
        raise SystemExit("Nginx HTTPS server blogunda location / bulunamadi")
    body = body[:marker.start()] + location + body[marker.start():]
path.write_text(body, encoding="utf-8")
PY

nginx -t
systemctl reload nginx
systemctl enable asterisk
systemctl restart asterisk

for _ in {1..20}; do
    if asterisk -rx 'core show version' 2>/dev/null | grep -q '^Asterisk '; then
        break
    fi
    sleep 1
done
systemctl is-active --quiet asterisk || {
    systemctl status asterisk --no-pager -l || true
    journalctl -u asterisk -n 100 --no-pager || true
    die "Asterisk baslatilamadi."
}

for module_name in res_http_websocket.so res_pjsip_transport_websocket.so; do
    if ! asterisk -rx "module show like $module_name" \
        | grep -Fq "$module_name"; then
        asterisk -rx "module load $module_name" >/dev/null 2>&1 || true
    fi
    asterisk -rx "module show like $module_name" \
        | grep -Fq "$module_name" \
        || die "Asterisk modulu yuklenemedi: $module_name"
done

HTTP_STATUS="$(asterisk -rx 'http show status' 2>&1 || true)"
grep -Fq 'HTTP Server Status:' <<<"$HTTP_STATUS" \
    || die "Asterisk HTTP durum komutu calismadi."
grep -Eq '127\.0\.0\.1:8088|0\.0\.0\.0:8088' <<<"$HTTP_STATUS" \
    || die "Asterisk HTTP 8088 portunda dinlemiyor."

TRANSPORT_STATUS="$(asterisk -rx 'pjsip show transports' 2>&1 || true)"
grep -Eiq 'transport-ws.*ws|ws.*transport-ws' <<<"$TRANSPORT_STATUS" \
    || die "PJSIP transport-ws etkin degil."

ws_headers="$(
    curl --silent --show-error --include --no-buffer --max-time 3 \
        --http1.1 \
        -H 'Connection: Upgrade' \
        -H 'Upgrade: websocket' \
        -H 'Sec-WebSocket-Protocol: sip' \
        -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
        -H 'Sec-WebSocket-Version: 13' \
        http://127.0.0.1:8088/ws 2>&1 || true
)"
grep -Fq '101 Switching Protocols' <<<"$ws_headers" \
    || {
        printf '%s\n' "$ws_headers" >&2
        die "Asterisk yerel WebSocket testi basarisiz."
    }

wss_headers="$(
    curl --insecure --silent --show-error --include --no-buffer --max-time 3 \
        --http1.1 \
        --resolve "$PANEL_DOMAIN:443:127.0.0.1" \
        -H 'Connection: Upgrade' \
        -H 'Upgrade: websocket' \
        -H 'Sec-WebSocket-Protocol: sip' \
        -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
        -H 'Sec-WebSocket-Version: 13' \
        "https://$PANEL_DOMAIN/asterisk-ws" 2>&1 || true
)"
grep -Fq '101 Switching Protocols' <<<"$wss_headers" \
    || {
        printf '%s\n' "$wss_headers" >&2
        die "Nginx WSS proxy testi basarisiz."
    }

if command -v ufw >/dev/null 2>&1 && ufw status | grep -q '^Status: active'; then
    ufw allow 443/tcp
    ufw allow 10000:20000/udp
fi

completed=1
trap - EXIT
echo
echo "WebRTC yapilandirmasi basarili."
echo "WSS: wss://$PANEL_DOMAIN/asterisk-ws"
echo "Asterisk HTTP: 127.0.0.1:8088/ws"
echo "RTP: UDP 10000-20000"
echo "Codec: $CODEC_NOTE"
echo
echo "Son kontrol:"
echo "  1. Panelde WebRTC etkin bir abone secin."
echo "  2. Tarayicida mikrofon izni verin."
echo "  3. Kayit durumunun 'Baglandi' oldugunu dogrulayin."
