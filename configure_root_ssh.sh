#!/usr/bin/env bash
# Run separately on the NEW Ubuntu PBX after install_new_pbx.sh.
# Enables root password login and verifies Astera's loopback management key.

if [ -z "${BASH_VERSION:-}" ]; then
    if command -v bash >/dev/null 2>&1; then
        exec bash "$0" "$@"
    fi
    echo "Bu betik Bash gerektirir." >&2
    exit 1
fi

set -Eeuo pipefail
umask 027

if [[ $EUID -ne 0 ]]; then
    echo "Root olarak calistirin: sudo bash configure_root_ssh.sh" >&2
    exit 1
fi

command -v sshd >/dev/null 2>&1 || {
    echo "openssh-server kurulu degil." >&2
    exit 1
}

BACKUP_DIR="/root/astera-ssh-backup-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
cp -a /etc/ssh/sshd_config "$BACKUP_DIR/sshd_config"
if [[ -d /etc/ssh/sshd_config.d ]]; then
    cp -a /etc/ssh/sshd_config.d "$BACKUP_DIR/sshd_config.d"
fi

completed=0
restore_on_error() {
    local code=$?
    if ((code != 0 && completed == 0)); then
        echo "SSH ayari basarisiz; onceki dosyalar geri yukleniyor." >&2
        cp -a "$BACKUP_DIR/sshd_config" /etc/ssh/sshd_config
        rm -rf /etc/ssh/sshd_config.d
        if [[ -d "$BACKUP_DIR/sshd_config.d" ]]; then
            cp -a "$BACKUP_DIR/sshd_config.d" /etc/ssh/sshd_config.d
        else
            mkdir -p /etc/ssh/sshd_config.d
        fi
        if sshd -t; then
            systemctl restart ssh.service 2>/dev/null \
                || systemctl restart sshd.service 2>/dev/null \
                || true
        fi
    fi
    exit "$code"
}
trap restore_on_error EXIT

echo "SSH yedegi: $BACKUP_DIR"
echo "Mevcut SSH oturumunu, yeni root baglantisini test edene kadar kapatmayin."

install -d -m 0755 /etc/ssh/sshd_config.d
: >/etc/ssh/sshd_config.d/90-astera-local-root.conf
chmod 0644 /etc/ssh/sshd_config.d/90-astera-local-root.conf

# Remove older ASTERA block if this script is run again.
clean_config="$(mktemp)"
awk '
    $0 == "# BEGIN ASTERA ROOT SSH" { skip = 1; next }
    $0 == "# END ASTERA ROOT SSH" { skip = 0; next }
    !skip { print }
' /etc/ssh/sshd_config >"$clean_config"
install -m 0644 "$clean_config" /etc/ssh/sshd_config
rm -f "$clean_config"

# OpenSSH uses the first value it reads. Comment conflicting settings in both
# the main file and cloud-init fragments, then put the wanted values first.
for ssh_config_file in /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf; do
    [[ -f "$ssh_config_file" ]] || continue
    sed -i -E \
        's/^([[:space:]]*)(PermitRootLogin|PasswordAuthentication)([[:space:]]+)/\1# ASTERA override: \2\3/I' \
        "$ssh_config_file"
done

root_config="$(mktemp)"
cat >"$root_config" <<'SSHD'
# BEGIN ASTERA ROOT SSH
PermitRootLogin yes
PasswordAuthentication yes
# END ASTERA ROOT SSH

SSHD
cat /etc/ssh/sshd_config >>"$root_config"
install -m 0644 "$root_config" /etc/ssh/sshd_config
rm -f "$root_config"

root_password_status="$(passwd -S root 2>/dev/null | awk '{print $2}')"
if [[ "$root_password_status" != "P" ]]; then
    if [[ ! -t 0 ]]; then
        echo "Root hesabi icin parola yok. Once 'sudo passwd root' calistirin." >&2
        exit 1
    fi
    echo
    echo "Yeni root SSH parolasini belirleyin:"
    passwd root
fi

sshd -t
effective_config="$(sshd -T)"
grep -Fxq 'permitrootlogin yes' <<<"$effective_config" || {
    echo "PermitRootLogin etkinlestirilemedi." >&2
    exit 1
}
grep -Fxq 'passwordauthentication yes' <<<"$effective_config" || {
    echo "PasswordAuthentication etkinlestirilemedi." >&2
    exit 1
}

if systemctl list-unit-files ssh.service >/dev/null 2>&1; then
    systemctl restart ssh.service
else
    systemctl restart sshd.service
fi

# Verify the panel's restricted loopback key when the main installer created it.
if [[ -f /etc/astera/id_ed25519 && -f /root/.ssh/authorized_keys ]]; then
    ssh-keyscan -H 127.0.0.1 localhost 2>/dev/null >/etc/astera/known_hosts
    chown astera-panel:astera-panel /etc/astera/known_hosts
    chmod 600 /etc/astera/known_hosts
    sudo -u astera-panel ssh \
        -o BatchMode=yes \
        -o ConnectTimeout=5 \
        -o UserKnownHostsFile=/etc/astera/known_hosts \
        -o StrictHostKeyChecking=yes \
        -i /etc/astera/id_ed25519 \
        root@127.0.0.1 true || {
            echo "Astera yerel root SSH anahtari dogrulanamadi." >&2
            exit 1
        }
fi

completed=1
trap - EXIT
echo
echo "Root SSH parola girisi etkin."
echo "Yeni bir terminalde test edin: ssh root@SUNUCU_IP"
echo "Test basarili olana kadar bu oturumu kapatmayin."
