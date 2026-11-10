#!/usr/bin/env bash
set -u

export PATH=/usr/sbin:/usr/bin:/sbin:/bin
export LC_ALL=C

CONFIG=/etc/astera/fail2ban-mail.conf
ip="${1:-}"
jail="${2:-asterisk}"
failures="${3:-bilinmiyor}"

[[ -r "$CONFIG" ]] || exit 0
# shellcheck disable=SC1090
source "$CONFIG"
[[ "${MAIL_ENABLED:-no}" == yes ]] || exit 0
recipient="${MAIL_RECIPIENT:-}"
IFS=',; ' read -r -a recipients <<<"$recipient"
(( ${#recipients[@]} >= 1 && ${#recipients[@]} <= 10 )) || exit 0
for address in "${recipients[@]}"; do
    [[ "$address" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}$ ]] || exit 0
done
[[ "$ip" =~ ^[0-9A-Fa-f:.]+$ ]] || exit 0
[[ "$jail" =~ ^[A-Za-z0-9_.-]+$ ]] || jail=asterisk
[[ "$failures" =~ ^[0-9]+$ ]] || failures=bilinmiyor

server="$(hostname)"
customer="Bilinmiyor"
if [[ -r /etc/astera/ssh-alert.conf ]]; then
    # shellcheck disable=SC1091
    source /etc/astera/ssh-alert.conf
    customer="${CUSTOMER_NAME:-$customer}"
fi
event_label="Asterisk"
if [[ "$jail" == sshd* ]]; then
    event_label="SSH"
    recent="$(
        {
            tail -n 10000 /var/log/auth.log 2>/dev/null | grep -F -- "$ip" || true
            journalctl -u ssh.service --since '-10 minutes' --no-pager 2>/dev/null \
                | grep -F -- "$ip" || true
        } | tail -n 20
    )"
else
    recent="$(
        {
            tail -n 10000 /var/log/asterisk/security.log 2>/dev/null | grep -F -- "$ip" || true
            journalctl -u asterisk.service --since '-10 minutes' --no-pager 2>/dev/null \
                | grep -F -- "$ip" || true
        } | tail -n 20
    )"
fi

{
    echo "Fail2ban bir IP adresini engelledi."
    echo
    echo "Musteri: $customer"
    echo "Sunucu: $server"
    echo "Tarih/Saat: $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "Jail: $jail"
    echo "Engellenen IP: $ip"
    echo "Tespit edilen hata: $failures"
    echo
    echo "Son $event_label olaylari:"
    if [[ -n "$recent" ]]; then
        printf '%s\n' "$recent"
    else
        echo "Bu IP icin son 10 dakikada ayrintili $event_label kaydi bulunamadi."
    fi
    echo
    echo "Yonetim: https://${server}/index.php?p=fail2ban"
} | timeout 20s /usr/bin/mail -r root@astera.com.tr \
    -s "Fail2ban: $server $jail IP engellendi - $ip" "${recipients[@]}"
mail_status=${PIPESTATUS[1]:-1}

if ((mail_status != 0)); then
    logger -t astera-fail2ban-mail "Bildirim gonderilemedi: jail=$jail ip=$ip recipient=$recipient status=$mail_status"
fi
exit 0
