#!/usr/bin/env bash
# ASTERA PBX migration installer
#
# Run on the NEW Ubuntu PBX only:
#   sudo bash install_new_pbx.sh
#   sudo bash install_new_pbx.sh --activate
#
# The source PBX is read-only during this process. The script only runs
# inspection commands, pg_dump/pg_dumpall and rsync reads on 192.168.181.74.

if [ -z "${BASH_VERSION:-}" ]; then
    if command -v bash >/dev/null 2>&1; then
        exec bash "$0" "$@"
    fi
    echo "Bu kurulum betigi Bash gerektirir; bash paketi bulunamadi." >&2
    exit 1
fi

set -Eeuo pipefail
umask 027

SOURCE_HOST="${SOURCE_HOST:-192.168.181.74}"
TARGET_HOST="${TARGET_HOST:-192.168.181.87}"
PROVISION_NETWORK="${PROVISION_NETWORK:-${TARGET_HOST%.*}.0/24}"
SOURCE_SSH_USER="${SOURCE_SSH_USER:-root}"
SOURCE_SSH_PORT="${SOURCE_SSH_PORT:-22}"
SOURCE_SSH_KEY="${SOURCE_SSH_KEY:-}"
SOURCE_APP_DIR="${SOURCE_APP_DIR:-/var/www/astera}"
TARGET_APP_DIR="${TARGET_APP_DIR:-/var/www/astera}"
DB_NAME="${DB_NAME:-asterisk}"
SOURCE_PANEL_DOMAIN="${SOURCE_PANEL_DOMAIN:-santral.astera.com.tr}"
PBX_NAME="${PBX_NAME:-}"
PANEL_DOMAIN="${PANEL_DOMAIN:-}"
if [[ -z "$PANEL_DOMAIN" && -n "$PBX_NAME" ]]; then
    PANEL_DOMAIN="${PBX_NAME,,}.asterapbx.net"
fi
ASTERISK_SERIES="${ASTERISK_SERIES:-22}"
ASTERISK_BUILD_JOBS="${ASTERISK_BUILD_JOBS:-2}"
POSTGRESQL_MAJOR="${POSTGRESQL_MAJOR:-18}"
ACTIVATE=0
FORCE=0
ASSUME_YES=0
LOG_FILE="/var/log/astera-pbx-install.log"
STATE_DIR="/var/lib/astera-installer"
WORK_DIR=""

usage() {
    cat <<'USAGE'
Kullanim:
  sudo bash install_new_pbx.sh [secenekler]

Secenekler:
  --activate   Kurulum sonunda Asterisk'i baslatir. Eski santral devredeyken
               kullanmak ayni trunk'in iki kez kayit olmasina neden olabilir.
  --force      Hedefte bulunan uygulama/veritabani verisini yedekleyip uzerine
               geri yuklemeye izin verir.
  --yes        Etkilesimli hedef IP onayini atlar.
  -h, --help   Bu yardimi gosterir.

Istege bagli ortam degiskenleri:
  SOURCE_SSH_KEY=/root/.ssh/id_ed25519
  SOURCE_SSH_PASSWORD='...'        (anahtar yoksa; komut satirina yazmayin)
  SOURCE_APP_DIR=/var/www/astera
  SOURCE_PANEL_DOMAIN=santral.astera.com.tr
  PBX_NAME=pbx002                   (pbx002.asterapbx.net oluşturur)
  PANEL_DOMAIN=pbx002.example.com   (PBX_NAME yerine kullanılabilir)
  LETSENCRYPT_EMAIL=admin@example.com
  CLOUDFLARE_API_TOKEN='...'        (komut geçmişine yazmak yerine export edin)
  SOURCE_HOST=192.168.181.74 TARGET_HOST=192.168.181.87

Onerilen guvenli ilk calisma:
  sudo PBX_NAME=pbx002 SOURCE_SSH_KEY=/root/.ssh/id_ed25519 \
    bash install_new_pbx.sh

Kesin gecis sirasinda:
  1. Eski santralde yeni cagri olusmasini durdurun.
  2. Betigi --force --activate ile yeniden calistirin. SSL, WebRTC ve root SSH
     ayarlari bu adimda otomatik tamamlanir.
  3. Operator/DNS/NAT yonunu yeni IP'ye cevirin.
USAGE
}

while (($#)); do
    case "$1" in
        --activate) ACTIVATE=1 ;;
        --force) FORCE=1 ;;
        --yes) ASSUME_YES=1 ;;
        -h|--help) usage; exit 0 ;;
        *) printf 'Bilinmeyen secenek: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

if [[ $EUID -ne 0 ]]; then
    echo "Bu betik yeni Ubuntu santralde root olarak calistirilmalidir." >&2
    exit 1
fi

if [[ ! -r /etc/os-release ]]; then
    echo "Desteklenen Ubuntu sistemi algilanamadi." >&2
    exit 1
fi
# shellcheck disable=SC1091
source /etc/os-release
if [[ "${ID:-}" != "ubuntu" ]]; then
    echo "Bu betik Ubuntu icin hazirlanmistir; algilanan: ${ID:-bilinmiyor}" >&2
    exit 1
fi

mkdir -p "$(dirname "$LOG_FILE")" "$STATE_DIR"
touch "$LOG_FILE"
chmod 600 "$LOG_FILE"
exec > >(tee -a "$LOG_FILE") 2>&1

log() { printf '\n[%s] %s\n' "$(date '+%F %T')" "$*"; }
die() { printf '\nHATA: %s\n' "$*" >&2; exit 1; }
cleanup() {
    local code=$?
    if [[ -n "$WORK_DIR" && -d "$WORK_DIR" ]]; then
        rm -rf "$WORK_DIR"
    fi
    if ((code != 0)); then
        printf '\nKurulum tamamlanamadi (kod %d). Gunluk: %s\n' "$code" "$LOG_FILE" >&2
    fi
}
trap cleanup EXIT
trap 'die "Satir $LINENO calistirilamadi: $BASH_COMMAND"' ERR

