#!/usr/bin/env bash
set -Eeuo pipefail

archive="${1:-}"
expected_checksum="${2:-}"
backup_root="/var/backups/astera"

[[ $EUID -eq 0 ]] || { echo "Restore root yetkisi gerektirir" >&2; exit 1; }
[[ $archive =~ ^/var/backups/astera/([A-Za-z0-9][A-Za-z0-9._-]*_[0-9]{8}_[0-9]{4}|AsteraBackup-[A-Za-z0-9._-]+)\.tar\.gz$ ]] \
    || { echo "Geçersiz backup yolu" >&2; exit 1; }
[[ -f "$archive" ]] || { echo "Backup dosyası bulunamadı" >&2; exit 1; }
[[ $expected_checksum =~ ^[a-f0-9]{64}$ ]] || { echo "Geçersiz checksum" >&2; exit 1; }

actual_checksum="$(sha256sum "$archive" | awk '{print $1}')"
[[ "$actual_checksum" == "$expected_checksum" ]] \
    || { echo "Backup checksum doğrulaması başarısız" >&2; exit 1; }

restore_id="$(date +%Y%m%d-%H%M%S)-$$"
work_dir="$backup_root/.restore-$restore_id"
safety_dir="$backup_root/pre-restore-$restore_id"
mkdir -p "$work_dir" "$safety_dir"
chmod 0700 "$work_dir" "$safety_dir"

cleanup() {
    rm -rf "$work_dir"
    [[ -z "${temporary_dump:-}" ]] || rm -f "$temporary_dump"
}
trap cleanup EXIT

tar -xzf "$archive" -C "$work_dir"
[[ -s "$work_dir/database/asterisk.dump" ]] \
    || { echo "Arşivde PostgreSQL yedeği bulunamadı" >&2; exit 1; }

echo "Restore öncesi güvenlik yedeği alınıyor"
temporary_dump="$(mktemp /tmp/astera-pre-restore.XXXXXX)"
chown postgres:postgres "$temporary_dump"
chmod 0600 "$temporary_dump"
runuser -u postgres -- pg_dump \
    --format=custom --no-owner --no-privileges \
    --file="$temporary_dump" asterisk
install -m 0600 -o root -g root "$temporary_dump" "$safety_dir/asterisk.dump"
tar -czf "$safety_dir/system-config.tar.gz" \
    -C / \
    etc/asterisk etc/dahdi etc/astera etc/nginx etc/letsencrypt etc/ssl \
    etc/ufw etc/fail2ban etc/systemd/system etc/netplan etc/hosts etc/hostname \
    var/www/astera/data var/lib/astera var/lib/asterisk var/spool/asterisk/voicemail \
    2>/dev/null || true

echo "PostgreSQL geri yükleniyor"
systemctl stop astera-fidelio-fias.service astera-syslog-export.service astera-alarms.timer 2>/dev/null || true
runuser -u postgres -- pg_restore \
    --clean --if-exists --no-owner --no-privileges --exit-on-error \
    --dbname=asterisk "$work_dir/database/asterisk.dump"

echo "Sistem dosyaları geri yükleniyor"
for path in \
    etc/asterisk \
    etc/dahdi \
    etc/astera \
    etc/nginx \
    etc/letsencrypt \
    etc/ssl \
    etc/ufw \
    etc/fail2ban \
    etc/nftables.conf \
    etc/iptables \
    etc/odbc.ini \
    etc/odbcinst.ini \
    etc/systemd/system \
    etc/cron.d \
    etc/logrotate.d \
    etc/sudoers.d \
    etc/netplan \
    etc/NetworkManager/system-connections \
    etc/systemd/network \
    etc/hosts \
    etc/hostname \
    var/www/astera/data \
    var/lib/astera \
    var/lib/asterisk \
    var/spool/asterisk/voicemail; do
    [[ -e "$work_dir/rootfs/$path" ]] || continue
    target="/$path"
    rm -rf "$target"
    mkdir -p "$(dirname "$target")"
    cp -a "$work_dir/rootfs/$path" "$target"
done
if [[ -d "$work_dir/rootfs/var/www/astera/app" ]]; then
    rsync -a --delete \
        --exclude='/data/' \
        "$work_dir/rootfs/var/www/astera/app/" /var/www/astera/
fi
if [[ -d "$work_dir/rootfs/var/spool/asterisk/monitor" ]]; then
    mkdir -p /var/spool/asterisk
    rsync -a --delete "$work_dir/rootfs/var/spool/asterisk/monitor/" /var/spool/asterisk/monitor/
fi

chown -R asterisk:asterisk /etc/asterisk 2>/dev/null || true
chown -R astera-panel:www-data /var/www/astera 2>/dev/null || true
chown -R asterisk:asterisk /var/lib/asterisk 2>/dev/null || true
chown -R asterisk:asterisk /var/spool/asterisk/voicemail 2>/dev/null || true
chown -R asterisk:asterisk /var/spool/asterisk/monitor 2>/dev/null || true
command -v netplan >/dev/null 2>&1 && netplan generate
command -v nginx >/dev/null 2>&1 && nginx -t

systemctl daemon-reload
systemctl restart php8.3-fpm nginx asterisk
systemctl enable --now astera-alarms.timer astera-fidelio-fias.service astera-syslog-export.service 2>/dev/null || true
echo "Restore tamamlandı. Güvenlik yedeği: $safety_dir"
