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
SOURCE_SSH_USER="${SOURCE_SSH_USER:-root}"
SOURCE_SSH_PORT="${SOURCE_SSH_PORT:-22}"
SOURCE_SSH_KEY="${SOURCE_SSH_KEY:-}"
SOURCE_APP_DIR="${SOURCE_APP_DIR:-/var/www/astera}"
TARGET_APP_DIR="${TARGET_APP_DIR:-/var/www/astera}"
DB_NAME="${DB_NAME:-asterisk}"
PANEL_DOMAIN="${PANEL_DOMAIN:-santral.astera.com.tr}"
ASTERISK_SERIES="${ASTERISK_SERIES:-22}"
ASTERISK_BUILD_JOBS="${ASTERISK_BUILD_JOBS:-2}"
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
  PANEL_DOMAIN=santral.example.com
  SOURCE_HOST=192.168.181.74 TARGET_HOST=192.168.181.87

Onerilen guvenli ilk calisma:
  sudo SOURCE_SSH_KEY=/root/.ssh/id_ed25519 bash install_new_pbx.sh

Kesin gecis sirasinda:
  1. Eski santralde yeni cagri olusmasini durdurun.
  2. Betigi --force --activate ile yeniden calistirarak son veriyi alin.
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
[[ "$SOURCE_APP_DIR" == /* && "$TARGET_APP_DIR" == /* ]] || die "Uygulama yollari mutlak olmalidir."
[[ "$ASTERISK_BUILD_JOBS" =~ ^[1-8]$ ]] \
    || die "ASTERISK_BUILD_JOBS 1 ile 8 arasinda olmalidir."

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
apt-get update
apt-get install -y --no-install-recommends \
    ca-certificates curl wget gnupg lsb-release openssl rsync openssh-client sshpass \
    build-essential pkg-config git subversion \
    libedit-dev libjansson-dev libxml2-dev libxslt1-dev libsqlite3-dev uuid-dev \
    libssl-dev libncurses5-dev libnewt-dev libcurl4-openssl-dev \
    libspeex-dev libspeexdsp-dev libogg-dev libvorbis-dev libgsm1-dev \
    libopus-dev libsrtp2-dev libspandsp-dev libical-dev libneon27-dev \
    unixodbc unixodbc-dev odbc-postgresql libpq-dev \
    postgresql postgresql-contrib \
    nginx certbot python3-certbot-nginx \
    php-fpm php-cli php-pgsql php-mbstring php-curl php-xml php-zip php-intl php-gd

SSH_OPTIONS=(
    -p "$SOURCE_SSH_PORT"
    -o ConnectTimeout=10
    -o ServerAliveInterval=15
    -o ServerAliveCountMax=4
    -o StrictHostKeyChecking=accept-new
)
SSH_USES_PASSWORD=0
if [[ -n "$SOURCE_SSH_KEY" ]]; then
    [[ -r "$SOURCE_SSH_KEY" ]] || die "SSH anahtari okunamiyor: $SOURCE_SSH_KEY"
    SSH_OPTIONS+=(-i "$SOURCE_SSH_KEY" -o BatchMode=yes)
    SSH_PREFIX=()
elif [[ -n "${SOURCE_SSH_PASSWORD:-}" ]]; then
    export SSHPASS="$SOURCE_SSH_PASSWORD"
    SSH_PREFIX=(sshpass -e)
    SSH_OPTIONS+=(-o BatchMode=no -o NumberOfPasswordPrompts=1)
    SSH_USES_PASSWORD=1
elif [[ -t 0 ]]; then
    read -r -s -p "$SOURCE_SSH_USER@$SOURCE_HOST SSH parolasi: " SSHPASS
    echo
    export SSHPASS
    SSH_PREFIX=(sshpass -e)
    SSH_OPTIONS+=(-o BatchMode=no -o NumberOfPasswordPrompts=1)
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

if ! remote "true" >/dev/null 2>&1; then
    if ((SSH_USES_PASSWORD == 0)) || [[ ! -t 0 ]]; then
        die "Kaynak SSH baglantisi reddedildi. Kullanici, anahtar ve SSH ayarlarini kontrol edin."
    fi
    ssh_verified=0
    for attempt in 2 3; do
        read -r -s -p \
            "SSH dogrulanamadi. Parolayi tekrar girin ($attempt/3): " SSHPASS
        echo
        export SSHPASS
        if remote "true" >/dev/null 2>&1; then
            ssh_verified=1
            break
        fi
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
remote_stream "sudo -n -u postgres pg_dump -Fc --no-owner --no-acl $(printf '%q' "$DB_NAME")" \
    >"$WORK_DIR/database.dump"
[[ -s "$WORK_DIR/database.dump" ]] || die "Kaynak veritabani yedegi bos."
# mktemp creates its directory as root-only. PostgreSQL must be able to
# traverse the directory and read both streamed backup files during restore.
chgrp postgres "$WORK_DIR" "$WORK_DIR/roles.sql" "$WORK_DIR/database.dump"
chmod 0750 "$WORK_DIR"
chmod 0640 "$WORK_DIR/roles.sql" "$WORK_DIR/database.dump"

systemctl enable --now postgresql
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
sudo -u postgres pg_restore --exit-on-error --no-owner --no-acl \
    --dbname="$DB_NAME" "$WORK_DIR/database.dump"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <<SQL
GRANT CONNECT ON DATABASE "$DB_NAME" TO asterisk;
GRANT USAGE ON SCHEMA public TO asterisk;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO asterisk;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO asterisk;
GRANT CONNECT ON DATABASE "$DB_NAME" TO "astera-panel";
GRANT USAGE ON SCHEMA public TO "astera-panel";
GRANT SELECT ON astera_tenants, pbx_config_entities, pbx_config_values,
    crm_customers, crm_customer_phones, softphone_crm_tokens,
    pbx_custom_contexts, pbx_custom_context_steps
    TO "astera-panel";
GRANT UPDATE (last_used_at, updated_at) ON softphone_crm_tokens TO "astera-panel";
SQL

log "Yerel, anahtar tabanli PBX yonetim baglantisi hazirlaniyor"
install -d -m 0750 -o astera-panel -g astera-panel /etc/astera
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
    return 301 https://\$host\$request_uri;
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
After=network-online.target postgresql.service
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
systemctl daemon-reload
systemctl enable "$PHP_FPM_SERVICE" nginx
systemctl restart "$PHP_FPM_SERVICE" nginx
[[ -f /etc/systemd/system/astera-url-trigger.service ]] \
    && systemctl enable --now astera-url-trigger.service
[[ -f /etc/systemd/system/astera-cdr-partitions.timer ]] \
    && systemctl enable --now astera-cdr-partitions.timer

if command -v ufw >/dev/null 2>&1 && ufw status | grep -q '^Status: active'; then
    log "Etkin UFW icin gerekli portlar aciliyor"
    ufw allow 22/tcp
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
sudo -u postgres psql -d "$DB_NAME" -Atqc 'SELECT current_database()' | grep -Fxq "$DB_NAME"
curl -kfsS --resolve "$PANEL_DOMAIN:443:127.0.0.1" \
    "https://$PANEL_DOMAIN/webphone.php" >/dev/null

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
echo "Root SSH ayari: sudo bash configure_root_ssh.sh"
echo "WebRTC ayari:   sudo bash configure_webrtc.sh"
if ((ACTIVATE == 0)); then
    echo
    echo "Asterisk guvenlik amaciyla BASLATILMADI."
    echo "Kesin geciste kaynak cagrilarini durdurduktan sonra son senkronizasyonu yapin:"
    echo "  sudo bash $0 --force --activate"
else
    echo
    echo "Asterisk calisiyor. DNS/NAT, trunk kayitlari, WSS ve test cagrisini kontrol edin."
fi
