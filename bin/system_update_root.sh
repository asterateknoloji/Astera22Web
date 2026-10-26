#!/usr/bin/env bash
set -Eeuo pipefail

export PATH=/usr/sbin:/usr/bin:/sbin:/bin
export DEBIAN_FRONTEND=noninteractive
export LC_ALL=C

ACTION="${1:-}"
shift || true
LOG_FILE=/var/log/astera-system-update.log
LOCK_FILE=/run/lock/astera-system-update.lock

die() {
  printf 'ERROR\t%s\n' "$*" >&2
  exit 1
}

package_is_blocked() {
  case "$1" in
    asterisk*|dahdi*|libpri*|postgresql*|php*|nginx*|openssh*|systemd*|libc6*|linux-*|grub*|network-manager*|*netplan*|ufw*|fail2ban*|unixodbc*|odbc-postgresql*|mysql*|mariadb*|apt*|dpkg*|sudo*|initramfs*|kmod*|udev*|dbus*|polkit*|util-linux*|apparmor*|libapparmor*|cloud-init*|dnsmasq*)
      return 0
      ;;
  esac
  return 1
}

package_service() {
  case "$1" in
    asterisk*|dahdi*|libpri*) printf 'Asterisk' ;;
    postgresql*) printf 'PostgreSQL' ;;
    php*) printf 'PHP-FPM / Nginx' ;;
    nginx*) printf 'Nginx' ;;
    openssh*) printf 'SSH' ;;
    systemd*|libc6*|linux-*|grub*|apt*|dpkg*|sudo*|initramfs*|kmod*|udev*|dbus*|polkit*|util-linux*) printf 'İşletim sistemi / yeniden başlatma' ;;
    network-manager*|*netplan*|dnsmasq*) printf 'Ağ bağlantısı' ;;
    ufw*) printf 'Güvenlik duvarı' ;;
    fail2ban*) printf 'Fail2ban' ;;
    apparmor*|libapparmor*) printf 'AppArmor güvenliği' ;;
    cloud-init*) printf 'Sunucu başlangıç yapılandırması' ;;
    snapd*) printf 'Snapd' ;;
    thermald*) printf 'Thermald' ;;
    cron*) printf 'Cron' ;;
    *) printf 'Servis etkisi tespit edilmedi' ;;
  esac
}

package_reason() {
  if package_is_blocked "$1"; then
    printf 'PBX için kritik paket; panelden güncelleme engellendi'
  elif apt-mark showhold 2>/dev/null | grep -Fxq -- "$1"; then
    printf 'Paket sistem yöneticisi tarafından bekletiliyor'
  else
    printf 'APT ön kontrolü başarılı olursa güncellenebilir'
  fi
}

package_necessity() {
  local package="$1"
  local source_line="$2"
  local service="$3"
  if [[ "$source_line" == *-security* ]]; then
    printf 'Yayınlanmış güvenlik açıklarını kapatır ve sistemi bilinen saldırılara karşı güncel tutar.'
  else
    printf 'Hata düzeltmeleri ve kararlılık iyileştirmeleri içerir.'
  fi
  if package_is_blocked "$package"; then
    printf ' %s bileşenini etkilediği için bakım penceresinde kontrollü güncellenmelidir.' "$service"
  elif [[ "$service" != 'Servis etkisi tespit edilmedi' ]]; then
    printf ' Etkilenen bileşen: %s.' "$service"
  fi
}

list_updates() {
  local line package new_version old_version service risk reason necessity
  while IFS= read -r line; do
    [[ -n "$line" && "$line" != Listing... ]] || continue
    package="${line%%/*}"
    [[ "$package" =~ ^[a-z0-9][a-z0-9+.-]*$ ]] || continue
    new_version="$(awk '{print $2}' <<<"$line")"
    old_version="$(dpkg-query -W -f='${Version}' "$package" 2>/dev/null || true)"
    service="$(package_service "$package")"
    risk=safe
    if package_is_blocked "$package" || apt-mark showhold 2>/dev/null | grep -Fxq -- "$package"; then
      risk=blocked
    fi
    reason="$(package_reason "$package")"
    necessity="$(package_necessity "$package" "$line" "$service")"
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
      "$package" "$old_version" "$new_version" "$service" "$risk" "$reason" "$necessity"
  done < <(apt list --upgradable 2>/dev/null)
}

