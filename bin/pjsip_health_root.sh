#!/usr/bin/env bash
set -Eeuo pipefail

config="/etc/asterisk/pjsip_trunks.conf"
state_dir="/var/lib/astera-pjsip-health"
status_file="$state_dir/status"
queue_count_file="$state_dir/queue-failures"
lock_file="/run/lock/astera-pjsip-health.lock"
queue_limit=262144
registration_refresh_interval=600

install -d -m 0755 "$state_dir" /run/lock
exec 9>"$lock_file"
flock -n 9 || exit 0

log() {
    logger -t astera-pjsip-health -- "$*"
}

write_status() {
    local level="$1"
    shift
    printf '%s|%s|%s\n' "$level" "$(date -Is)" "$*" >"$status_file.tmp"
    chmod 0644 "$status_file.tmp"
    mv -f "$status_file.tmp" "$status_file"
}

cli() {
    timeout 12 asterisk -rx "$1" 2>/dev/null
}

active_calls() {
    cli "core show channels count" \
        | awk '/active call/ {print $1; found=1; exit} END {if (!found) print 0}'
}

registration_ids() {
    [[ -r "$config" ]] || return 0
    awk '
        /^\[[^]]+\]$/ {
            if (section != "" && type == "registration") print section
            section = substr($0, 2, length($0) - 2)
            type = ""
            next
        }
        /^type=registration[[:space:]]*$/ { type = "registration" }
        END {
            if (section != "" && type == "registration") print section
        }
    ' "$config" | sort -u
}

identify_matches() {
    local wanted="$1"
    awk -v wanted="$wanted" '
        function flush() {
            if (section == wanted && type == "identify") {
                for (i = 1; i <= count; i++) print matches[i]
            }
            delete matches
            count = 0
        }
        /^\[[^]]+\]$/ {
            flush()
            section = substr($0, 2, length($0) - 2)
            type = ""
            next
        }
        /^type=identify[[:space:]]*$/ { type = "identify"; next }
        /^match=/ { matches[++count] = substr($0, 7) }
        END { flush() }
    ' "$config"
}

restart_if_safe() {
    local reason="$1"
    local calls
    calls="$(active_calls || echo 0)"
    if [[ "$calls" =~ ^[0-9]+$ ]] && ((calls == 0)); then
        log "Asterisk güvenli biçimde yeniden başlatılıyor: $reason"
        systemctl restart asterisk.service
        for _ in {1..20}; do
            systemctl is-active --quiet asterisk.service \
                && cli "core show uptime" >/dev/null \
                && return 0
            sleep 1
        done
        return 1
    fi
    write_status "FAIL" "$reason; $calls aktif çağrı nedeniyle yeniden başlatma ertelendi"
    log "$reason; $calls aktif çağrı nedeniyle yeniden başlatma ertelendi"
    return 1
}

if ! systemctl is-active --quiet asterisk.service || ! cli "core show uptime" >/dev/null; then
    restart_if_safe "Asterisk servisi veya CLI yanıt vermiyor" || exit 1
fi

recv_queue="$(
    ss -H -lunp 2>/dev/null \
        | awk '$1 == "UNCONN" && $4 ~ /:5060$/ {print $2; exit}'
)"
recv_queue="${recv_queue:-0}"
if [[ "$recv_queue" =~ ^[0-9]+$ ]] && ((recv_queue > queue_limit)); then
    queue_failures="$(cat "$queue_count_file" 2>/dev/null || echo 0)"
    [[ "$queue_failures" =~ ^[0-9]+$ ]] || queue_failures=0
    queue_failures=$((queue_failures + 1))
    printf '%s\n' "$queue_failures" >"$queue_count_file"
    if ((queue_failures >= 2)); then
        restart_if_safe "SIP UDP 5060 alım kuyruğu dolu: $recv_queue bayt" || exit 1
        printf '0\n' >"$queue_count_file"
    else
        write_status "WARN" "SIP UDP 5060 alım kuyruğu yüksek: $recv_queue bayt"
        exit 0
    fi
