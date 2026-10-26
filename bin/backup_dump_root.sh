#!/usr/bin/env bash
set -Eeuo pipefail

output="${1:-}"
[[ $EUID -eq 0 ]] || { echo "Database dump root yetkisi gerektirir" >&2; exit 1; }
[[ $output =~ ^/var/backups/astera/\.staging-[A-Za-z0-9._-]+/database/asterisk\.dump$ ]] \
    || { echo "Geçersiz database dump yolu" >&2; exit 1; }
[[ -d "$(dirname "$output")" ]] || { echo "Database dump dizini bulunamadı" >&2; exit 1; }

temporary_dump="$(mktemp /tmp/astera-pg-dump.XXXXXX)"
cleanup() {
    rm -f "$temporary_dump"
}
trap cleanup EXIT
chown postgres:postgres "$temporary_dump"
chmod 0600 "$temporary_dump"
runuser -u postgres -- pg_dump \
    --format=custom \
    --no-owner \
    --no-privileges \
    --file="$temporary_dump" \
    asterisk
install -m 0640 -o astera-panel -g astera-panel "$temporary_dump" "$output"
