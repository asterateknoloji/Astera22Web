#!/usr/bin/env bash
set -Eeuo pipefail

config="${1:-/etc/nginx/sites-available/astera}"
network="${PROVISION_NETWORK:-192.168.181.0/24}"
backup="${config}.provision-backup-$(date +%Y%m%d-%H%M%S)"

[[ $EUID -eq 0 ]] || { echo "Root yetkisi gerekli." >&2; exit 1; }
[[ -f "$config" ]] || { echo "Nginx site dosyasi bulunamadi: $config" >&2; exit 1; }
[[ "$network" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}/([0-9]|[12][0-9]|3[0-2])$ ]] \
    || { echo "Gecersiz PROVISION_NETWORK: $network" >&2; exit 1; }

cp -a "$config" "$backup"
install -d -o root -g www-data -m 0750 /var/lib/astera/provisioning

python3 - "$config" "$network" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
network = sys.argv[2]
text = path.read_text(encoding="utf-8")
marker = "# ASTERA AUTOPROVISION"
location = f"""    {marker}
    location ^~ /provision/ {{
        alias /var/lib/astera/provisioning/;
        allow {network};
        deny all;
        default_type application/octet-stream;
        add_header Cache-Control "no-store";
    }}

"""

if marker not in text:
    https_anchor = "    location / {\n        try_files $uri $uri/ /index.php?$query_string;\n    }"
    if https_anchor not in text:
        raise SystemExit("HTTPS location blogu bulunamadi")
    text = text.replace(https_anchor, location + https_anchor, 1)

    http_anchor = "    return 404; # managed by Certbot"
    if http_anchor not in text:
        raise SystemExit("HTTP Certbot blogu bulunamadi")
    http_replacement = location + """    location / {
        return 404;
    }"""
    text = text.replace(http_anchor, http_replacement, 1)

path.write_text(text, encoding="utf-8")
PY

if ! nginx -t; then
    cp -a "$backup" "$config"
    nginx -t
    echo "Nginx dogrulamasi basarisiz; eski ayar geri yuklendi." >&2
    exit 1
fi

systemctl reload nginx
echo "Autoprovision Nginx erisimi etkin: $network"