else
    printf '0\n' >"$queue_count_file"
fi

mapfile -t ids < <(registration_ids)
if ((${#ids[@]} == 0)); then
    write_status "OK" "Etkin register trunk bulunmuyor"
    exit 0
fi

problems=()
for id in "${ids[@]}"; do
    [[ "$id" =~ ^[A-Za-z0-9_-]+$ ]] || continue
    registration="$(cli "pjsip show registration $id" || true)"
    endpoint="$(cli "pjsip show endpoint $id" || true)"
    identify="$(cli "pjsip show identify $id" || true)"

    registered=0
    endpoint_ok=0
    identify_ok=0
    needs_refresh=0
    refresh_requested=0
    grep -Eq "^[[:space:]]*$id/[^[:space:]]+[[:space:]]+[^[:space:]]+[[:space:]]+Registered\\b" \
        <<<"$registration" && registered=1
    grep -Eq "^[[:space:]]*Endpoint:[[:space:]]+$id\\b" <<<"$endpoint" && endpoint_ok=1
    grep -Eq "^[[:space:]]*Identify:[[:space:]]+$id/" <<<"$identify" && identify_ok=1
    while IFS= read -r expected_match; do
        [[ -n "$expected_match" ]] || continue
        [[ "$expected_match" =~ ^[0-9A-Fa-f:.]+(/[0-9]+)?$ ]] || continue
        if ! grep -Fq "Match: $expected_match" <<<"$identify"; then
            identify_ok=0
        fi
    done < <(identify_matches "$id")

    refresh_file="$state_dir/refresh-$id"
    refresh_time="$(stat -c %Y "$refresh_file" 2>/dev/null || echo 0)"
    now="$(date +%s)"
    if ((registered == 1 && now - refresh_time >= registration_refresh_interval)) \
        && ! cli "core show channels concise" | grep -Fq "PJSIP/$id-"; then
        if cli "pjsip send register $id" >/dev/null; then
            registered=0
            refresh_requested=1
        fi
    fi

    if ((endpoint_ok == 0 || identify_ok == 0)); then
        log "$id endpoint/identify nesnesi eksik veya güncel değil; PJSIP yeniden yükleniyor"
        cli "pjsip reload" >/dev/null || true
        sleep 2
        needs_refresh=1
    fi

    if ((registered == 0 || needs_refresh == 1)); then
        if ! cli "core show channels concise" | grep -Fq "PJSIP/$id-"; then
            cli "pjsip send register $id" >/dev/null || true
            registered=0
            for _ in {1..12}; do
                sleep 1
                registration="$(cli "pjsip show registration $id" || true)"
                if grep -Eq "^[[:space:]]*$id/[^[:space:]]+[[:space:]]+[^[:space:]]+[[:space:]]+Registered\\b" \
                    <<<"$registration"; then
                    registered=1
                    break
                fi
            done
        fi
    fi
    if ((refresh_requested == 1 && registered == 1)); then
        touch "$refresh_file"
        log "$id REGISTER kaydı koruyucu olarak tazelendi"
    fi

    endpoint="$(cli "pjsip show endpoint $id" || true)"
    identify="$(cli "pjsip show identify $id" || true)"
    if grep -Eq "^[[:space:]]*Endpoint:[[:space:]]+$id\\b" <<<"$endpoint"; then
        endpoint_ok=1
    else
        endpoint_ok=0
    fi
    if grep -Eq "^[[:space:]]*Identify:[[:space:]]+$id/" <<<"$identify"; then
        identify_ok=1
    else
        identify_ok=0
    fi
    ((registered == 1)) || problems+=("$id kayıtlı değil")
    ((endpoint_ok == 1)) || problems+=("$id endpoint eksik")
    ((identify_ok == 1)) || problems+=("$id identify eksik")
done

if ((${#problems[@]} > 0)); then
    message="$(IFS='; '; echo "${problems[*]}")"
    write_status "FAIL" "$message"
    log "$message"
    exit 1
fi

write_status "OK" "${#ids[@]} register trunk sağlıklı; UDP kuyruk=$recv_queue"
