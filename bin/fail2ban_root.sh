#!/usr/bin/env bash
set -Eeuo pipefail

export PATH=/usr/sbin:/usr/bin:/sbin:/bin
export LC_ALL=C

JAIL=asterisk
CONFIG=/etc/fail2ban/jail.d/astera-asterisk.local
MAIL_CONFIG=/etc/astera/fail2ban-mail.conf
MAIL_ACTION=/etc/fail2ban/action.d/astera-mail.conf
SSH_PREAUTH_FILTER=/etc/fail2ban/filter.d/sshd-preauth.conf
SSH_PREAUTH_JAIL=/etc/fail2ban/jail.d/sshd-preauth.local
ACTION="${1:-status}"
shift || true

die() {
    printf 'ERROR\t%s\n' "$*" >&2
    exit 1
}

setting() {
    local key="$1" fallback="$2" value=""
    if [[ -r "$CONFIG" ]]; then
        value="$(awk -F= -v key="$key" '
            $1 ~ "^[[:space:]]*" key "[[:space:]]*$" {
                sub(/^[[:space:]]*/, "", $2)
                sub(/[[:space:]]*$/, "", $2)
                print $2
                exit
            }
        ' "$CONFIG")"
    fi
    printf '%s' "${value:-$fallback}"
}

status() {
    local service=inactive jail=inactive current=0 total=0 line ip jail_name jail_total
    declare -A banned=()
    systemctl is-active --quiet fail2ban.service && service=active
    if [[ "$service" == active ]]; then
        for jail_name in "$JAIL" sshd sshd-preauth; do
            fail2ban-client status "$jail_name" >/dev/null 2>&1 || continue
            [[ "$jail_name" == "$JAIL" ]] && jail=active
            line="$(fail2ban-client status "$jail_name")"
            jail_total="$(awk -F: '/Total banned:/ {gsub(/[[:space:]]/, "", $2); print $2}' <<<"$line")"
            total=$((total + ${jail_total:-0}))
            while IFS= read -r ip; do
                [[ -n "$ip" ]] && banned["$ip"]=1
            done < <(awk -F: '/Banned IP list:/ {
                sub(/^[^:]*:[[:space:]]*/, "")
                gsub(/[[:space:]]+/, "\n")
                print
            }' <<<"$line")
        done
        current="${#banned[@]}"
        while IFS= read -r ip; do
            [[ -n "$ip" ]] && printf 'banned_ip\t%s\n' "$ip"
        done < <(printf '%s\n' "${!banned[@]}" | sort)
    fi
    printf 'installed\t%s\n' "$(dpkg-query -W -f='${db:Status-Abbrev}' fail2ban 2>/dev/null | grep -q '^ii' && printf yes || printf no)"
    printf 'service\t%s\n' "$service"
    printf 'jail\t%s\n' "$jail"
    printf 'enabled\t%s\n' "$(setting enabled false)"
    printf 'bantime\t%s\n' "$(setting bantime 86400)"
    printf 'findtime\t%s\n' "$(setting findtime 600)"
    printf 'maxretry\t%s\n' "$(setting maxretry 5)"
    printf 'ignoreip\t%s\n' "$(setting ignoreip '127.0.0.0/8 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16')"
    if [[ -r "$MAIL_CONFIG" ]]; then
        # shellcheck disable=SC1090
        source "$MAIL_CONFIG"
    fi
    printf 'mail_enabled\t%s\n' "${MAIL_ENABLED:-no}"
    printf 'mail_recipient\t%s\n' "${MAIL_RECIPIENT:-teknik@asterateknoloji.com.tr}"
    printf 'currently_banned\t%s\n' "${current:-0}"
    printf 'total_banned\t%s\n' "${total:-0}"
}