valid_ipv4() {
    local ip=$1 part
    [[ $ip =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
    IFS=. read -r -a parts <<<"$ip"
    for part in "${parts[@]}"; do
        ((10#$part <= 255)) || return 1
    done
}
valid_ipv4 "$SOURCE_HOST" || die "Gecersiz kaynak IP: $SOURCE_HOST"
valid_ipv4 "$TARGET_HOST" || die "Gecersiz hedef IP: $TARGET_HOST"
[[ "$SOURCE_HOST" != "$TARGET_HOST" ]] || die "Kaynak ve hedef IP ayni olamaz."
[[ "$DB_NAME" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]] || die "Gecersiz veritabani adi."
[[ "$SOURCE_PANEL_DOMAIN" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]] \
    || die "Geçersiz SOURCE_PANEL_DOMAIN."
[[ "$PANEL_DOMAIN" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]] \
    || die "PBX_NAME veya geçerli PANEL_DOMAIN zorunludur."
[[ "$SOURCE_APP_DIR" == /* && "$TARGET_APP_DIR" == /* ]] || die "Uygulama yollari mutlak olmalidir."
[[ "$ASTERISK_BUILD_JOBS" =~ ^[1-8]$ ]] \
    || die "ASTERISK_BUILD_JOBS 1 ile 8 arasinda olmalidir."
[[ "$POSTGRESQL_MAJOR" =~ ^(14|15|16|17|18)$ ]] \
    || die "POSTGRESQL_MAJOR 14-18 araliginda olmalidir."

if ! ip -4 -o addr show scope global | awk '{print $4}' | cut -d/ -f1 | grep -Fxq "$TARGET_HOST"; then
    die "Bu makinede hedef IP ($TARGET_HOST) bulunamadi. Betigi yalnizca yeni santralde calistirin."
fi

if ((ASSUME_YES == 0)); then
    echo
    echo "Kaynak (yalnizca okunacak): $SOURCE_SSH_USER@$SOURCE_HOST"
    echo "Hedef (kurulum yapilacak):   $TARGET_HOST"
    echo "Uygulama:                    $SOURCE_APP_DIR -> $TARGET_APP_DIR"
    echo "Veritabani:                  $DB_NAME"
    ((ACTIVATE == 1)) && echo "UYARI: --activate verildi; yeni Asterisk trunk kayitlarini baslatabilir."
    read -r -p "Devam etmek icin hedef IP'yi yazin ($TARGET_HOST): " confirmation
    [[ "$confirmation" == "$TARGET_HOST" ]] || die "Onay eslesmedi; hicbir degisiklik yapilmadi."
fi

export DEBIAN_FRONTEND=noninteractive
log "Temel paketler kuruluyor"

# A previous PostgreSQL repository entry may exist without its signing key.
# Disable only PGDG entries before the bootstrap apt update; they are recreated
# below with an explicit signed-by key.
PGDG_SOURCE_BACKUP_DIR="/etc/apt/sources.list.d/astera-pgdg-backup-$(date +%Y%m%d-%H%M%S)"
install -d -m 0755 "$PGDG_SOURCE_BACKUP_DIR"
shopt -s nullglob
for source_file in /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources; do
    [[ -f "$source_file" ]] || continue
    grep -Fq 'apt.postgresql.org' "$source_file" || continue
    mv "$source_file" "$PGDG_SOURCE_BACKUP_DIR/$(basename "$source_file")"
done
shopt -u nullglob
if [[ -f /etc/apt/sources.list ]] && grep -Fq 'apt.postgresql.org' /etc/apt/sources.list; then
    cp -a /etc/apt/sources.list "$PGDG_SOURCE_BACKUP_DIR/sources.list"
    sed -i '\|apt.postgresql.org|s|^|# disabled-by-astera: |' /etc/apt/sources.list
fi

apt-get update
apt-get install -y --no-install-recommends ca-certificates curl gnupg
install -d -m 0755 /usr/share/keyrings
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    | gpg --dearmor --yes -o /usr/share/keyrings/postgresql-pgdg.gpg
chmod 0644 /usr/share/keyrings/postgresql-pgdg.gpg
cat >/etc/apt/sources.list.d/pgdg.list <<EOF
deb [signed-by=/usr/share/keyrings/postgresql-pgdg.gpg] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main
EOF
apt-get update

log "Hedefteki mevcut PostgreSQL paketleri ve bos/yarim cluster temizleniyor"
mapfile -t installed_postgresql_packages < <(
    dpkg-query -W -f='${binary:Package}\t${db:Status-Abbrev}\n' 'postgresql*' 2>/dev/null \
        | awk '$2 ~ /^ii/ { print $1 }' \
        | sort -u
)
if ((${#installed_postgresql_packages[@]} > 0)); then
    systemctl stop postgresql 2>/dev/null || true
    apt-get purge -y "${installed_postgresql_packages[@]}"
    apt-get autoremove -y --purge
fi
rm -rf /etc/postgresql /var/lib/postgresql

log "PostgreSQL ${POSTGRESQL_MAJOR} kuruluyor"
apt-get install -y --no-install-recommends \
    ca-certificates curl wget gnupg lsb-release openssl rsync openssh-client openssh-server sshpass \
    build-essential pkg-config git subversion \
    libedit-dev libjansson-dev libxml2-dev libxslt1-dev libsqlite3-dev uuid-dev \
    libssl-dev libncurses5-dev libnewt-dev libcurl4-openssl-dev \
    libspeex-dev libspeexdsp-dev libogg-dev libvorbis-dev libgsm1-dev \
    libopus-dev libsrtp2-dev libspandsp-dev libical-dev libneon27-dev \
    unixodbc unixodbc-dev odbc-postgresql libpq-dev \
    "postgresql-${POSTGRESQL_MAJOR}" "postgresql-client-${POSTGRESQL_MAJOR}" \
    nginx certbot python3-certbot-nginx ufw fail2ban network-manager \
    iproute2 net-tools iputils-ping dnsutils ethtool traceroute mtr-tiny \
    php-fpm php-cli php-pgsql php-mbstring php-curl php-xml php-zip php-intl php-gd

log "Webmin resmi deposu ve paketi kuruluyor"
WEBMIN_REPO_SCRIPT="$(mktemp /var/tmp/webmin-setup-repo.XXXXXX.sh)"
curl -fsSL --retry 3 \
    https://raw.githubusercontent.com/webmin/webmin/master/webmin-setup-repo.sh \
    -o "$WEBMIN_REPO_SCRIPT"
sh "$WEBMIN_REPO_SCRIPT" --force --stable
rm -f "$WEBMIN_REPO_SCRIPT"
apt-get update
apt-get install -y --install-recommends webmin
systemctl enable --now webmin

log "Ethernet kartlari NetworkManager yonetimine aliniyor"
install -d -m 0755 /etc/netplan
cat >/etc/netplan/99-astera-networkmanager.yaml <<'NETPLAN'
network:
  version: 2
  renderer: NetworkManager
NETPLAN
chmod 0600 /etc/netplan/99-astera-networkmanager.yaml
netplan generate
systemctl enable --now NetworkManager
netplan apply

log "Hedef SSH servisi hazirlaniyor"
install -d -m 0755 /run/sshd /etc/ssh/sshd_config.d
cat >/etc/ssh/sshd_config.d/99-astera-root.conf <<'SSHD_CONFIG'
PermitRootLogin yes
PasswordAuthentication yes
SSHD_CONFIG
chmod 0644 /etc/ssh/sshd_config.d/99-astera-root.conf
sshd -t
systemctl enable --now ssh

SSH_OPTIONS=(
    -F /dev/null
    -p "$SOURCE_SSH_PORT"
    -o ConnectTimeout=10
    -o ServerAliveInterval=15
    -o ServerAliveCountMax=4
    -o "UserKnownHostsFile=$STATE_DIR/source_known_hosts"
    -o StrictHostKeyChecking=accept-new
)
touch "$STATE_DIR/source_known_hosts"
chmod 600 "$STATE_DIR/source_known_hosts"
SSH_USES_PASSWORD=0
if [[ -n "$SOURCE_SSH_KEY" ]]; then
    [[ -r "$SOURCE_SSH_KEY" ]] || die "SSH anahtari okunamiyor: $SOURCE_SSH_KEY"
    SSH_OPTIONS+=(-i "$SOURCE_SSH_KEY" -o BatchMode=yes)
    SSH_PREFIX=()
elif [[ -n "${SOURCE_SSH_PASSWORD:-}" ]]; then
    export SSHPASS="$SOURCE_SSH_PASSWORD"
    SSH_PREFIX=(sshpass -e)
    SSH_OPTIONS+=(
        -o BatchMode=no
        -o NumberOfPasswordPrompts=1
        -o PreferredAuthentications=password
        -o PubkeyAuthentication=no
        -o KbdInteractiveAuthentication=no
    )
    SSH_USES_PASSWORD=1
elif [[ -t 0 ]]; then
    read -r -s -p "$SOURCE_SSH_USER@$SOURCE_HOST SSH parolasi: " SSHPASS
    echo
    export SSHPASS
    SSH_PREFIX=(sshpass -e)
    SSH_OPTIONS+=(
        -o BatchMode=no
        -o NumberOfPasswordPrompts=1
        -o PreferredAuthentications=password
        -o PubkeyAuthentication=no
        -o KbdInteractiveAuthentication=no
    )
    SSH_USES_PASSWORD=1
else
    die "SOURCE_SSH_KEY veya SOURCE_SSH_PASSWORD gereklidir."
fi

remote() {
    "${SSH_PREFIX[@]}" ssh "${SSH_OPTIONS[@]}" \
        "$SOURCE_SSH_USER@$SOURCE_HOST" "$@"
}
remote_stream() {
    "${SSH_PREFIX[@]}" ssh "${SSH_OPTIONS[@]}" \
        "$SOURCE_SSH_USER@$SOURCE_HOST" "$@"
}
pull_dir() {
    local source_path=$1 target_path=$2
    mkdir -p "$target_path"
    "${SSH_PREFIX[@]}" rsync -aHAX --numeric-ids --info=stats2 \
        -e "ssh $(printf '%q ' "${SSH_OPTIONS[@]}")" \
        "$SOURCE_SSH_USER@$SOURCE_HOST:$source_path/" "$target_path/"
}
pull_file_if_exists() {
    local source_path=$1 target_path=$2
    if remote "test -f $(printf '%q' "$source_path")"; then
        mkdir -p "$(dirname "$target_path")"
        "${SSH_PREFIX[@]}" rsync -a --numeric-ids \
            -e "ssh $(printf '%q ' "${SSH_OPTIONS[@]}")" \
            "$SOURCE_SSH_USER@$SOURCE_HOST:$source_path" "$target_path"
    fi
}

ssh_error=''
if ! ssh_error="$(remote "true" 2>&1)"; then
    printf 'SSH baglanti ayrintisi: %s\n' "${ssh_error:-bilinmeyen hata}" >&2
    if ((SSH_USES_PASSWORD == 0)) || [[ ! -t 0 ]]; then
        die "Kaynak SSH baglantisi reddedildi. Kullanici, anahtar ve SSH ayarlarini kontrol edin."
    fi
    ssh_verified=0
    for attempt in 2 3; do
        read -r -s -p \
            "SSH dogrulanamadi. Parolayi tekrar girin ($attempt/3): " SSHPASS
        echo
        export SSHPASS
        if ssh_error="$(remote "true" 2>&1)"; then
            ssh_verified=1
            break
        fi
        printf 'SSH baglanti ayrintisi: %s\n' "${ssh_error:-bilinmeyen hata}" >&2
    done
    ((ssh_verified == 1)) \
        || die "Kaynak SSH kimlik dogrulamasi 3 denemede basarisiz oldu."
fi

log "Kaynak santral salt-okunur on kontrolden geciriliyor"
remote "set -e
    test -d $(printf '%q' "$SOURCE_APP_DIR")
    test -d /etc/asterisk
    command -v asterisk >/dev/null
    command -v pg_dump >/dev/null
    command -v rsync >/dev/null
    sudo -n -u postgres pg_dump --version >/dev/null
    sudo -n -u postgres psql -Atqc \"SELECT 1 FROM pg_database WHERE datname='$(printf '%s' "$DB_NAME" | sed "s/'/''/g")'\" | grep -qx 1
    printf 'Kaynak hostname: '; hostname
    asterisk -rx 'core show version' 2>/dev/null | sed -n '1p'
    printf 'Kaynak uygulama: '; readlink -f $(printf '%q' "$SOURCE_APP_DIR")"

WORK_DIR="$(mktemp -d /var/tmp/astera-install.XXXXXX)"
BACKUP_DIR="/root/astera-target-backup-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

if [[ -f "$STATE_DIR/completed" && $FORCE -ne 1 ]]; then
    die "Bu hedef daha once kurulmus. Yeniden veri almak icin --force kullanin."
fi
if [[ -d "$TARGET_APP_DIR" && -n "$(ls -A "$TARGET_APP_DIR" 2>/dev/null)" ]]; then
    ((FORCE == 1)) || die "$TARGET_APP_DIR bos degil. Uzerine yazmak icin --force kullanin."
    log "Hedef uygulama yedekleniyor: $BACKUP_DIR"
    cp -a "$TARGET_APP_DIR" "$BACKUP_DIR/application"
fi

log "Asterisk $ASTERISK_SERIES kuruluyor"
ASTERISK_MODULE_FILE="$(find /usr/lib /usr/lib64 /usr/local/lib \
    -type f -path '*/asterisk/modules/res_pjsip.so' -print -quit 2>/dev/null || true)"
if ! command -v asterisk >/dev/null 2>&1 \
    || ! asterisk -V 2>/dev/null | grep -Eq "^Asterisk ${ASTERISK_SERIES}([. -]|$)" \
    || [[ -z "$ASTERISK_MODULE_FILE" ]]; then
    AST_TARBALL="$WORK_DIR/asterisk.tar.gz"
    curl -fL --retry 3 \
        "https://downloads.asterisk.org/pub/telephony/asterisk/asterisk-${ASTERISK_SERIES}-current.tar.gz" \
        -o "$AST_TARBALL"
    mkdir -p "$WORK_DIR/asterisk-src"
    tar -xzf "$AST_TARBALL" --strip-components=1 -C "$WORK_DIR/asterisk-src"
    pushd "$WORK_DIR/asterisk-src" >/dev/null
    ./configure --with-jansson-bundled
    make menuselect.makeopts
    menuselect/menuselect \
        --enable res_http_websocket \
        --enable chan_pjsip \
        --enable res_pjsip_transport_websocket \
        --enable res_odbc \
        --enable cdr_adaptive_odbc \
        menuselect.makeopts
    make -j"$ASTERISK_BUILD_JOBS"
    make install
    make samples
    make config
    ldconfig
    popd >/dev/null
fi
ASTERISK_MODULE_FILE="$(find /usr/lib /usr/lib64 /usr/local/lib \
    -type f -path '*/asterisk/modules/res_pjsip.so' -print -quit 2>/dev/null || true)"
[[ -n "$ASTERISK_MODULE_FILE" ]] || die "Asterisk res_pjsip modulu kurulamadi."
ASTERISK_MODULE_DIR="$(dirname "$ASTERISK_MODULE_FILE")"

getent group asterisk >/dev/null || groupadd --system asterisk
id asterisk >/dev/null 2>&1 || useradd --system --gid asterisk \
    --home-dir /var/lib/asterisk --shell /usr/sbin/nologin asterisk
chown -R root:asterisk "$ASTERISK_MODULE_DIR"
chmod 0755 "$(dirname "$ASTERISK_MODULE_DIR")" "$ASTERISK_MODULE_DIR"
find "$ASTERISK_MODULE_DIR" -type d -exec chmod 0755 {} +
find "$ASTERISK_MODULE_DIR" -type f -name '*.so' -exec chmod 0644 {} +
getent group astera-panel >/dev/null || groupadd --system astera-panel
id astera-panel >/dev/null 2>&1 || useradd --system --gid astera-panel \
    --home-dir "$TARGET_APP_DIR" --shell /usr/sbin/nologin astera-panel

systemctl stop asterisk.service 2>/dev/null || true
systemctl stop astera-url-trigger.service 2>/dev/null || true

log "Asterisk yapilandirmasi ve calisma verileri aliniyor"
[[ -d /etc/asterisk ]] && cp -a /etc/asterisk "$BACKUP_DIR/asterisk-config-before-copy"
rm -rf /etc/asterisk
mkdir -p /etc/asterisk
pull_dir /etc/asterisk /etc/asterisk
pull_dir /var/lib/asterisk /var/lib/asterisk
pull_dir /var/spool/asterisk /var/spool/asterisk
pull_dir /var/log/asterisk /var/log/asterisk
pull_file_if_exists /etc/odbc.ini /etc/odbc.ini
pull_file_if_exists /etc/odbcinst.ini /etc/odbcinst.ini
if grep -Eq '^[[:space:]]*astmoddir[[:space:]]*=>' /etc/asterisk/asterisk.conf; then
    sed -i -E \
        "s#^[[:space:]]*astmoddir[[:space:]]*=>.*#astmoddir => $ASTERISK_MODULE_DIR#" \
        /etc/asterisk/asterisk.conf
else
    sed -i "/^\[directories\]/a astmoddir => $ASTERISK_MODULE_DIR" \
        /etc/asterisk/asterisk.conf
fi

log "Web uygulamasi aliniyor"
mkdir -p "$TARGET_APP_DIR"
pull_dir "$SOURCE_APP_DIR" "$TARGET_APP_DIR"

log "Yeni panel icin durum sorgusu ve istek onbellegi duzenleniyor"
python3 - \
    "$TARGET_APP_DIR/api.php" \
    "$TARGET_APP_DIR/assets/js/app.js" \
    "$TARGET_APP_DIR/includes/store.php" \
    "$TARGET_APP_DIR/includes/pbx_repository.php" <<'PY'
from pathlib import Path
import sys

api_path = Path(sys.argv[1])
app_path = Path(sys.argv[2])
store_path = Path(sys.argv[3])
repository_path = Path(sys.argv[4])

api = api_path.read_text(encoding="utf-8")
old_status = """        case 'status':
            $status = pbx_status(current_dept_id());
            $status['pending_count'] = store_pending_count();
            json_out(['ok' => true, 'status' => $status]);
"""
new_status = """        case 'status':
            $statusDeptId = current_dept_id();
            $pendingCount = store_pending_count();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $status = pbx_status($statusDeptId);
            $status['pending_count'] = $pendingCount;
            json_out(['ok' => true, 'status' => $status]);
"""
if old_status in api:
    api_path.write_text(api.replace(old_status, new_status, 1), encoding="utf-8")
elif new_status not in api:
    raise SystemExit("api.php durum sorgusu beklenen bicimde degil; guvenli duzenleme yapilamadi")

app = app_path.read_text(encoding="utf-8")
old_poll = "setInterval(() => this.refreshStatus(), 5000);"
new_poll = "setInterval(() => this.refreshStatus(), 30000);"
if old_poll in app:
    app_path.write_text(app.replace(old_poll, new_poll, 1), encoding="utf-8")
elif new_poll not in app:
    raise SystemExit("app.js durum sorgusu araligi bulunamadi")

store = store_path.read_text(encoding="utf-8")
cache_helpers = """function &store_request_cache(): array
{
    static $cache = [];
    return $cache;
}

function store_cache_forget(?string $name = null): void
{
    $cache =& store_request_cache();
    if ($name === null) {
        $cache = [];
        return;
    }
    unset($cache[$name]);
}

"""
read_start = """function store_read(string $name): array
{
    store_validate_name($name);
"""
cached_read_start = """function store_read(string $name): array
{
    store_validate_name($name);
    $cache =& store_request_cache();
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
"""
if "function &store_request_cache(): array" not in store:
    if read_start not in store:
        raise SystemExit("store.php okuma fonksiyonu beklenen bicimde degil")
    store = store.replace(read_start, cache_helpers + cached_read_start, 1)
    store = store.replace(
        "        return pbx_relational_read($name);",
        "        return $cache[$name] = pbx_relational_read($name);",
        1,
    )
    store = store.replace(
        """    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}""",
        """    if (!is_file($file)) {
        return $cache[$name] = [];
    }
    $raw = file_get_contents($file);
    $data = json_decode((string) $raw, true);
    return $cache[$name] = (is_array($data) ? $data : []);
}""",
        1,
    )
    store = store.replace(
        """function store_write(string $name, array $data): void
{
    store_validate_name($name);""",
        """function store_write(string $name, array $data): void
{
    store_validate_name($name);
    store_cache_forget($name);""",
        1,
    )
    store = store.replace(
        """function store_local_write(string $name, array $data): void
{""",
        """function store_local_write(string $name, array $data): void
{
    store_cache_forget($name);""",
        1,
    )
    store_path.write_text(store, encoding="utf-8")

repository = repository_path.read_text(encoding="utf-8")
repository_start = """function pbx_relational_json_rows(string $sql): array
{
"""
pdo_repository_start = """function pbx_relational_json_rows(string $sql): array
{
    if (PHP_OS_FAMILY !== 'Windows' && extension_loaded('pdo_pgsql')) {
        static $pdo = null;
        if (!$pdo instanceof PDO) {
            $pdo = new PDO(
                'pgsql:dbname=' . PBX_DB_NAME,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_PERSISTENT => true,
                ]
            );
        }
        $rows = $pdo->query($sql)->fetchAll();
        return is_array($rows) ? $rows : [];
    }
"""
if "PDO::ATTR_PERSISTENT => true" not in repository:
    if repository_start not in repository:
        raise SystemExit("pbx_repository.php sorgu fonksiyonu beklenen bicimde degil")
    repository_path.write_text(
        repository.replace(repository_start, pdo_repository_start, 1),
        encoding="utf-8",
    )
PY

log "PostgreSQL veritabani salt-okunur akis ile aliniyor"
remote_stream "sudo -n -u postgres pg_dumpall --roles-only" >"$WORK_DIR/roles.sql"
remote_stream "sudo -n -u postgres pg_dump --format=plain --no-owner --no-acl $(printf '%q' "$DB_NAME")" \
    >"$WORK_DIR/database.sql"
[[ -s "$WORK_DIR/database.sql" ]] || die "Kaynak veritabani yedegi bos."
# mktemp creates its directory as root-only. PostgreSQL must be able to
# traverse the directory and read both streamed backup files during restore.
chgrp postgres "$WORK_DIR" "$WORK_DIR/roles.sql" "$WORK_DIR/database.sql"
chmod 0750 "$WORK_DIR"
chmod 0640 "$WORK_DIR/roles.sql" "$WORK_DIR/database.sql"

log "Sistem ve PostgreSQL saat dilimi Europe/Istanbul olarak ayarlaniyor"
timedatectl set-timezone Europe/Istanbul
install -d -m 0755 -o root -g root /etc/systemd/timesyncd.conf.d
cat >/etc/systemd/timesyncd.conf.d/astera.conf <<'NTP'
[Time]
NTP=tr.pool.ntp.org pool.ntp.org time.cloudflare.com
FallbackNTP=ntp.ubuntu.com time.cloudflare.com
NTP
chmod 0644 /etc/systemd/timesyncd.conf.d/astera.conf
timedatectl set-ntp true
systemctl enable --now systemd-timesyncd.service
systemctl restart systemd-timesyncd.service
systemctl enable --now postgresql
cd /tmp
sudo -u postgres psql -v ON_ERROR_STOP=1 -d postgres \
    -c "ALTER SYSTEM SET timezone = 'Europe/Istanbul';"
systemctl restart postgresql
[[ "$(sudo -u postgres psql -d postgres -Atqc 'SHOW timezone')" == "Europe/Istanbul" ]] \
    || die "PostgreSQL saat dilimi Europe/Istanbul olarak ayarlanamadi."

if sudo -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='$(printf '%s' "$DB_NAME" | sed "s/'/''/g")'" | grep -qx 1; then
    existing_tables="$(sudo -u postgres psql -d "$DB_NAME" -Atqc \
        "SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')")"
    if [[ "$existing_tables" != "0" ]]; then
        ((FORCE == 1)) || die "Hedef $DB_NAME veritabani dolu. Yenilemek icin --force kullanin."
        sudo -u postgres pg_dump -Fc "$DB_NAME" >"$BACKUP_DIR/database-before-restore.dump"
    fi
    sudo -u postgres dropdb --force "$DB_NAME"
fi

# The roles dump retains source role password hashes and grants. Existing
# PostgreSQL built-in roles can safely produce duplicate-role notices.
sudo -u postgres psql -v ON_ERROR_STOP=0 -f "$WORK_DIR/roles.sql" postgres
if ! sudo -u postgres psql -Atqc "SELECT 1 FROM pg_roles WHERE rolname='asterisk'" | grep -qx 1; then
    sudo -u postgres createuser asterisk
fi
if ! sudo -u postgres psql -Atqc "SELECT 1 FROM pg_roles WHERE rolname='astera-panel'" | grep -qx 1; then
    sudo -u postgres createuser "astera-panel"
fi
sudo -u postgres createdb --owner=asterisk "$DB_NAME"
sudo -u postgres psql -v ON_ERROR_STOP=1 --dbname="$DB_NAME" \
    --file="$WORK_DIR/database.sql"
PRI_MIGRATION="$TARGET_APP_DIR/migrations/011_pri_spans.sql"
[[ -s "$PRI_MIGRATION" ]] || die "PRI migration dosyasi bulunamadi: $PRI_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$PRI_MIGRATION"
OUTBOUND_PERMISSION_MIGRATION="$TARGET_APP_DIR/migrations/022_extension_outbound_permissions.sql"
[[ -s "$OUTBOUND_PERMISSION_MIGRATION" ]] \
    || die "Abone dis arama yetkisi migration dosyasi bulunamadi: $OUTBOUND_PERMISSION_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$OUTBOUND_PERMISSION_MIGRATION"
CRM_DEFAULT_MIGRATION="$TARGET_APP_DIR/migrations/023_extension_crm_default_disabled.sql"
[[ -s "$CRM_DEFAULT_MIGRATION" ]] \
    || die "Abone CRM varsayilan migration dosyasi bulunamadi: $CRM_DEFAULT_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$CRM_DEFAULT_MIGRATION"
PANEL_CONFIG_WRITE_MIGRATION="$TARGET_APP_DIR/migrations/024_panel_pbx_config_write.sql"
[[ -s "$PANEL_CONFIG_WRITE_MIGRATION" ]] \
    || die "Panel PBX yazma yetkisi migration dosyasi bulunamadi: $PANEL_CONFIG_WRITE_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$PANEL_CONFIG_WRITE_MIGRATION"
NETWORK_SETTINGS_MIGRATION="$TARGET_APP_DIR/migrations/025_network_interface_settings.sql"
[[ -s "$NETWORK_SETTINGS_MIGRATION" ]] \
    || die "Ethernet ayarlari migration dosyasi bulunamadi: $NETWORK_SETTINGS_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$NETWORK_SETTINGS_MIGRATION"

log "Hedef makinenin Ethernet bilgileri veritabanina isleniyor"
mapfile -t TARGET_NETWORK_INTERFACES < <(
    ip -o -4 address show scope global \
        | awk '{print $2}' \
        | sort -u
)
for interface in "${TARGET_NETWORK_INTERFACES[@]}"; do
    [[ "$interface" =~ ^[A-Za-z0-9][A-Za-z0-9_.:-]{0,30}$ ]] \
        || die "Gecersiz hedef Ethernet arayuzu: $interface"
    ipv4_cidr="$(ip -o -4 address show dev "$interface" scope global | awk 'NR==1 {print $4}')"
    gateway="$(ip -4 route show default dev "$interface" | awk 'NR==1 {print $3}')"
    mtu="$(cat "/sys/class/net/$interface/mtu")"
    connection_uuid="$(nmcli -g GENERAL.CON-UUID device show "$interface" 2>/dev/null | head -n1 || true)"
    dns_servers="$(
        resolvectl dns "$interface" 2>/dev/null \
            | awk -F': ' 'NF > 1 {print $2}' \
            | tr ' ' '\n' \
            | awk '/^[0-9]{1,3}(\.[0-9]{1,3}){3}$/' \
            | paste -sd, - \
            || true
    )"
    method="manual"
    ip -o -4 address show dev "$interface" scope global | grep -qw dynamic \
        && method="dhcp"
    dept_id="$(
        sudo -u postgres psql -d "$DB_NAME" -Atqc \
            "SELECT COALESCE(
                (SELECT dept_id FROM network_interface_settings
                 WHERE interface_name = '$(printf '%s' "$interface" | sed "s/'/''/g")' LIMIT 1),
                (SELECT dept_id FROM astera_tenants ORDER BY dept_id LIMIT 1)
            )"
    )"
    [[ -n "$dept_id" ]] || die "Ethernet ayari icin firma bulunamadi: $interface"
    sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" \
        -v interface_name="$interface" \
        -v dept_id="$dept_id" \
        -v method="$method" \
        -v ipv4_cidr="$ipv4_cidr" \
        -v gateway="$gateway" \
        -v dns_servers="$dns_servers" \
        -v mtu="$mtu" \
        -v connection_uuid="$connection_uuid" <<'SQL'
INSERT INTO network_interface_settings (
    interface_name, dept_id, method, ipv4_cidr, gateway, dns_servers, mtu,
    connection_uuid, rollback_unit, backup_connection, confirmed,
    applied_at, updated_at, updated_by
) VALUES (
    :'interface_name', :'dept_id', :'method',
    NULLIF(:'ipv4_cidr', '')::inet,
    NULLIF(:'gateway', '')::inet,
    CASE WHEN :'dns_servers' = '' THEN ARRAY[]::inet[]
         ELSE string_to_array(:'dns_servers', ',')::inet[] END,
    :'mtu'::integer, :'connection_uuid', '', '', TRUE, now(), now(),
    'installer-target-network'
)
ON CONFLICT (interface_name) DO UPDATE SET
    method = EXCLUDED.method,
    ipv4_cidr = EXCLUDED.ipv4_cidr,
    gateway = EXCLUDED.gateway,
    dns_servers = EXCLUDED.dns_servers,
    mtu = EXCLUDED.mtu,
    connection_uuid = EXCLUDED.connection_uuid,
    rollback_unit = '',
    backup_connection = '',
    confirmed = TRUE,
    applied_at = now(),
    updated_at = now(),
    updated_by = EXCLUDED.updated_by;
SQL
done

PANEL_OPERATIONAL_WRITE_MIGRATION="$TARGET_APP_DIR/migrations/026_panel_operational_write_permissions.sql"
[[ -s "$PANEL_OPERATIONAL_WRITE_MIGRATION" ]] \
    || die "Panel operasyonel yazma yetkisi migration dosyasi bulunamadi: $PANEL_OPERATIONAL_WRITE_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$PANEL_OPERATIONAL_WRITE_MIGRATION"
AUTOPROVISION_MIGRATION="$TARGET_APP_DIR/migrations/027_autoprovision_devices.sql"
[[ -s "$AUTOPROVISION_MIGRATION" ]] \
    || die "Autoprovision migration dosyasi bulunamadi: $AUTOPROVISION_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$AUTOPROVISION_MIGRATION"
AUTOPROVISION_ACCOUNT_SLOT_MIGRATION="$TARGET_APP_DIR/migrations/028_autoprovision_account_slot.sql"
[[ -s "$AUTOPROVISION_ACCOUNT_SLOT_MIGRATION" ]] \
    || die "Autoprovision hesap yuvasi migration dosyasi bulunamadi: $AUTOPROVISION_ACCOUNT_SLOT_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$AUTOPROVISION_ACCOUNT_SLOT_MIGRATION"
AUTOPROVISION_TEMPLATE_MIGRATION="$TARGET_APP_DIR/migrations/029_autoprovision_templates_and_keys.sql"
[[ -s "$AUTOPROVISION_TEMPLATE_MIGRATION" ]] \
    || die "Autoprovision sablon ve tus migration dosyasi bulunamadi: $AUTOPROVISION_TEMPLATE_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$AUTOPROVISION_TEMPLATE_MIGRATION"
AUTOPROVISION_SCHEDULE_MIGRATION="$TARGET_APP_DIR/migrations/030_autoprovision_schedule.sql"
[[ -s "$AUTOPROVISION_SCHEDULE_MIGRATION" ]] \
    || die "Autoprovision zamanlama migration dosyasi bulunamadi: $AUTOPROVISION_SCHEDULE_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$AUTOPROVISION_SCHEDULE_MIGRATION"
CALLCENTER_MIGRATION="$TARGET_APP_DIR/migrations/012_callcenter_core.sql"
[[ -s "$CALLCENTER_MIGRATION" ]] \
    || die "Callcenter migration dosyasi bulunamadi: $CALLCENTER_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$CALLCENTER_MIGRATION"
CALLCENTER_ADMIN_MIGRATION="$TARGET_APP_DIR/migrations/013_callcenter_default_admin.sql"
[[ -s "$CALLCENTER_ADMIN_MIGRATION" ]] \
    || die "Callcenter admin migration dosyasi bulunamadi: $CALLCENTER_ADMIN_MIGRATION"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$CALLCENTER_ADMIN_MIGRATION"
for migration in \
    014_callcenter_permissions.sql \
    015_callcenter_audit_logs.sql \
    016_agent_detail_recording_permissions.sql \
    017_callcenter_surveys.sql \
    018_callcenter_system_settings.sql \
    019_callcenter_legacy_survey_import.sql \
    020_callcenter_survey_call_unique.sql \
    021_callcenter_agent_phone_mode.sql \
    031_callcenter_alarms.sql \
    032_integrations_fidelio_fias.sql \
    033_integration_remote_syslog.sql \
    034_system_backups.sql \
    035_network_static_routes.sql \
    036_pbx_default_turkish_language.sql \
    037_ivr_internal_numbers.sql \
    038_ringgroup_internal_numbers.sql \
    039_system_ntp_settings.sql \
    040_system_backup_schedule.sql \
    041_feature_takeover_settings.sql \
    042_ami_managers.sql \
    043_extension_qualify_default.sql; do
    migration_path="$TARGET_APP_DIR/migrations/$migration"
    [[ -s "$migration_path" ]] || die "Migration dosyasi bulunamadi: $migration_path"
    sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <"$migration_path"
done
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <<SQL
GRANT CONNECT ON DATABASE "$DB_NAME" TO asterisk;
GRANT USAGE ON SCHEMA public TO asterisk;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO asterisk;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO asterisk;
GRANT CONNECT ON DATABASE "$DB_NAME" TO "astera-panel";
GRANT USAGE ON SCHEMA public TO "astera-panel";
GRANT SELECT ON astera_tenants, pbx_config_entities, pbx_config_values,
    crm_customers, crm_customer_phones, softphone_crm_tokens,
    pbx_custom_contexts, pbx_custom_context_steps,
    cdr, queue_log,
    callcenter_agents, callcenter_agent_queue_assignments, callcenter_break_types,
    callcenter_breaks, callcenter_agent_sessions, callcenter_supervisor_users,
    callcenter_supervisor_queue_scopes, callcenter_supervisor_tenant_scopes,
    autoprovision_devices, autoprovision_templates,
    autoprovision_device_keys, autoprovision_settings
    TO "astera-panel";
GRANT INSERT, UPDATE, DELETE ON callcenter_agents, callcenter_agent_queue_assignments,
    callcenter_break_types, callcenter_breaks, callcenter_agent_sessions,
    callcenter_supervisor_users, callcenter_supervisor_queue_scopes,
    callcenter_supervisor_tenant_scopes,
    autoprovision_devices, autoprovision_templates,
    autoprovision_device_keys, autoprovision_settings TO "astera-panel";
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO "astera-panel";
GRANT UPDATE (last_used_at, updated_at) ON softphone_crm_tokens TO "astera-panel";
SQL

log "CDR yazici gorunumu ve Asterisk ODBC hedefi hazirlaniyor"
CDR_WRITER_MIGRATION="$TARGET_APP_DIR/migrations/010_cdr_asterisk_writer.sql"
CDR_ADAPTIVE_CONF="/etc/asterisk/cdr_adaptive_odbc.conf"
CDR_CONF="/etc/asterisk/cdr.conf"
[[ -s "$CDR_WRITER_MIGRATION" ]] \
    || die "CDR yazici migration dosyasi bulunamadi: $CDR_WRITER_MIGRATION"
[[ -f "$CDR_ADAPTIVE_CONF" ]] \
    || die "Asterisk Adaptive ODBC yapilandirmasi bulunamadi: $CDR_ADAPTIVE_CONF"
[[ -f "$CDR_CONF" ]] \
    || die "Asterisk CDR yapilandirmasi bulunamadi: $CDR_CONF"

sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" \
    <"$CDR_WRITER_MIGRATION"

if grep -Eq '^[[:space:]]*usegmtime[[:space:]]*=' "$CDR_CONF"; then
    sed -i -E \
        's/^[[:space:]]*usegmtime[[:space:]]*=.*/usegmtime=no/' \
        "$CDR_CONF"
else
    sed -i '/^\[general\]/a usegmtime=no' "$CDR_CONF"
fi

python3 - "$CDR_ADAPTIVE_CONF" <<'PY'
import pathlib
import re
import sys

path = pathlib.Path(sys.argv[1])
lines = path.read_text(encoding="utf-8").splitlines()
section = next(
    (index for index, line in enumerate(lines) if re.match(r"^\s*\[[^]]+\]\s*$", line)),
    None,
)
if section is None:
    raise SystemExit("Adaptive ODBC yapilandirmasinda baglanti bolumu bulunamadi")

table_indexes = [
    index for index, line in enumerate(lines)
    if re.match(r"^\s*table\s*=", line)
]
if table_indexes:
    lines[table_indexes[0]] = "table=cdr_asterisk_writer"
    for index in reversed(table_indexes[1:]):
        del lines[index]
    table_index = table_indexes[0]
else:
    table_index = section + 1
    lines.insert(table_index, "table=cdr_asterisk_writer")

alias_pattern = re.compile(r"^\s*alias\s+end\s*=>\s*call_end\s*$")
if not any(alias_pattern.match(line) for line in lines):
    lines.insert(table_index + 1, "alias end => call_end")

path.write_text("\n".join(lines) + "\n", encoding="utf-8")
PY
chown asterisk:asterisk "$CDR_ADAPTIVE_CONF"
chmod 0640 "$CDR_ADAPTIVE_CONF"

if [[ "$SOURCE_PANEL_DOMAIN" != "$PANEL_DOMAIN" ]]; then
    log "Kopyalanan URL tetikleyicileri yeni panel alan adına taşınıyor"
    sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <<SQL
UPDATE pbx_config_values AS value
SET text_value = replace(
        replace(
            value.text_value,
            'https://$SOURCE_PANEL_DOMAIN/',
            'https://$PANEL_DOMAIN/'
        ),
        'http://$SOURCE_PANEL_DOMAIN/',
        'https://$PANEL_DOMAIN/'
    ),
    updated_at = now()
FROM pbx_config_entities AS entity
WHERE entity.entity_id = value.entity_id
  AND entity.dept_id = value.dept_id
  AND entity.store_name = 'url_triggers'
  AND value.field_name = 'url_template'
  AND (
      value.text_value LIKE 'https://$SOURCE_PANEL_DOMAIN/%'
      OR value.text_value LIKE 'http://$SOURCE_PANEL_DOMAIN/%'
  );
SQL
fi

log "Yerel, anahtar tabanli PBX yonetim baglantisi hazirlaniyor"
install -d -m 0750 -o astera-panel -g astera-panel /etc/astera
if [[ ! -s /etc/astera/alarm.key ]]; then
    openssl rand -hex 32 >/etc/astera/alarm.key
fi
chown root:astera-panel /etc/astera/alarm.key
chmod 0640 /etc/astera/alarm.key
install -d -m 0750 -o astera-panel -g astera-panel /var/lib/astera-alarms
cat >/etc/sudoers.d/astera-alarms <<'SUDOERS'
astera-panel ALL=(root) NOPASSWD: /usr/bin/systemctl restart asterisk.service, /usr/bin/systemctl restart postgresql.service, /usr/bin/systemctl restart nginx.service
SUDOERS
chmod 0440 /etc/sudoers.d/astera-alarms
visudo -cf /etc/sudoers.d/astera-alarms >/dev/null
if [[ ! -f /etc/astera/id_ed25519 ]]; then
    sudo -u astera-panel ssh-keygen -q -t ed25519 -N '' \
        -f /etc/astera/id_ed25519 -C "astera-panel@$TARGET_HOST"
fi
install -d -m 0700 -o root -g root /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
panel_public_key="$(cat /etc/astera/id_ed25519.pub)"
panel_authorized_key="from=\"127.0.0.1,::1\",restrict $panel_public_key"
grep -Fvx "$panel_public_key" /root/.ssh/authorized_keys \
    >/root/.ssh/authorized_keys.tmp || true
mv /root/.ssh/authorized_keys.tmp /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
grep -Fqx "$panel_authorized_key" /root/.ssh/authorized_keys \
    || printf '%s\n' "$panel_authorized_key" >>/root/.ssh/authorized_keys
ssh-keyscan -H 127.0.0.1 localhost "$TARGET_HOST" 2>/dev/null \
    >/etc/astera/known_hosts || true
chown astera-panel:astera-panel /etc/astera/id_ed25519 /etc/astera/id_ed25519.pub \
    /etc/astera/known_hosts
chmod 600 /etc/astera/id_ed25519 /etc/astera/known_hosts
cat >/etc/astera/astera.env <<ENV
ASTERA_PBX_HOST=$TARGET_HOST
ASTERA_PBX_SSH_HOST=127.0.0.1
ASTERA_SSH_USER=root
ASTERA_SSH_PORT=22
ASTERA_SSH_PASS=
ASTERA_SSH_KEY=/etc/astera/id_ed25519
ASTERA_SSH_KNOWN_HOSTS=/etc/astera/known_hosts
ASTERA_PANEL_DOMAIN=$PANEL_DOMAIN
ASTERA_DB_NAME=$DB_NAME
ENV
chown root:astera-panel /etc/astera/astera.env
chmod 0640 /etc/astera/astera.env
log "SSHD ayarlari ana kurulumda degistirilmedi; configure_root_ssh.sh ayrica calistirilmalidir"

log "Dosya izinleri duzenleniyor"
install -d -m 0770 -o astera-panel -g astera-panel "$TARGET_APP_DIR/data"
chown -R astera-panel:www-data "$TARGET_APP_DIR"
find "$TARGET_APP_DIR" -type d -exec chmod u=rwx,g=rx,o= {} +
find "$TARGET_APP_DIR" -type f -exec chmod u=rw,g=r,o= {} +
chown -R astera-panel:astera-panel "$TARGET_APP_DIR/data"
chmod -R u+rwX,g+rwX,o-rwx "$TARGET_APP_DIR/data"
chown -R asterisk:asterisk \
    /etc/asterisk /var/lib/asterisk /var/spool/asterisk /var/log/asterisk
find /etc/asterisk -type d -exec chmod 750 {} +
find /etc/asterisk -type f -exec chmod 640 {} +

log "PHP-FPM ve Nginx yapilandiriliyor"
PHP_FPM_SERVICE=""
for php_fpm_unit in \
    /etc/systemd/system/php*-fpm.service \
    /lib/systemd/system/php*-fpm.service \
    /usr/lib/systemd/system/php*-fpm.service; do
    [[ -f "$php_fpm_unit" ]] || continue
    PHP_FPM_SERVICE="$(basename "$php_fpm_unit" .service)"
    break
done
[[ -n "$PHP_FPM_SERVICE" ]] || die "PHP-FPM servisi bulunamadi."
PHP_VERSION="${PHP_FPM_SERVICE#php}"
PHP_VERSION="${PHP_VERSION%-fpm}"
PHP_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
PHP_POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
[[ -f "$PHP_POOL" ]] || die "PHP-FPM havuz yapilandirmasi bulunamadi: $PHP_POOL"
# The application deliberately uses PostgreSQL peer authentication as the
# astera-panel OS user and needs its protected local SSH management key.
sed -i \
    -e 's/^user = .*/user = astera-panel/' \
    -e 's/^group = .*/group = astera-panel/' \
    -e 's/^pm.max_children = .*/pm.max_children = 10/' \
    -e 's/^pm.start_servers = .*/pm.start_servers = 4/' \
    -e 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/' \
    -e 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 6/' \
    "$PHP_POOL"

install -d -m 0750 -o root -g astera-panel /etc/nginx/ssl
install -d -m 0750 -o root -g www-data /var/lib/astera/provisioning
if remote "test -f /etc/letsencrypt/live/$(printf '%q' "$PANEL_DOMAIN")/fullchain.pem \
    && test -f /etc/letsencrypt/live/$(printf '%q' "$PANEL_DOMAIN")/privkey.pem"; then
    pull_dir /etc/letsencrypt /etc/letsencrypt
    WEB_CERT="/etc/letsencrypt/live/$PANEL_DOMAIN/fullchain.pem"
    WEB_KEY="/etc/letsencrypt/live/$PANEL_DOMAIN/privkey.pem"
else
    WEB_CERT="/etc/nginx/ssl/astera.crt"
    WEB_KEY="/etc/nginx/ssl/astera.key"
    if [[ ! -f "$WEB_CERT" || ! -f "$WEB_KEY" ]]; then
        openssl req -x509 -nodes -newkey rsa:3072 -days 825 \
            -keyout "$WEB_KEY" -out "$WEB_CERT" \
            -subj "/CN=$PANEL_DOMAIN" \
            -addext "subjectAltName=DNS:$PANEL_DOMAIN,IP:$TARGET_HOST"
    fi
    chmod 600 "$WEB_KEY"
fi

cat >/etc/nginx/sites-available/astera <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name $PANEL_DOMAIN $TARGET_HOST;
    location ^~ /provision/ {
        alias /var/lib/astera/provisioning/;
        allow $PROVISION_NETWORK;
        deny all;
        default_type application/octet-stream;
        add_header Cache-Control "no-store";
    }
    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name $PANEL_DOMAIN $TARGET_HOST;
    root $TARGET_APP_DIR;
    index index.php;
    client_max_body_size 10m;

    ssl_certificate $WEB_CERT;
    ssl_certificate_key $WEB_KEY;
    ssl_protocols TLSv1.2 TLSv1.3;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;

    location ^~ /provision/ {
        alias /var/lib/astera/provisioning/;
        allow $PROVISION_NETWORK;
        deny all;
        default_type application/octet-stream;
        add_header Cache-Control "no-store";
    }

    location = /asterisk-ws {
        proxy_pass http://127.0.0.1:8088/ws;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_buffering off;
        proxy_read_timeout 86400;
        proxy_send_timeout 86400;
    }
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_SOCKET;
        fastcgi_param ASTERA_PBX_HOST $TARGET_HOST;
        fastcgi_param ASTERA_PBX_SSH_HOST 127.0.0.1;
        fastcgi_param ASTERA_SSH_USER root;
        fastcgi_param ASTERA_SSH_PASS "";
        fastcgi_param ASTERA_SSH_KEY /etc/astera/id_ed25519;
        fastcgi_param ASTERA_SSH_KNOWN_HOSTS /etc/astera/known_hosts;
        fastcgi_param ASTERA_PANEL_DOMAIN $PANEL_DOMAIN;
        fastcgi_param ASTERA_DB_NAME $DB_NAME;
    }
    location ~ ^/(?:data|includes|migrations|bin|ops|deploy|tools)/ {
        deny all;
    }
    location ~ /\. {
        deny all;
    }
}
NGINX
rm -f /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/astera /etc/nginx/sites-enabled/astera
nginx -t

log "Yardimci systemd servisleri kuruluyor"
cat >/etc/systemd/system/asterisk.service <<'SYSTEMD'
[Unit]
Description=Astera Asterisk PBX
After=network-online.target postgresql.service dahdi.service
Wants=network-online.target

[Service]
Type=simple
User=asterisk
Group=asterisk
Environment=HOME=/var/lib/asterisk
RuntimeDirectory=asterisk
RuntimeDirectoryMode=0755
ExecStart=/usr/sbin/asterisk -f -U asterisk -G asterisk
ExecReload=/usr/sbin/asterisk -rx "core reload"
ExecStop=/usr/sbin/asterisk -rx "core stop now"
Restart=on-failure
RestartSec=3
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
SYSTEMD
if [[ -f "$TARGET_APP_DIR/deploy/astera-url-trigger.service" ]]; then
    cp "$TARGET_APP_DIR/deploy/astera-url-trigger.service" /etc/systemd/system/
fi
if [[ -f "$TARGET_APP_DIR/ops/systemd/astera-cdr-partitions.service" ]]; then
    cp "$TARGET_APP_DIR/ops/systemd/astera-cdr-partitions.service" /etc/systemd/system/
fi
if [[ -f "$TARGET_APP_DIR/ops/systemd/astera-cdr-partitions.timer" ]]; then
    cp "$TARGET_APP_DIR/ops/systemd/astera-cdr-partitions.timer" /etc/systemd/system/
fi
if [[ -f "$TARGET_APP_DIR/ops/systemd/astera-alarms.service" ]]; then
    cp "$TARGET_APP_DIR/ops/systemd/astera-alarms.service" /etc/systemd/system/
fi
if [[ -f "$TARGET_APP_DIR/ops/systemd/astera-alarms.timer" ]]; then
    cp "$TARGET_APP_DIR/ops/systemd/astera-alarms.timer" /etc/systemd/system/
fi
if [[ -f "$TARGET_APP_DIR/ops/systemd/astera-fidelio-fias.service" ]]; then
    cp "$TARGET_APP_DIR/ops/systemd/astera-fidelio-fias.service" /etc/systemd/system/
fi
if [[ -f "$TARGET_APP_DIR/ops/systemd/astera-syslog-export.service" ]]; then
    cp "$TARGET_APP_DIR/ops/systemd/astera-syslog-export.service" /etc/systemd/system/
fi
if [[ -f "$TARGET_APP_DIR/ops/systemd/astera-backup-worker.service" ]]; then
    cp "$TARGET_APP_DIR/ops/systemd/astera-backup-worker.service" /etc/systemd/system/
fi
if [[ -f "$TARGET_APP_DIR/ops/systemd/astera-network-routes.service" ]]; then
    cp "$TARGET_APP_DIR/ops/systemd/astera-network-routes.service" /etc/systemd/system/
fi
install -d -m 0770 -o astera-panel -g astera-panel /var/backups/astera
install -m 0750 -o root -g root \
    "$TARGET_APP_DIR/bin/backup_restore_root.sh" /usr/local/sbin/astera-backup-restore
install -m 0750 -o root -g root \
    "$TARGET_APP_DIR/bin/backup_dump_root.sh" /usr/local/sbin/astera-backup-dump
install -m 0750 -o root -g root \
    "$TARGET_APP_DIR/bin/backup_files_root.sh" /usr/local/sbin/astera-backup-files
install -m 0750 -o root -g root \
    "$TARGET_APP_DIR/bin/network_routes_root.sh" /usr/local/sbin/astera-network-routes
install -m 0750 -o root -g root \
    "$TARGET_APP_DIR/bin/system_update_root.sh" /usr/local/sbin/astera-system-update
install -d -m 0750 -o root -g astera-panel /etc/astera
touch /etc/astera/network-routes.conf
chown root:astera-panel /etc/astera/network-routes.conf
chmod 0640 /etc/astera/network-routes.conf
cat >/etc/sudoers.d/astera-backup <<'SUDOERS'
astera-panel ALL=(root) NOPASSWD: /usr/local/sbin/astera-backup-restore *
astera-panel ALL=(root) NOPASSWD: /usr/local/sbin/astera-backup-dump *
astera-panel ALL=(root) NOPASSWD: /usr/local/sbin/astera-backup-files *
SUDOERS
chmod 0440 /etc/sudoers.d/astera-backup
visudo -cf /etc/sudoers.d/astera-backup >/dev/null
cat >/etc/sudoers.d/astera-system-update <<'SUDOERS'
astera-panel ALL=(root) NOPASSWD: /usr/local/sbin/astera-system-update *
SUDOERS
chmod 0440 /etc/sudoers.d/astera-system-update
visudo -cf /etc/sudoers.d/astera-system-update >/dev/null
systemctl daemon-reload
systemctl enable "$PHP_FPM_SERVICE" nginx
systemctl restart "$PHP_FPM_SERVICE" nginx
[[ -f /etc/systemd/system/astera-url-trigger.service ]] \
    && systemctl enable --now astera-url-trigger.service
[[ -f /etc/systemd/system/astera-cdr-partitions.timer ]] \
    && systemctl enable --now astera-cdr-partitions.timer
[[ -f /etc/systemd/system/astera-alarms.timer ]] \
    && systemctl enable --now astera-alarms.timer
[[ -f /etc/systemd/system/astera-fidelio-fias.service ]] \
    && systemctl enable --now astera-fidelio-fias.service
[[ -f /etc/systemd/system/astera-syslog-export.service ]] \
    && systemctl enable --now astera-syslog-export.service
[[ -f /etc/systemd/system/astera-backup-worker.service ]] \
    && systemctl enable --now astera-backup-worker.service
[[ -f /etc/systemd/system/astera-network-routes.service ]] \
    && systemctl enable --now astera-network-routes.service

if command -v sshd >/dev/null 2>&1; then
    log "SSH 22 ve 45555 portlarini dinleyecek sekilde ayarlaniyor"
    install -d -m 0755 /etc/ssh/sshd_config.d
    cat >/etc/ssh/sshd_config.d/99-astera-ports.conf <<'EOF'
Port 22
Port 45555
EOF
    sshd -t
    if systemctl list-unit-files ssh.socket --no-legend 2>/dev/null | grep -q '^ssh\.socket'; then
        install -d -m 0755 /etc/systemd/system/ssh.socket.d
        cat >/etc/systemd/system/ssh.socket.d/99-astera-ports.conf <<'EOF'
[Socket]
ListenStream=
ListenStream=22
ListenStream=45555
EOF
        systemctl daemon-reload
        systemctl restart ssh.socket
    fi
    systemctl restart ssh
fi

if command -v ufw >/dev/null 2>&1 && ufw status | grep -q '^Status: active'; then
    log "Etkin UFW icin gerekli portlar aciliyor"
    for interface in $(ip -o -4 route show default \
        | awk '{for (i=1; i<=NF; i++) if ($i == "dev") print $(i+1)}' \
        | sort -u); do
        ip -o -4 route show proto kernel scope link dev "$interface"
    done \
        | awk '$1 ~ /^10\./ || $1 ~ /^192\.168\./ || $1 ~ /^172\.(1[6-9]|2[0-9]|3[01])\./ {print $1}' \
        | sort -u \
        | while IFS= read -r network; do
            ufw allow from "$network" comment astera-local-network
        done
    ufw allow 22/tcp
    ufw allow 45555/tcp
    ufw allow 80/tcp
    ufw allow 443/tcp
    ufw allow 5060/udp
    ufw allow 5060/tcp
    ufw allow 5061/tcp
    ufw allow 8089/tcp
    ufw allow 10000:20000/udp
fi

log "Kurulum dogrulaniyor"
php -l "$TARGET_APP_DIR/webphone.php"
php -l "$TARGET_APP_DIR/api.php"
php -l "$TARGET_APP_DIR/includes/generator.php"
php -l "$TARGET_APP_DIR/includes/feature_codes.php"
php -l "$TARGET_APP_DIR/pages/features.php"
php -l "$TARGET_APP_DIR/Agent/login.php"
php -l "$TARGET_APP_DIR/Agent/agent_dashboard_v2.php"
php -l "$TARGET_APP_DIR/Supervisor/fop_abone.php"
php -l "$TARGET_APP_DIR/Supervisor/reports/callcenter/agentdetay.php"
php -l "$TARGET_APP_DIR/Supervisor/reports/callcenter/anket.php"
php -l "$TARGET_APP_DIR/includes/alarms.php"
php -l "$TARGET_APP_DIR/bin/check_alarms.php"
php -l "$TARGET_APP_DIR/includes/integrations.php"
php -l "$TARGET_APP_DIR/bin/fidelio_fias_service.php"
php -l "$TARGET_APP_DIR/pages/integrations.php"
php -l "$TARGET_APP_DIR/includes/syslog_integration.php"
php -l "$TARGET_APP_DIR/bin/syslog_export_service.php"
php -l "$TARGET_APP_DIR/pages/integrations_syslog.php"
php -l "$TARGET_APP_DIR/includes/backups.php"
php -l "$TARGET_APP_DIR/bin/backup_worker.php"
php -l "$TARGET_APP_DIR/pages/backup.php"
php -l "$TARGET_APP_DIR/backup_download.php"
php -l "$TARGET_APP_DIR/includes/system_updates.php"
php -l "$TARGET_APP_DIR/system_updates.php"
bash -n "$TARGET_APP_DIR/bin/backup_restore_root.sh"
bash -n "$TARGET_APP_DIR/bin/backup_dump_root.sh"
bash -n "$TARGET_APP_DIR/bin/backup_files_root.sh"
bash -n "$TARGET_APP_DIR/bin/network_routes_root.sh"
bash -n "$TARGET_APP_DIR/bin/system_update_root.sh"
php -l "$TARGET_APP_DIR/pages/provisioning.php"
php -l "$TARGET_APP_DIR/includes/provisioning.php"
sudo -u postgres psql -d "$DB_NAME" -Atqc 'SELECT current_database()' | grep -Fxq "$DB_NAME"
sudo -u postgres psql -d "$DB_NAME" -Atqc \
    "SELECT version FROM astera_schema_migrations WHERE version='021_callcenter_agent_phone_mode'" \
    | grep -Fxq '021_callcenter_agent_phone_mode'
sudo -u postgres psql -d "$DB_NAME" -Atqc \
    "SELECT column_name FROM information_schema.columns
     WHERE table_schema='public' AND table_name='callcenter_agent_sessions'
       AND column_name='phone_mode'" \
    | grep -Fxq 'phone_mode'
sudo -u postgres psql -d "$DB_NAME" -Atqc \
    "SELECT count(*) FROM information_schema.tables
     WHERE table_schema='public' AND table_name IN (
       'callcenter_surveys','callcenter_survey_categories','callcenter_survey_questions',
       'callcenter_survey_evaluations','callcenter_survey_answers',
       'callcenter_settings','callcenter_queue_settings',
       'callcenter_queue_notification_recipients','callcenter_audit_logs'
     )" | grep -Fxq '9'
sudo -u postgres psql -d "$DB_NAME" -Atqc \
    "SELECT count(*) FROM astera_schema_migrations
     WHERE version IN (
       '027_autoprovision_devices','028_autoprovision_account_slot',
       '029_autoprovision_templates_and_keys','030_autoprovision_schedule'
     )" | grep -Fxq '4'
sudo -u postgres psql -d "$DB_NAME" -Atqc \
    "SELECT bool_and(has_table_privilege(
       'astera-panel', table_name, 'SELECT,INSERT,UPDATE,DELETE'
     )) FROM (VALUES
       ('autoprovision_devices'),('autoprovision_templates'),
       ('autoprovision_device_keys'),('autoprovision_settings')
     ) AS provisioning_tables(table_name)" | grep -Fxq 't'
