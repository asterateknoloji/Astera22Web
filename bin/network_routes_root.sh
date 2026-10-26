#!/usr/bin/env bash
set -Eeuo pipefail

action="${1:-apply}"
config="/etc/astera/network-routes.conf"
state="/run/astera-network-routes.applied"

[[ $EUID -eq 0 ]] || { echo "Routing işlemi root yetkisi gerektirir" >&2; exit 1; }

clear_routes() {
    [[ -f "$state" ]] || return 0
    while IFS='|' read -r destination gateway interface metric mode; do
        [[ -n "$destination" && -n "$gateway" && -n "$interface" && -n "$metric" ]] || continue
        if [[ "$mode" == "direct" ]]; then
            ip -4 route del "$destination" dev "$interface" \
                metric "$metric" proto static 2>/dev/null || true
        else
            ip -4 route del "$destination" via "$gateway" dev "$interface" \
                metric "$metric" proto static 2>/dev/null || true
        fi
    done <"$state"
    rm -f "$state"
}

apply_routes() {
    clear_routes
    [[ -f "$config" ]] || return 0
    : >"$state"
    chmod 0600 "$state"
    while IFS='|' read -r destination gateway interface metric; do
        [[ -n "$destination" && -n "$gateway" && -n "$interface" && -n "$metric" ]] || continue
        [[ "$interface" =~ ^[A-Za-z0-9][A-Za-z0-9_.:-]{0,30}$ ]] \
            || { echo "Geçersiz arayüz: $interface" >&2; exit 1; }
        [[ "$metric" =~ ^[0-9]+$ ]] && ((metric <= 65535)) \
            || { echo "Geçersiz metric: $metric" >&2; exit 1; }
        if ip -4 -o address show dev "$interface" \
            | awk '{print $4}' | cut -d/ -f1 | grep -Fxq "$gateway"; then
            ip -4 route replace "$destination" dev "$interface" src "$gateway" \
                metric "$metric" proto static
            mode="direct"
        else
            ip -4 route replace "$destination" via "$gateway" dev "$interface" \
                metric "$metric" proto static
            mode="via"
        fi
        printf '%s|%s|%s|%s|%s\n' \
            "$destination" "$gateway" "$interface" "$metric" "$mode" >>"$state"
    done <"$config"
}

case "$action" in
    apply|reload) apply_routes ;;
    clear|stop) clear_routes ;;
    *) echo "Kullanım: $0 apply|reload|clear" >&2; exit 2 ;;
esac
