#!/usr/bin/env bash
set -Eeuo pipefail

ASTERISK_SERIES="${ASTERISK_SERIES:-22}"
BUILD_JOBS="${BUILD_JOBS:-2}"
ALLOW_NO_HARDWARE="${ALLOW_NO_HARDWARE:-0}"
DAHDI_LINUX_REF="${DAHDI_LINUX_REF:-276c914ea7d58bc2c19478f17835ed833e8f66d4}"
WORK_DIR=""

log() { printf '\n[%s] %s\n' "$(date '+%F %T')" "$*"; }
die() { printf '\nHATA: %s\n' "$*" >&2; exit 1; }
cleanup() {
    local code=$?
    [[ -z "$WORK_DIR" || ! -d "$WORK_DIR" ]] || rm -rf "$WORK_DIR"
    ((code == 0)) || printf '\nPRI kurulumu tamamlanamadı (kod %d).\n' "$code" >&2
}
trap cleanup EXIT
trap 'die "Satır $LINENO çalıştırılamadı: $BASH_COMMAND"' ERR

[[ $EUID -eq 0 ]] || die "Bu betik root olarak çalıştırılmalıdır."
[[ -r /etc/os-release ]] || die "Ubuntu sürümü algılanamadı."
# shellcheck disable=SC1091
source /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || die "Bu betik Ubuntu içindir."
[[ "$BUILD_JOBS" =~ ^[1-8]$ ]] || die "BUILD_JOBS 1 ile 8 arasında olmalıdır."
[[ "$ALLOW_NO_HARDWARE" =~ ^[01]$ ]] || die "ALLOW_NO_HARDWARE 0 veya 1 olmalıdır."

if mokutil --sb-state 2>/dev/null | grep -qi 'enabled'; then
    die "Secure Boot açık. İmzasız DAHDI modülü yüklenemez; önce Secure Boot kapatılmalıdır."
fi

WORK_DIR="$(mktemp -d /var/tmp/astera-pri.XXXXXX)"
export DEBIAN_FRONTEND=noninteractive

log "Derleme bağımlılıkları kuruluyor"
apt-get update
apt-get install -y --no-install-recommends \
    ca-certificates curl build-essential pkg-config bison flex patch \
    autoconf automake libtool linux-headers-"$(uname -r)" \
    libnewt-dev libusb-1.0-0-dev libpci-dev libncurses-dev \
    libedit-dev libjansson-dev libxml2-dev libsqlite3-dev uuid-dev \
    libssl-dev libcurl4-openssl-dev libspeex-dev libspeexdsp-dev \
    libogg-dev libvorbis-dev libgsm1-dev libopus-dev libsrtp2-dev \
    libspandsp-dev libical-dev libneon27-dev unixodbc-dev libpq-dev

log "DAHDI Linux ve DAHDI Tools kuruluyor"
if ! command -v dahdi_hardware >/dev/null 2>&1 \
    || { ! modinfo wcte11xp >/dev/null 2>&1 && ! modinfo wct4xxp >/dev/null 2>&1; }; then
    curl -fL --retry 3 \
        https://downloads.asterisk.org/pub/telephony/dahdi-linux-complete/dahdi-linux-complete-current.tar.gz \
        -o "$WORK_DIR/dahdi-linux-complete.tar.gz"
    mkdir "$WORK_DIR/dahdi"
    tar -xzf "$WORK_DIR/dahdi-linux-complete.tar.gz" --strip-components=1 -C "$WORK_DIR/dahdi"
    # The stable complete archive carries an old kernel module tree. Replace
    # only that tree with a pinned upstream revision supporting current kernels,
    # while retaining the stable DAHDI Tools release from the complete archive.
    curl -fL --retry 3 \
        "https://github.com/asterisk/dahdi-linux/archive/${DAHDI_LINUX_REF}.tar.gz" \
        -o "$WORK_DIR/dahdi-linux-current.tar.gz"
    rm -rf "$WORK_DIR/dahdi/linux"
    mkdir "$WORK_DIR/dahdi/linux"
    tar -xzf "$WORK_DIR/dahdi-linux-current.tar.gz" \
        --strip-components=1 -C "$WORK_DIR/dahdi/linux"
    # Linux 6.13+ no longer honors the out-of-tree EXTRA_CFLAGS variable.
    find "$WORK_DIR/dahdi/linux/drivers/dahdi" -type f \
        \( -name Makefile -o -name Kbuild \) \
        -exec sed -i 's/EXTRA_CFLAGS/ccflags-y/g' {} +
    # The proprietary prebuilt VPMADT032 object cannot pass objtool checks on
    # current kernels and is not used by TE110P/TE405P PRI cards.
    rm -f "$WORK_DIR/dahdi/linux/drivers/dahdi/vpmadt032_loader/"*.o_shipped
    sed -i '/CONFIG_DAHDI_VPMADT032_LOADER/d' \
        "$WORK_DIR/dahdi/linux/drivers/dahdi/Kbuild"
    make -C "$WORK_DIR/dahdi" -j"$BUILD_JOBS" all
    make -C "$WORK_DIR/dahdi" install
    make -C "$WORK_DIR/dahdi" config
    depmod -a
else
    log "DAHDI zaten kurulu; yeniden derleme atlandı"