configure() {
    (($# == 7)) || die 'Kullanım: configure yes|no bantime findtime maxretry ignoreip mail_yes|no eposta'
    local enabled="$1" bantime="$2" findtime="$3" maxretry="$4" ignore_input="$5"
    local mail_enabled="$6" mail_recipient="$7"
    [[ "$enabled" == yes || "$enabled" == no ]] || die 'Etkin değeri yes veya no olmalıdır.'
    [[ "$mail_enabled" == yes || "$mail_enabled" == no ]] \
        || die 'E-posta bildirimi yes veya no olmalıdır.'
    mail_recipient="$(
        python3 - "$mail_recipient" <<'PY'
import re
import sys

addresses = list(dict.fromkeys(filter(None, re.split(r"[\s,;]+", sys.argv[1].strip()))))
pattern = re.compile(r"^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}$")
if not 1 <= len(addresses) <= 10:
    raise SystemExit("1-10 bildirim e-posta adresi girin.")
for address in addresses:
    if len(address) > 254 or not pattern.fullmatch(address):
        raise SystemExit("Geçersiz bildirim e-posta adresi: " + address)
print(",".join(addresses))
PY
    )" || die 'Bildirim e-posta adresleri doğrulanamadı.'
    [[ "$bantime" =~ ^[0-9]+$ ]] && ((bantime >= 60 && bantime <= 31536000)) \
        || die 'Engelleme süresi 60-31536000 saniye olmalıdır.'
    [[ "$findtime" =~ ^[0-9]+$ ]] && ((findtime >= 10 && findtime <= 86400)) \
        || die 'Takip süresi 10-86400 saniye olmalıdır.'
    [[ "$maxretry" =~ ^[0-9]+$ ]] && ((maxretry >= 1 && maxretry <= 100)) \
        || die 'Deneme sayısı 1-100 olmalıdır.'

    local ignoreip
    ignoreip="$(python3 - "$ignore_input" <<'PY'
import ipaddress
import sys

tokens = sys.argv[1].replace(",", " ").split()
networks = []
fixed = {
    "127.0.0.0/8", "::1/128", "10.0.0.0/8",
    "172.16.0.0/12", "192.168.0.0/16",
}
for token in tokens:
    try:
        network = str(ipaddress.ip_network(token, strict=False))
        if network not in fixed:
            networks.append(network)
    except ValueError:
        raise SystemExit("Geçersiz izinli IP/ağ: " + token)
print(" ".join(dict.fromkeys(networks)))
PY
)" || die 'İzinli IP/ağ listesi doğrulanamadı.'

    install -d -m 0755 /etc/fail2ban/jail.d /etc/fail2ban/filter.d /etc/fail2ban/action.d
    install -d -m 0750 -o root -g astera-panel /etc/astera
    cat >"$MAIL_ACTION" <<'EOF'
[Definition]
actionban = /usr/local/sbin/astera-fail2ban-mail '<ip>' '<name>' '<failures>'
EOF
    chmod 0644 "$MAIL_ACTION"
    {
        printf 'MAIL_ENABLED=%q\n' "$mail_enabled"
        printf 'MAIL_RECIPIENT=%q\n' "$mail_recipient"
    } >"$MAIL_CONFIG"
    chown root:astera-panel "$MAIL_CONFIG"
    chmod 0640 "$MAIL_CONFIG"

    local preauth_filter_backup="" preauth_jail_backup=""
    if [[ -f "$SSH_PREAUTH_FILTER" ]]; then
        preauth_filter_backup="$(mktemp /var/tmp/astera-sshd-preauth-filter.XXXXXX)"
        cp -a "$SSH_PREAUTH_FILTER" "$preauth_filter_backup"
    fi
    if [[ -f "$SSH_PREAUTH_JAIL" ]]; then
        preauth_jail_backup="$(mktemp /var/tmp/astera-sshd-preauth-jail.XXXXXX)"
        cp -a "$SSH_PREAUTH_JAIL" "$preauth_jail_backup"
    fi
    cat >"$SSH_PREAUTH_FILTER" <<'EOF'
[Definition]

failregex = ^.*sshd(?:-session)?\[\d+\]: (?:Connection closed|Connection reset) by <HOST> port \d+ \[preauth\]$

ignoreregex =
EOF
    chmod 0644 "$SSH_PREAUTH_FILTER"
    cat >"$SSH_PREAUTH_JAIL" <<EOF
[sshd-preauth]
enabled = $enabled
filter = sshd-preauth
backend = systemd
journalmatch = _SYSTEMD_UNIT=ssh.service
port = 45555
protocol = tcp
usedns = no
maxretry = 3
findtime = 600
bantime = $bantime
ignoreip = 127.0.0.0/8 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 $ignoreip
action = %(action_)s
EOF
    if [[ "$mail_enabled" == yes ]]; then
        printf '         astera-mail\n' >>"$SSH_PREAUTH_JAIL"
    fi
    chmod 0644 "$SSH_PREAUTH_JAIL"

    local temp backup=""
    temp="$(mktemp /etc/fail2ban/jail.d/.astera-asterisk.XXXXXX)"
    if [[ -f "$CONFIG" ]]; then
        backup="$(mktemp /var/tmp/astera-asterisk-jail.XXXXXX)"
        cp -a "$CONFIG" "$backup"
    fi
    cat >"$temp" <<EOF
[$JAIL]
enabled = $enabled
backend = auto
logpath = /var/log/asterisk/security.log
filter = asterisk
port = 5038,5060,5061
protocol = all
usedns = no
maxretry = $maxretry
findtime = $findtime
bantime = $bantime
ignoreip = 127.0.0.0/8 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 $ignoreip
EOF
    if [[ "$mail_enabled" == yes ]]; then
        cat >>"$temp" <<'EOF'
action = %(action_)s
         astera-mail
EOF
    fi
    chmod 0644 "$temp"
    mv "$temp" "$CONFIG"
    if ! fail2ban-client -t >/var/tmp/astera-fail2ban-test.log 2>&1; then
        if [[ -n "$backup" ]]; then
            mv "$backup" "$CONFIG"
        else
            rm -f "$CONFIG"
        fi
        if [[ -n "$preauth_filter_backup" ]]; then
            mv "$preauth_filter_backup" "$SSH_PREAUTH_FILTER"
        else
            rm -f "$SSH_PREAUTH_FILTER"
        fi
        if [[ -n "$preauth_jail_backup" ]]; then
            mv "$preauth_jail_backup" "$SSH_PREAUTH_JAIL"
        else
            rm -f "$SSH_PREAUTH_JAIL"
        fi
        cat /var/tmp/astera-fail2ban-test.log >&2
        die 'Fail2ban yapılandırması geçersiz.'
    fi
    [[ -z "$backup" ]] || rm -f "$backup"
    [[ -z "$preauth_filter_backup" ]] || rm -f "$preauth_filter_backup"
    [[ -z "$preauth_jail_backup" ]] || rm -f "$preauth_jail_backup"
    systemctl enable fail2ban.service >/dev/null
    systemctl restart fail2ban.service
    systemctl is-active --quiet fail2ban.service || die 'Fail2ban servisi başlatılamadı.'
    if [[ "$enabled" == yes ]]; then
        local attempt
        for attempt in {1..30}; do
            fail2ban-client status "$JAIL" >/dev/null 2>&1 && break
            sleep 0.1
        done
        fail2ban-client status "$JAIL" >/dev/null 2>&1 \
            || die 'Asterisk jail başlatılamadı.'
    fi
    printf 'OK\tFail2ban Asterisk koruması güncellendi.\n'
}

