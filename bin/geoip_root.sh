#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

CONFIG=/etc/astera/geoip.conf
CACHE_DIR=/var/lib/astera/geoip
LOG_FILE=/var/log/astera-geoip.log
LOCK_FILE=/run/lock/astera-geoip.lock
SERVICE=/etc/systemd/system/astera-geoip.service
TIMER=/etc/systemd/system/astera-geoip.timer

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S %z')" "$*" | tee -a "$LOG_FILE"
}

valid_csv() {
    [[ "${1:-}" =~ ^([a-z]{2})(,[a-z]{2})*$|^$ ]]
}

load_config() {
    POLICY_MODE=passthrough
    ALLOW_IPV4=tr
    BLOCK_IPV4=
    ALLOW_IPV6=tr
    BLOCK_IPV6=
    TRUSTED_NETWORKS=
    [[ -f "$CONFIG" ]] && source "$CONFIG"
    [[ "$POLICY_MODE" == "allow_only" || "$POLICY_MODE" == "passthrough" ]] \
        || { log "Geçersiz GeoIP politika modu"; return 1; }
    for value in "$ALLOW_IPV4" "$BLOCK_IPV4" "$ALLOW_IPV6" "$BLOCK_IPV6"; do
        valid_csv "$value" || { log "Geçersiz ülke listesi"; return 1; }
    done
    if [[ "$POLICY_MODE" == "allow_only" && ( -z "$ALLOW_IPV4" || -z "$ALLOW_IPV6" ) ]]; then
        log "Allow-only modunda IPv4 ve IPv6 için en az bir izinli ülke gerekir"
        return 1
    fi
    [[ "$TRUSTED_NETWORKS" =~ ^[0-9a-fA-F:.,/[:space:]-]*$ ]] \
        || { log "Geçersiz güvenilir ağ listesi"; return 1; }
}

backup_security() {
    local backup="/var/backups/astera-geoip/$(date '+%Y%m%d-%H%M%S')"
    install -d -m 0700 "$backup"
    cp -a /etc/ufw "$backup/ufw" 2>/dev/null || true
    cp -a /etc/ssh "$backup/ssh" 2>/dev/null || true
    iptables-save >"$backup/iptables.rules" 2>/dev/null || true
    ip6tables-save >"$backup/ip6tables.rules" 2>/dev/null || true
    ipset save >"$backup/ipset.rules" 2>/dev/null || true
    log "UFW, SSH, iptables ve ipset yedeği: $backup"
}

write_units() {
    cat >"$SERVICE" <<'EOF'
[Unit]
Description=Astera GeoIP SIP and SSH country filter
Wants=network-online.target
After=network-online.target ufw.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/astera-geoip update

[Install]
WantedBy=multi-user.target
EOF
    cat >"$TIMER" <<'EOF'
[Unit]
Description=Daily Astera GeoIP list update

[Timer]
OnCalendar=*-*-* 04:17:00
RandomizedDelaySec=45m
Persistent=true
Unit=astera-geoip.service

[Install]
WantedBy=timers.target
EOF
    systemctl daemon-reload
    systemctl enable astera-geoip.service astera-geoip.timer >/dev/null
    systemctl start astera-geoip.timer
}

