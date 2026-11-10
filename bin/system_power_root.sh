#!/usr/bin/env bash
set -Eeuo pipefail

export PATH=/usr/sbin:/usr/bin:/sbin:/bin

ACTION="${1:-}"
LOG_FILE=/var/log/astera-system-power.log
LOCK_FILE=/run/lock/astera-system-power.lock

case "$ACTION" in
  reboot|poweroff) ;;
  *)
    printf 'ERROR\tKullanim: astera-system-power reboot|poweroff\n' >&2
    exit 2
    ;;
esac

exec 9>"$LOCK_FILE"
flock -n 9 || {
  printf 'ERROR\tBaska bir sistem gucu islemi zaten planlanmis.\n' >&2
  exit 1
}

unit="astera-panel-system-power"
printf '[%s] Panelden %s planlandi; 10 saniye sonra uygulanacak.\n' \
  "$(date --iso-8601=seconds)" "$ACTION" >>"$LOG_FILE"

systemd-run \
  --quiet \
  --collect \
  --unit="$unit" \
  --on-active=10s \
  /usr/bin/systemctl "$ACTION"

printf 'OK\t%s islemi 10 saniye sonrasina planlandi.\n' "$ACTION"