test -d /var/lib/astera/provisioning
sudo -u postgres psql -d "$DB_NAME" -Atqc \
    "SELECT count(*) FROM information_schema.tables
     WHERE table_schema='public' AND table_name IN (
       'system_backup_settings','system_backup_archives','system_backup_jobs'
     )" | grep -Fxq '3'
sudo -u postgres psql -d "$DB_NAME" -Atqc \
    "SELECT column_name FROM information_schema.columns
     WHERE table_schema='public' AND table_name='system_backup_settings'
       AND column_name='company_name'" | grep -Fxq 'company_name'
sudo -u postgres psql -d "$DB_NAME" -Atqc \
    "SELECT to_regclass('public.network_static_routes')" \
    | grep -Fxq 'network_static_routes'
sudo -u postgres psql -d "$DB_NAME" -Atqc \
    "SELECT to_regclass('public.feature_takeover_settings')" \
    | grep -Fxq 'feature_takeover_settings'
command -v nmcli >/dev/null
systemctl is-active --quiet NetworkManager
nmcli -t -f TYPE device status | grep -Fxq 'ethernet'
test -x /usr/local/sbin/astera-backup-restore
test -x /usr/local/sbin/astera-backup-dump
test -x /usr/local/sbin/astera-backup-files
systemctl is-active --quiet astera-backup-worker.service
test -x /usr/local/sbin/astera-network-routes
systemctl is-active --quiet astera-network-routes.service
test -x /usr/local/sbin/astera-system-update
curl -kfsS --resolve "$PANEL_DOMAIN:443:127.0.0.1" \
    "https://$PANEL_DOMAIN/webphone.php" >/dev/null
