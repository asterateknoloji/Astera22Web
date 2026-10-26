#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Bu betigi root olarak calistirin: sudo bash fix_target_ssh.sh" >&2
    exit 1
fi

timestamp="$(date +%Y%m%d-%H%M%S)"
client_config=/etc/ssh/ssh_config

echo "[1/5] Bozuk SSH istemci ayarlari temizleniyor"
if [[ -f "$client_config" ]]; then
    cp -a "$client_config" "${client_config}.bak-${timestamp}"
    sed -i -E \
        -e '/^[[:space:]]*PermitRootLogin([[:space:]]|$)/Id' \
        -e '/^[[:space:]]*Pas?swordAuthentication([[:space:]]|$)/Id' \
        "$client_config"
fi

echo "[2/5] OpenSSH Server denetleniyor"
if ! dpkg-query -W -f='${db:Status-Abbrev}' openssh-server 2>/dev/null | grep -q '^ii'; then
    backup_dir="/etc/apt/sources.list.d/astera-ssh-fix-${timestamp}"
    install -d -m 0755 "$backup_dir"
    shopt -s nullglob
    for source_file in /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources; do
        [[ -f "$source_file" ]] || continue
        grep -Fq 'apt.postgresql.org' "$source_file" || continue
        mv "$source_file" "$backup_dir/$(basename "$source_file")"
    done
    shopt -u nullglob
    apt-get update
    apt-get install -y openssh-server
fi

echo "[3/5] SSH sunucu ayarlari yaziliyor"
install -d -m 0755 /run/sshd /etc/ssh/sshd_config.d
cat >/etc/ssh/sshd_config.d/99-astera-root.conf <<'SSHD_CONFIG'
PermitRootLogin yes
PasswordAuthentication yes
SSHD_CONFIG
chmod 0644 /etc/ssh/sshd_config.d/99-astera-root.conf

echo "[4/5] Yapilandirma dogrulaniyor"
/usr/sbin/sshd -t

echo "[5/5] SSH servisi etkinlestiriliyor"
systemctl daemon-reload
systemctl enable ssh
systemctl restart ssh
systemctl is-active --quiet ssh

echo
echo "SSH onarildi."
echo "Baglanti testi: ssh root@$(hostname -I | awk '{print $1}')"
passwd -S root || true