validate_packages() {
  (($# > 0 && $# <= 100)) || die 'Bir işlemde 1-100 paket seçilebilir.'
  local package
  for package in "$@"; do
    [[ "$package" =~ ^[a-z0-9][a-z0-9+.-]*$ ]] || die "Geçersiz paket adı: $package"
    dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -Fq 'install ok installed' \
      || die "Paket kurulu değil: $package"
    package_is_blocked "$package" && die "Kritik paket panelden güncellenemez: $package"
    apt-mark showhold 2>/dev/null | grep -Fxq -- "$package" \
      && die "Bekletilen paket güncellenemez: $package"
  done
}

preflight_apply() {
  [[ ! -e /var/run/reboot-required ]] \
    || die 'Sunucu bekleyen yeniden başlatma gerektiriyor. Önce planlı yeniden başlatma yapın.'
  [[ -z "$(dpkg --audit 2>/dev/null)" ]] \
    || die 'DPKG bütünlük kontrolü başarısız. Güncelleme iptal edildi.'
  local root_free cache_free simulation installed
  root_free="$(df -Pk / | awk 'NR==2 {print $4}')"
  cache_free="$(df -Pk /var/cache/apt/archives | awk 'NR==2 {print $4}')"
  ((root_free >= 1048576 && cache_free >= 524288)) \
    || die 'Güncelleme için yeterli boş disk alanı yok.'
  simulation="$(apt-get -s --no-remove --no-install-recommends install --only-upgrade "$@" 2>&1)" \
    || die "APT ön kontrolü başarısız: $simulation"
  grep -q '^Remv ' <<<"$simulation" && die 'APT paket kaldırmak istiyor; işlem güvenlik nedeniyle iptal edildi.'
  while IFS= read -r installed; do
    installed="${installed#Inst }"
    installed="${installed%% *}"
    [[ -n "$installed" ]] || continue
    local allowed=false requested
    for requested in "$@"; do
      [[ "$installed" == "$requested" ]] && allowed=true
    done
    $allowed || die "Seçilmeyen bağımlılık paketi değişecek: $installed"
  done < <(grep '^Inst ' <<<"$simulation" || true)
}

case "$ACTION" in
  list)
    list_updates
    ;;
  refresh)
    flock -n 9 || die 'Başka bir paket işlemi devam ediyor.'
    apt-get update >>"$LOG_FILE" 2>&1 || {
      tail -n 30 "$LOG_FILE" >&2
      die 'Paket listesi yenilenemedi.'
    }
    printf 'OK\tPaket listesi yenilendi.\n'
    ;;
  apply)
    flock -n 9 || die 'Başka bir paket işlemi devam ediyor.'
    validate_packages "$@"
    preflight_apply "$@"
    {
      printf '\n[%s] Astera seçmeli güncelleme: %s\n' "$(date --iso-8601=seconds)" "$*"
      apt-get -y --no-remove --no-install-recommends \
        -o Dpkg::Options::=--force-confold install --only-upgrade "$@"
    } >>"$LOG_FILE" 2>&1 || {
      tail -n 30 "$LOG_FILE" >&2
      die 'Paket güncellemesi başarısız. Ayrıntı: /var/log/astera-system-update.log'
    }
    printf 'OK\tGüncelleme tamamlandı: %s\n' "$*"
    ;;
  *)
    die 'Kullanım: astera-system-update list|refresh|apply <paket...>'
    ;;
esac 9>"$LOCK_FILE"