fi

log "libpri kuruluyor"
curl -fL --retry 3 \
    https://downloads.asterisk.org/pub/telephony/libpri/libpri-1.6.1.tar.gz \
    -o "$WORK_DIR/libpri.tar.gz"
mkdir "$WORK_DIR/libpri"
tar -xzf "$WORK_DIR/libpri.tar.gz" --strip-components=1 -C "$WORK_DIR/libpri"
make -C "$WORK_DIR/libpri" -j"$BUILD_JOBS"
make -C "$WORK_DIR/libpri" install
ldconfig

log "Tiger Jet çakışan netjet sürücüsü devre dışı bırakılıyor"
cat >/etc/modprobe.d/astera-dahdi-blacklist.conf <<'EOF'
# Tiger Jet e159:0001/79fe:0001 is a TE110P-compatible E1/PRI card.
# The generic mISDN netjet driver claims it before DAHDI unless blacklisted.
blacklist netjet
EOF
modprobe -r netjet mISDNipac mISDN_core isdnhdlc 2>/dev/null || true
cat >/etc/modules-load.d/astera-dahdi.conf <<'EOF'
dahdi
wcte11xp
wct4xxp
EOF
getent group asterisk >/dev/null || groupadd --system asterisk
id asterisk >/dev/null 2>&1 || useradd --system --gid asterisk \
    --home-dir /var/lib/asterisk --shell /usr/sbin/nologin asterisk
cat >/etc/udev/rules.d/60-astera-dahdi.rules <<'EOF'
SUBSYSTEM=="dahdi", OWNER="asterisk", GROUP="asterisk", MODE="0660"
SUBSYSTEM=="dahdi_channels", OWNER="asterisk", GROUP="asterisk", MODE="0660"
EOF
udevadm control --reload-rules
update-initramfs -u
modprobe dahdi
modprobe wcte11xp 2>/dev/null || true
modprobe wct4xxp 2>/dev/null || true
udevadm trigger --subsystem-match=dahdi --subsystem-match=dahdi_channels || true
find /dev/dahdi -type c -exec chown asterisk:asterisk {} +
find /dev/dahdi -type c -exec chmod 0660 {} +

if ! dahdi_hardware 2>/dev/null | grep -Eq '(wcte11xp|wct4xxp)\+'; then
    lspci -nnk | grep -A4 -Ei 'e159:0001|telephony|isdn' || true
    dahdi_hardware || true
    if ((ALLOW_NO_HARDWARE == 0)); then
        die "PRI kartı wcte11xp veya wct4xxp sürücüsüne bağlanamadı."
    fi
    log "PRI kartı takılı değil; yazılım yığını hazır kurulum modunda devam ediyor"
fi

log "Asterisk ${ASTERISK_SERIES}, chan_dahdi ve libpri desteğiyle yeniden derleniyor"
curl -fL --retry 3 \
    "https://downloads.asterisk.org/pub/telephony/asterisk/asterisk-${ASTERISK_SERIES}-current.tar.gz" \
    -o "$WORK_DIR/asterisk.tar.gz"
mkdir "$WORK_DIR/asterisk"
tar -xzf "$WORK_DIR/asterisk.tar.gz" --strip-components=1 -C "$WORK_DIR/asterisk"
pushd "$WORK_DIR/asterisk" >/dev/null
./configure --with-jansson-bundled --with-dahdi --with-pri
make menuselect.makeopts
menuselect/menuselect \
    --enable chan_dahdi \
    --enable res_http_websocket \
    --enable chan_pjsip \
    --enable res_pjsip_transport_websocket \
    --enable res_odbc \
    --enable cdr_adaptive_odbc \
    menuselect.makeopts
make -j"$BUILD_JOBS"
systemctl stop asterisk
make install
ldconfig
systemctl start asterisk
popd >/dev/null

CHAN_DAHDI="$(
    find /usr/lib /usr/lib64 /usr/local/lib -type f \
        -path '*/asterisk/modules/chan_dahdi.so' -print -quit 2>/dev/null
)"
[[ -n "$CHAN_DAHDI" ]] || die "chan_dahdi.so kurulamadı."
for _attempt in {1..30}; do
    [[ -S /run/asterisk/asterisk.ctl ]] && break
    sleep 1
done
[[ -S /run/asterisk/asterisk.ctl ]] || die "Asterisk kontrol soketi oluşmadı."
asterisk -rx 'module load chan_dahdi.so' 2>/dev/null || true

mkdir -p /etc/systemd/system/asterisk.service.d
cat >/etc/systemd/system/asterisk.service.d/after-dahdi.conf <<'EOF'
[Unit]
After=dahdi.service
EOF
systemctl daemon-reload

log "PRI yazılım yığını kuruldu"
dahdi_hardware
asterisk -rx 'module show like chan_dahdi.so'
echo
if dahdi_hardware 2>/dev/null | grep -Eq '(wcte11xp|wct4xxp)\+'; then
    echo "Sonraki adım: panelde PRI / DAHDI span ayarını girip Santrale uygula."
else
    echo "PRI kartı takıldığında sürücüyü yüklemek için sunucuyu yeniden başlatın veya ilgili modülü modprobe ile yükleyin."
fi