curl -kfsS --resolve "$PANEL_DOMAIN:443:127.0.0.1" \
    "https://$PANEL_DOMAIN/Agent/login.php" \
    -o "$WORK_DIR/agent-login-check.html"
grep -Fq 'name="phone_mode"' "$WORK_DIR/agent-login-check.html"
curl -kfsS --resolve "$PANEL_DOMAIN:443:127.0.0.1" \
    "https://$PANEL_DOMAIN/Supervisor/login.php" \
    -o "$WORK_DIR/supervisor-login-check.html"
grep -Fq 'Supervisor Girişi' "$WORK_DIR/supervisor-login-check.html"
curl -kfsS --resolve "$PANEL_DOMAIN:443:127.0.0.1" \
    "https://$PANEL_DOMAIN/assets/js/app.js" \
    -o "$WORK_DIR/app-js-check.js"
grep -Fq 'pageExtensions()' "$WORK_DIR/app-js-check.js"

old_ip_report="$STATE_DIR/source-ip-references.txt"
grep -RIn --exclude='*.log' --exclude='source-ip-references.txt' \
    -- "$SOURCE_HOST" /etc/asterisk "$TARGET_APP_DIR" >"$old_ip_report" || true

if ((ACTIVATE == 1)); then
    log "Asterisk etkinlestiriliyor"
    systemctl enable asterisk
    if ! systemctl restart asterisk; then
        systemctl status asterisk --no-pager -l || true
        journalctl -u asterisk -n 100 --no-pager || true
        die "Asterisk servisi baslatilamadi."
    fi
    ASTERISK_VERSION_OUTPUT=""
    for _ in {1..20}; do
        ASTERISK_VERSION_OUTPUT="$(asterisk -rx 'core show version' 2>&1 || true)"
        if grep -Eq "^Asterisk ${ASTERISK_SERIES}([. -]|$)" <<<"$ASTERISK_VERSION_OUTPUT"; then
            break
        fi
        sleep 1
    done
    if ! systemctl is-active --quiet asterisk; then
        systemctl status asterisk --no-pager -l || true
        journalctl -u asterisk -n 100 --no-pager || true
        die "Asterisk calisir durumda degil."
    fi
    if ! grep -Eq "^Asterisk ${ASTERISK_SERIES}([. -]|$)" <<<"$ASTERISK_VERSION_OUTPUT"; then
        printf '%s\n' "$ASTERISK_VERSION_OUTPUT" >&2
        systemctl status asterisk --no-pager -l || true
        journalctl -u asterisk -n 100 --no-pager || true
        die "Asterisk cekirdek komutlari yuklenmedi."
    fi
    printf '%s\n' "$ASTERISK_VERSION_OUTPUT"
    PJSIP_STATUS="$(asterisk -rx 'pjsip show transports' 2>&1 || true)"
    HTTP_STATUS="$(asterisk -rx 'http show status' 2>&1 || true)"
    if grep -Fq "No such command" <<<"$PJSIP_STATUS" \
        || grep -Fq "No such command" <<<"$HTTP_STATUS"; then
        printf '%s\n%s\n' "$PJSIP_STATUS" "$HTTP_STATUS" >&2
        systemctl status asterisk --no-pager -l || true
        journalctl -u asterisk -n 100 --no-pager || true
        die "Asterisk PJSIP veya HTTP modulleri yuklenmedi."
    fi
    printf '%s\n%s\n' "$PJSIP_STATUS" "$HTTP_STATUS"

    log "CDR ve ODBC yazma yolu dogrulaniyor"
    CDR_STATUS="$(asterisk -rx 'cdr show status' 2>&1 || true)"
    ODBC_STATUS="$(asterisk -rx 'odbc show' 2>&1 || true)"
    if ! grep -Fq 'Adaptive ODBC' <<<"$CDR_STATUS"; then
        printf '%s\n' "$CDR_STATUS" >&2
        die "Adaptive ODBC CDR arka ucu yuklenmedi."
    fi
    if ! grep -Eq 'Number of active connections:[[:space:]]+[1-9]' <<<"$ODBC_STATUS"; then
        printf '%s\n' "$ODBC_STATUS" >&2
        die "Asterisk PostgreSQL ODBC baglantisi etkin degil."
    fi
    CDR_RELKIND="$(sudo -u postgres psql -d "$DB_NAME" -Atqc \
        "SELECT relkind::text FROM pg_class WHERE oid = to_regclass('public.cdr')")"
    [[ "$CDR_RELKIND" == "p" ]] \
        || die "CDR tablosu aylik partition yapisinda degil (relkind=$CDR_RELKIND)."
    CDR_WRITER_KIND="$(sudo -u postgres psql -d "$DB_NAME" -Atqc \
        "SELECT relkind::text FROM pg_class WHERE oid = to_regclass('public.cdr_asterisk_writer')")"
    [[ "$CDR_WRITER_KIND" == "v" ]] \
        || die "Asterisk CDR yazici gorunumu bulunamadi."
    grep -Eq '^table[[:space:]]*=[[:space:]]*cdr_asterisk_writer[[:space:]]*$' \
        /etc/asterisk/cdr_adaptive_odbc.conf \
        || die "Asterisk CDR hedefi cdr_asterisk_writer degil."
    sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <<'SQL'
