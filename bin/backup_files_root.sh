#!/usr/bin/env bash
set -Eeuo pipefail

destination="${1:-}"
include_recordings="${2:-no}"
[[ $EUID -eq 0 ]] || { echo "Dosya backup root yetkisi gerektirir" >&2; exit 1; }
[[ $destination =~ ^/var/backups/astera/\.staging-[A-Za-z0-9._-]+/rootfs$ ]] \
    || { echo "Geçersiz backup staging yolu" >&2; exit 1; }
[[ $include_recordings == "yes" || $include_recordings == "no" ]] \
    || { echo "Geçersiz kayıt seçeneği" >&2; exit 1; }

mkdir -p "$destination/var/www/astera/app"
rsync -a \
    --exclude='/.git/' \
    --exclude='/data/' \
    --exclude='/tools/' \
    /var/www/astera/ "$destination/var/www/astera/app/"

for source in \
    /var/www/astera/data \
    /etc/asterisk \
    /etc/dahdi \
    /etc/astera \
    /etc/nginx \
    /etc/letsencrypt \
    /etc/ssl \
    /etc/ufw \
    /etc/fail2ban \
    /etc/nftables.conf \
    /etc/iptables \
    /etc/odbc.ini \
    /etc/odbcinst.ini \
    /etc/systemd/system \
    /etc/cron.d \
    /etc/logrotate.d \
    /etc/sudoers.d \
    /etc/netplan \
    /etc/NetworkManager/system-connections \
    /etc/systemd/network \
    /etc/hosts \
    /etc/hostname \
    /var/lib/astera \
    /var/lib/asterisk \
    /var/spool/asterisk/voicemail; do
    [[ -e "$source" ]] || continue
    target="$destination$source"
    mkdir -p "$(dirname "$target")"
    cp -a "$source" "$target"
done

if [[ "$include_recordings" == "yes" && -d /var/spool/asterisk/monitor ]]; then
    mkdir -p "$destination/var/spool/asterisk"
    cp -a /var/spool/asterisk/monitor "$destination/var/spool/asterisk/monitor"
fi

chown -R astera-panel:astera-panel "$(dirname "$destination")"
chmod -R u+rwX,g+rX,o-rwx "$(dirname "$destination")"