install_dependencies() {
    local missing=()
    command -v ipset >/dev/null 2>&1 || missing+=(ipset)
    command -v curl >/dev/null 2>&1 || missing+=(curl)
    command -v python3 >/dev/null 2>&1 || missing+=(python3)
    if ((${#missing[@]})); then
        apt-get update
        DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing[@]}" ca-certificates
    fi
}

ensure_layout() {
    install -d -m 0750 /etc/astera "$CACHE_DIR"
    touch "$LOG_FILE"
    chmod 0640 "$LOG_FILE"
    if [[ ! -f "$CONFIG" ]]; then
        cat >"$CONFIG" <<'EOF'
POLICY_MODE="passthrough"
ALLOW_IPV4="tr"
BLOCK_IPV4=""
ALLOW_IPV6="tr"
BLOCK_IPV6=""
TRUSTED_NETWORKS=""
EOF
        chmod 0600 "$CONFIG"
    fi
}

validate_zone() {
    local family="$1" input="$2" output="$3"
    python3 - "$family" "$input" >"$output" <<'PY'
import ipaddress
import pathlib
import sys

version = int(sys.argv[1])
source = pathlib.Path(sys.argv[2])
seen = set()
for raw in source.read_text(encoding="utf-8").splitlines():
    value = raw.strip()
    if not value or value.startswith("#"):
        continue
    try:
        network = ipaddress.ip_network(value, strict=False)
    except ValueError as exc:
        raise SystemExit(f"gecersiz CIDR: {value}: {exc}")
    if network.version != version:
        raise SystemExit(f"yanlis IP ailesi: {value}")
    normalized = str(network)
    if normalized not in seen:
        print(normalized)
        seen.add(normalized)
if not seen:
    raise SystemExit("bos GeoIP listesi")
PY
}

fetch_country() {
    local family="$1" country="$2" destination="$3" url raw
    raw="${destination}.download"
    if [[ "$family" == 4 ]]; then
        url="https://www.ipdeny.com/ipblocks/data/countries/${country}.zone"
    else
        url="https://www.ipdeny.com/ipv6/ipaddresses/blocks/${country}.zone"
    fi
    curl --fail --silent --show-error --location \
        --connect-timeout 20 --max-time 120 --retry 2 \
        --proto '=https' --tlsv1.2 "$url" -o "$raw"
    validate_zone "$family" "$raw" "$destination"
    rm -f "$raw"
}

TEMP_FILES=()

cleanup_update() {
    local set_name file
    for set_name in astera_geo_allow4_new astera_geo_block4_new astera_geo_allow6_new astera_geo_block6_new; do
        ipset destroy "$set_name" 2>/dev/null || true
    done
    for file in "${TEMP_FILES[@]}"; do
        rm -f "$file"
    done
}

prepare_set() {
    local live="$1" family="$2" countries="$3"
    local temp="${live}_new" family_name=inet country zone restore count=0
    [[ "$family" == 6 ]] && family_name=inet6
    ipset create "$live" hash:net family "$family_name" hashsize 16384 maxelem 500000 -exist
    ipset create "$temp" hash:net family "$family_name" hashsize 16384 maxelem 500000 -exist
    ipset flush "$temp"
    restore="$(mktemp)"
    TEMP_FILES+=("$restore")
    if [[ -n "$countries" ]]; then
        IFS=',' read -ra country_list <<<"$countries"
        for country in "${country_list[@]}"; do
            zone="$CACHE_DIR/${country}-${family}.zone"
            fetch_country "$family" "$country" "$zone"
            while IFS= read -r network; do
                printf 'add %s %s\n' "$temp" "$network" >>"$restore"
                ((count += 1))
            done <"$zone"
        done
    fi
    [[ -s "$restore" ]] && ipset restore <"$restore"
    log "$live hazırlandı: $count CIDR"
}

commit_sets() {
    local live temp
    for live in astera_geo_allow4 astera_geo_block4 astera_geo_allow6 astera_geo_block6; do
        temp="${live}_new"
        ipset swap "$temp" "$live"
    done
    for live in astera_geo_allow4 astera_geo_block4 astera_geo_allow6 astera_geo_block6; do
        ipset destroy "${live}_new"
    done
    for temp in "${TEMP_FILES[@]}"; do
        rm -f "$temp"
    done
    TEMP_FILES=()
    log "Dört GeoIP seti doğrulandıktan sonra birlikte değiştirildi"
}

chain_exists() {
    "$1" -nL "$2" >/dev/null 2>&1
}

apply_family_firewall() {
    local command="$1" chain="$2" suffix="$3" trusted network ssh_port
    chain_exists "$command" "$chain" || "$command" -N "$chain"
    "$command" -F "$chain"
    if [[ "$suffix" == 4 ]]; then
        "$command" -A "$chain" -s 127.0.0.0/8 -j RETURN
    else
        "$command" -A "$chain" -s ::1/128 -j RETURN
    fi
    for network in ${TRUSTED_NETWORKS//,/ }; do
        [[ -n "$network" ]] || continue
        if [[ "$suffix" == 4 && "$network" != *:* ]] || [[ "$suffix" == 6 && "$network" == *:* ]]; then
            "$command" -A "$chain" -s "$network" -j RETURN
        fi
    done
    "$command" -A "$chain" -m set --match-set "astera_geo_block${suffix}" src \
        -m limit --limit 12/min --limit-burst 20 -j LOG \
        --log-prefix "ASTERA-GEO-BLOCK${suffix} " --log-level 6
    "$command" -A "$chain" -m set --match-set "astera_geo_block${suffix}" src -j DROP
    "$command" -A "$chain" -m set --match-set "astera_geo_allow${suffix}" src -j ACCEPT
    if [[ "$POLICY_MODE" == "allow_only" ]]; then
        "$command" -A "$chain" -m limit --limit 12/min --limit-burst 20 -j LOG \
            --log-prefix "ASTERA-GEO-DENY${suffix} " --log-level 6
        "$command" -A "$chain" -j DROP
    else
        "$command" -A "$chain" -j RETURN
    fi
    for protocol in udp tcp; do
        "$command" -C INPUT -p "$protocol" --dport 5060 -j "$chain" 2>/dev/null \
            || "$command" -I INPUT 1 -p "$protocol" --dport 5060 -j "$chain"
    done
    while IFS= read -r ssh_port; do
        [[ "$ssh_port" =~ ^[0-9]+$ ]] || continue
        ((ssh_port >= 1 && ssh_port <= 65535)) || continue
        "$command" -C INPUT -p tcp --dport "$ssh_port" -j "$chain" 2>/dev/null \
            || "$command" -I INPUT 1 -p tcp --dport "$ssh_port" -j "$chain"
    done < <(
        { printf '%s\n' 22 45555; sshd -T 2>/dev/null | awk '$1 == "port" {print $2}'; } \
            | sort -un
    )
}

apply_firewall() {
    load_config
    apply_family_firewall iptables ASTERA_GEO_SIP4 4
    if ip6tables -nL INPUT >/dev/null 2>&1; then
        apply_family_firewall ip6tables ASTERA_GEO_SIP6 6
    fi
    log "SIP 5060 ve SSH GeoIP zincirleri uygulandı; mevcut UFW kuralları korunuyor"
}

update_all() {
    exec 9>"$LOCK_FILE"
    flock -n 9 || { log "Başka bir GeoIP güncellemesi çalışıyor"; exit 0; }
    load_config
    log "GeoIP güncellemesi başladı"
    trap cleanup_update EXIT
    prepare_set astera_geo_allow4 4 "$ALLOW_IPV4"
    prepare_set astera_geo_block4 4 "$BLOCK_IPV4"
    prepare_set astera_geo_allow6 6 "$ALLOW_IPV6"
    prepare_set astera_geo_block6 6 "$BLOCK_IPV6"
    commit_sets
    trap - EXIT
    apply_firewall
    date +%s >"$CACHE_DIR/last-success"
    log "GeoIP güncellemesi tamamlandı"
}

set_count() {
    ipset list "$1" 2>/dev/null | awk '/^Number of entries:/ {print $4}'
}

status_json() {
    local updated=0 timer=false
    load_config
    [[ -f "$CACHE_DIR/last-success" ]] && read -r updated <"$CACHE_DIR/last-success"
    systemctl is-enabled --quiet astera-geoip.timer 2>/dev/null && timer=true
    printf '{"installed":true,"policy_mode":"%s","last_update":%s,"timer_enabled":%s,' \
        "$POLICY_MODE" "$updated" "$timer"
    printf '"allow_ipv4":%s,"block_ipv4":%s,"allow_ipv6":%s,"block_ipv6":%s}\n' \
        "$(set_count astera_geo_allow4 || printf 0)" \
        "$(set_count astera_geo_block4 || printf 0)" \
        "$(set_count astera_geo_allow6 || printf 0)" \
        "$(set_count astera_geo_block6 || printf 0)"
}

case "${1:-}" in
    install)
        install_dependencies
        ensure_layout
        backup_security
        write_units
        update_all
        log "GeoIP altyapısı güvenli geçiş modunda kuruldu; ülke politikası panelden etkinleştirilebilir"
        ;;
    update)
        ensure_layout
        update_all
        ;;
    firewall)
        ensure_layout
        apply_firewall
        ;;
    status)
        status_json
        ;;
    backup)
        ensure_layout
        backup_security
        ;;
    *)
        echo "Kullanım: $0 {install|update|firewall|status|backup}" >&2
        exit 2
        ;;
esac