BEGIN;
SET LOCAL ROLE asterisk;
INSERT INTO cdr_asterisk_writer (
    calldate, clid, src, dst, dcontext, channel, lastapp, duration,
    billsec, disposition, amaflags, accountcode, uniqueid, linkedid
) VALUES (
    timezone('Europe/Istanbul', now()), '"Kurulum CDR Test" <999>', '999', '998',
    'from-genel', 'PJSIP/999-install-test', 'Dial', 1,
    0, 'NO ANSWER', 3, '', 'astera-install-cdr-test', 'astera-install-cdr-test'
);
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM cdr
        WHERE uniqueid = 'astera-install-cdr-test'
          AND calldate BETWEEN
              timezone('Europe/Istanbul', now()) - interval '1 minute'
              AND timezone('Europe/Istanbul', now()) + interval '1 minute'
    ) THEN
        RAISE EXCEPTION 'Asterisk CDR saat dönüşümü doğrulanamadı';
    END IF;
END;
$$;
ROLLBACK;
SQL
    DEFAULT_CDR_ROWS="$(sudo -u postgres psql -d "$DB_NAME" -Atqc \
        'SELECT astera_cdr_default_partition_rows()')"
    [[ "$DEFAULT_CDR_ROWS" == "0" ]] \
        || die "Varsayilan CDR partition icinde $DEFAULT_CDR_ROWS satir bulundu."

    for helper_script in \
        configure_pbx_ssl.sh \
        configure_webrtc.sh \
        configure_root_ssh.sh; do
        [[ -f "$TARGET_APP_DIR/$helper_script" ]] \
            || die "Kurulum yardımcısı bulunamadı: $TARGET_APP_DIR/$helper_script"
    done

    log "Alan adı ve TLS sertifikası yapılandırılıyor"
    bash "$TARGET_APP_DIR/configure_pbx_ssl.sh" "$PANEL_DOMAIN"

    log "WebRTC yapılandırılıyor"
    PANEL_DOMAIN="$PANEL_DOMAIN" APP_DIR="$TARGET_APP_DIR" \
        bash "$TARGET_APP_DIR/configure_webrtc.sh"

    log "Root SSH erişimi yapılandırılıyor"
    bash "$TARGET_APP_DIR/configure_root_ssh.sh"

    log "Panelin yerel CDR sorgusu dogrulaniyor"
    PANEL_CDR_TOTAL="$(
        sudo -u astera-panel env ASTERA_APP_DIR="$TARGET_APP_DIR" php -r '
            require getenv("ASTERA_APP_DIR") . "/includes/bootstrap.php";
            $ok = false;
            $rows = pbx_odbc_rows("SELECT count(*) AS total FROM cdr", $ok);
            if (!$ok || !isset($rows[0]["total"])) {
                fwrite(STDERR, "Panel CDR sorgusu basarisiz\n");
                exit(1);
            }
            echo (int) $rows[0]["total"];
        '
    )"
    [[ "$PANEL_CDR_TOTAL" =~ ^[0-9]+$ ]] \
        || die "Panel CDR toplam kaydini okuyamadi."
    systemctl start astera-cdr-partitions.service
    echo "CDR kayit sayisi: $PANEL_CDR_TOTAL"