ip_action() {
    (($# == 1)) || die 'Tek bir IPv4 veya IPv6 adresi zorunludur.'
    local ip="$1"
    python3 - "$ip" <<'PY' >/dev/null
import ipaddress
import sys
ipaddress.ip_address(sys.argv[1])
PY
    if [[ "$ACTION" == unbanip ]]; then
        local jail_name found=0
        for jail_name in "$JAIL" sshd sshd-preauth; do
            fail2ban-client status "$jail_name" >/dev/null 2>&1 || continue
            fail2ban-client set "$jail_name" unbanip "$ip" >/dev/null 2>&1 || true
            found=1
        done
        ((found == 1)) || die 'Etkin Fail2ban jail bulunamadı.'
        printf 'OK\tIP adresinin tüm engelleri kaldırıldı.\n'
        return
    fi
    fail2ban-client status "$JAIL" >/dev/null 2>&1 || die 'Asterisk jail etkin değil.'
    fail2ban-client set "$JAIL" "$ACTION" "$ip"
}

allow_ip() {
    (($# == 1)) || die 'Tek bir IPv4 veya IPv6 adresi zorunludur.'
    local ip="$1" canonical temp backup
    canonical="$(python3 - "$ip" <<'PY'
import ipaddress
import sys
print(ipaddress.ip_address(sys.argv[1]))
PY
)" || die 'Geçerli bir IP adresi girin.'
    [[ -f "$CONFIG" ]] || die 'Asterisk jail yapılandırması bulunamadı.'

    temp="$(mktemp /etc/fail2ban/jail.d/.astera-asterisk.XXXXXX)"
    backup="$(mktemp /var/tmp/astera-asterisk-jail.XXXXXX)"
    cp -a "$CONFIG" "$backup"
    python3 - "$CONFIG" "$temp" "$canonical" <<'PY'
import ipaddress
import re
import sys

source, target, address = sys.argv[1:]
lines = open(source, encoding="utf-8").read().splitlines()
found = False
for index, line in enumerate(lines):
    if not re.match(r"^\s*ignoreip\s*=", line, re.I):
        continue
    values = line.split("=", 1)[1].split()
    normalized = []
    for value in values:
        try:
            normalized.append(str(ipaddress.ip_network(value, strict=False)))
        except ValueError:
            normalized.append(value)
    network = str(ipaddress.ip_network(address, strict=False))
    if network not in normalized:
        values.append(network)
    lines[index] = "ignoreip = " + " ".join(dict.fromkeys(values))
    found = True
    break
if not found:
    raise SystemExit("ignoreip ayarı bulunamadı.")
with open(target, "w", encoding="utf-8") as handle:
    handle.write("\n".join(lines) + "\n")
PY
    chmod 0644 "$temp"
    mv "$temp" "$CONFIG"
    if ! fail2ban-client -t >/var/tmp/astera-fail2ban-test.log 2>&1; then
        mv "$backup" "$CONFIG"
        cat /var/tmp/astera-fail2ban-test.log >&2
        die 'Fail2ban izin listesi geçersiz.'
    fi
    rm -f "$backup"

    fail2ban-client status "$JAIL" >/dev/null 2>&1 || die 'Asterisk jail etkin değil.'
    fail2ban-client set "$JAIL" addignoreip "$canonical" >/dev/null
    fail2ban-client set "$JAIL" unbanip "$canonical" >/dev/null
    printf 'OK\tIP adresi kalıcı izin listesine eklendi ve engeli kaldırıldı.\n'
}

case "$ACTION" in
    status) status ;;
    configure) configure "$@" ;;
    banip|unbanip) ip_action "$@" ;;
    allowip) allow_ip "$@" ;;
    *) die 'Kullanım: status|configure|banip|unbanip|allowip' ;;
esac