else
    systemctl disable asterisk.service 2>/dev/null || true
    systemctl stop asterisk.service 2>/dev/null || true
fi

cat >"$STATE_DIR/completed" <<STATE
completed_at=$(date -Is)
source=$SOURCE_HOST
target=$TARGET_HOST
database=$DB_NAME
application=$TARGET_APP_DIR
activated=$ACTIVATE
backup=$BACKUP_DIR
STATE
chmod 600 "$STATE_DIR/completed" "$old_ip_report"

log "Kurulum tamamlandi"
echo "Panel/WebPhone: https://$PANEL_DOMAIN/webphone.php"
echo "Hedef IP ile:   https://$TARGET_HOST/webphone.php"
echo "Hedef yedegi:   $BACKUP_DIR"
echo "Eski IP raporu: $old_ip_report"
if ((ACTIVATE == 0)); then
    echo
    echo "Asterisk guvenlik amaciyla BASLATILMADI."
    echo "Kesin geciste kaynak cagrilarini durdurduktan sonra son senkronizasyonu yapin:"
    echo "  sudo PANEL_DOMAIN=$PANEL_DOMAIN bash $0 --force --activate"
else
    echo
    echo "Asterisk, SSL, WebRTC ve root SSH ayarlari tamamlandi."
    echo "DNS/NAT, trunk kayitlari, WSS ve test cagrisini kontrol edin."
fi
