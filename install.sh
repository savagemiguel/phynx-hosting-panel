#!/bin/bash

# PHYNX Panel Installer for Ubuntu
# - Requires Ubuntu 22.04+ (or later)
# - Installs Apache2, MariaDB (or MySQL), PHP-FPM (multiple versions), Certbot, Bind9, ModSecurity (CRS), UFW (and optional CSF/LFD)
# - Deploys the panel to /var/www/$PANEL_NAME
# - Creates Apache vhost, .env, runs DB migrations, and configures cron

# ---------------
# Helper functions
# ---------------
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
log(){ echo -e "${BLUE}[INFO]${NC} $*"; }
ok(){ echo -e "${GREEN}[OK]${NC} $*"; }
warn(){ echo -e "${YELLOW}[WARN]${NC} $*"; }
err(){ echo -e "${RED}[ERR]${NC} $*" >&2; }
die(){ err "$*"; exit 1; }

require_root(){ [[ $EUID -eq 0 ]] || die "Run as root (sudo ./install.sh)"; }

check_ubuntu(){
  source /etc/os-release || die "/etc/os-release not found"
  [[ "${ID:-}" == "ubuntu" ]] || die "This installer supports Ubuntu only"
  VERSION_ID_NUM=${VERSION_ID%%.*}
  [[ ${VERSION_ID_NUM} -ge 22 ]] || die "Ubuntu ${VERSION_ID} detected. Require Ubuntu 22 or newer"
  ok "Ubuntu ${VERSION_ID} detected"
}

# -------------
# EXPORT VALUES
# -------------
export PATH=$PATH:/sbin
export DEBIEN_FRONTEND=noninteractive

# ---------------
# Installers
# ---------------
install_apache() {
  log "Installing Apache2..."
  apt-get install -y apache2
  ok "Apache2 installed"
}

# ---------------
# Defaults / Args
# ---------------
APTHOST="apt.phynx.one"
PANEL_NAME="phynx"
PANEL_VERSION="1.0.0"
PANEL_BRANCH="master"
PANEL_REPO="https://github.com/PhynxOne/phynx.git"
PANEL_DIR="/usr/local/phynx"
LOG="/root/${PANEL_NAME}_install-$(date +%m%d%Y%H%M%San).log"
MEMORY_USAGE=$(grep 'MemTotal' /proc/meminfo | tr ' ' '\n' | grep [0-9])
SPINNER="/-\|"
OS="ubuntu"
RELEASE="$(lsb_release -s -r)"
OSCODENAME="$(lsb_release -s -c)"
ARCHITECTURE="$(arch)"
VERBOSE="no"
MULTIPHP=("7.4" "8.0" "8.1" "8.2" "8.3" "8.4" "8.5" "8.6")
PHP_VERSIONS=("${MULTIPHP[@]}" "7.3" "7.4" "8.0" "8.1" "8.2" "8.3" "8.4" "8.5" "8.6")
PHP_FPM_VERSIONS=("${MULTIPHP[@]}" "7.3" "7.4" "8.0" "8.1" "8.2" "8.3")
PHP_VERSION="8.4"
MYSQLDB_VERSION="10.5"
MARIADB_VERSION="11.4"
NODE_VERSION="20"
PHPADMIN_VERSION="1.0.0"
PHPMYADMIN_DIR="/usr/share/phynxadmin"
PHPMYADMIN_CONF="/etc/phynxadmin/apache.conf"
PHPMYADMIN_VHOST_PATH="/etc/apache2/sites-available"
PHPMYADMIN_VHOST_NAME="phynxadmin"
PHPMYADMIN_VHOST_CONF="${PHPMYADMIN_VHOST_PATH}/${PHPMYADMIN_VHOST_NAME}.conf"
PHPMYADMIN_VHOST_FILE="${PHPMYADMIN_VHOST_PATH}/${PHPMYADMIN_VHOST_NAME}.conf"
PHPMYADMIN_VHOST_FILE_PATH="${PHPMYADMIN_VHOST_PATH}/${PHPMYADMIN_VHOST_NAME}.conf"
WEB_ROOT="/var/www/"
APACHE_VHOST_PATH="/etc/apache2/sites-available"
DNS_ZONE_PATH="/etc/bind/zones"
CERTBOT_BIN="/usr/bin/certbot"
APACHE_RELOAD_CMD="systemctl reload apache2"
DOCKER_CLI_PATH="/usr/bin/docker"
DOCKER_TEMPLATES_DIR="${PANEL_DIR}/docker-templates"
DOCKER_STACKS_DIR="${PANEL_DIR}/docker-stacks"

DB_HOST="localhost"
DB_NAME="phynx"
DB_USER="root"
DB_PASS=""
ADMIN_EMAIL="admin@phynx.one"
# PANEL_DOMAIN="panel.local"
INSTALL_CSF="yes"

# DEFINE PACKAGES TO INSTALL (REQUIRED)
PKGS="acl apache2 apache2.2-common apache2-suexec-custom apache2-utils apparmor-utils at awstats bc bind9 bsdmainutils bsdutils clamav-daemon cron curl dnsutils dovecot-imapd dovecot-managesieved dovecot-pop3d dovecot-sieve dovecot-sqlite3 e2fslibs e2fsprogs exim4 exim4-daemon-heavy expect fail2ban flex ftp git idn2 imagemagick ipset jq libapache2-mod-fcgid libapache2-mod-php$PHP_VERSION libapache2-mod-rpaf libonig5 libzip4 lsb-release lsof mariadb-client mariadb-common mariadb-server mc mysql-client mysql-common mysql-server nginx nodejs openssh-server php$PHP_VERSION php$PHP_VERSION-cli php$PHP_VERSION-common php$PHP_VERSION-curl php$PHP_VERSION-fpm php$PHP_VERSION-gd php$PHP_VERSION-intl php$PHP_VERSION-mbstring php$PHP_VERSION-mysql php$PHP_VERSION-opcache php$PHP_VERSION-pear php$PHP_VERSION-readline php$PHP_VERSION-xml php$PHP_VERSION-xmlrpc php$PHP_VERSION-zip php$PHP_VERSION-zlib php$PHP_VERSION-ldap php$PHP_VERSION-mcrypt php$PHP_VERSION-mysqlnd php$PHP_VERSION-mysqli php$PHP_VERSION-pdo php$PHP_VERSION-pdo-mail_plugins postgres postgresql-contrib proftpd-core proftpd-mod-crypto quota rrdtool util-linux spanassassin sysstat unzip vim-common nano vsftpd whois zip zstd bubblewrap restic apt-transport-https ca-certificates curl dirmngr gnupg openssl software-properties-common wget sudo"

usage(){
  cat <<USAGE
Usage: sudo ./install.sh [options]
  -d DB_NAME            Database name (default: hosting_panel)
  -u DB_USER            Database user (default: panel_user)
  -p DB_PASS            Database password (default: panel_password)
  -H DB_HOST            Database host (default: localhost)
  -e ADMIN_EMAIL        Admin email (default: admin@example.com)
  -s PANEL_DOMAIN       Panel domain (default: panel.local)
  -c                    Install CSF/LFD (conflicts with UFW; UFW will be disabled)
  -y                    Non-interactive (assume yes)
USAGE
}

help() {
    echo "USAGE: $0 [OPTIONS]
  -a, --apache
  -pf, --phpfpm
  -mp, --multiphp
  -mysql, --mysql
  -mdb, --mariadb
  -n, nodejs
  -v, version
  -h, --help
  -pg, --postgresql
  -ex, --exim"
}

while getopts ":d:u:p:H:e:s:cy" opt; do
  case $opt in
    d) DB_NAME="$OPTARG";;
    u) DB_USER="$OPTARG";;
    p) DB_PASS="$OPTARG";;
    H) DB_HOST="$OPTARG";;
    e) ADMIN_EMAIL="$OPTARG";;
    s) PANEL_DOMAIN="$OPTARG";;
    c) INSTALL_CSF="yes";;
    y) NONINTERACTIVE="yes";;
    *) usage; exit 1;;
  esac
done

# Define download function with output and check for success
download() {
  wget $1 -q --show-progress --progress=bar:force -O $2
  if [[ $? -ne 0 ]]; then
    die "Download failed: $1"
  fi
}

# Define random password generator
random_password() {
  tr -dc 'A-Za-z0-9_@#%&+=' < /dev/urandom | head -c 16
  echo
}

# Validate user
validate_user() {
  if [[ "$user" =~ ^[[:alnum:]][-|\.|_[:alnum:]]{0,28}[[:alnum:]]$ ]]; then
      if [ -n "$(grep ^$user: /etc/passwd /etc/group)" ]; then
          echo -e "\USER or GROUP already exists. Please choose a new user or delete the user and group to continue."
      else
          return 1
      fi
  else
      echo -e "\nInvalid USER. Please choose a valid USER."
      return 0
  fi
}

# ---------------
# Pre-flight checks
# ---------------
require_root
check_ubuntu

log "Updating apt cache..."
apt-get update -y

log "Installing base packages..."
apt-get install -y software-properties-common curl ca-certificates git unzip lsb-release gnupg

log "Adding PHP PPA (Ondrej)..."
add-apt-repository -y ppa:ondrej/php
apt-get update -y

# ---------------
# Packages install
# ---------------
log "Installing required packages..."
apt-get install -y \
  $PKGS

ok "Core packages installed"

log "Enabling Apache modules..."
a2enmod rewrite ssl headers proxy proxy_fcgi setenvif || true
systemctl restart apache2

log "Configuring ModSecurity and OWASP CRS..."
# Enable ModSecurity engine
if [ -f /etc/modsecurity/modsecurity.conf-recommended ]; then
  cp -n /etc/modsecurity/modsecurity.conf-recommended /etc/modsecurity/modsecurity.conf
  sed -i 's/SecRuleEngine DetectionOnly/SecRuleEngine On/' /etc/modsecurity/modsecurity.conf || true
fi
# Install CRS rules if available
if [ -d /usr/share/modsecurity-crs ]; then
  if [ ! -d /etc/modsecurity/crs ]; then
    ln -s /usr/share/modsecurity-crs /etc/modsecurity/crs || true
  fi
  if [ -f /etc/modsecurity/crs/crs-setup.conf.example ] && [ ! -f /etc/modsecurity/crs/crs-setup.conf ]; then
    cp /etc/modsecurity/crs/crs-setup.conf.example /etc/modsecurity/crs/crs-setup.conf
  fi
  if [ -d /etc/modsecurity/crs/rules ]; then
    echo "IncludeOptional /etc/modsecurity/crs/crs-setup.conf" > /etc/apache2/mods-available/security2.conf
    echo "IncludeOptional /etc/modsecurity/crs/rules/*.conf" >> /etc/apache2/mods-available/security2.conf
  fi
  a2enmod security2 || true
  systemctl reload apache2
fi
ok "ModSecurity configured"

log "Preparing Bind9 zone directory..."
mkdir -p "$DNS_ZONE_PATH"
chown root:bind "$DNS_ZONE_PATH" || true
chmod 775 "$DNS_ZONE_PATH" || true

# ---------------
# Firewall setup
# ---------------
if [ "$INSTALL_CSF" = "yes" ]; then
  warn "Installing CSF/LFD will disable UFW to avoid conflicts."
  log "Installing CSF/LFD..."
  apt-get install -y perl libio-socket-ssl-perl libnet-libidn-perl libcrypt-ssleay-perl libio-socket-inet6-perl libsocket6-perl sendmail iptables
  cd /usr/src
  rm -rf csf* || true
  curl -fsSL https://download.configserver.com/csf.tgz -o csf.tgz
  tar -xzf csf.tgz
  cd csf
  sh install.sh || true
  # Disable UFW if enabled
  ufw disable || true
  # Enable csf/lfd services
  csf -e || true
  systemctl enable lfd --now || true
  ok "CSF/LFD installed and enabled. UFW disabled to avoid conflicts."
else
  log "Configuring UFW firewall..."
  ufw allow OpenSSH || true
  ufw allow 80/tcp || true
  ufw allow 443/tcp || true
  ufw allow 53 || true
  echo "y" | ufw enable || true
  ok "UFW configured"
fi

# ---------------
# Panel deployment
# ---------------
SRC_DIR=$(cd "$(dirname "$0")" && pwd)
log "Deploying panel to $PANEL_DIR ..."
mkdir -p "$PANEL_DIR"
rsync -a --delete --exclude ".git" --exclude "install.sh" "$SRC_DIR/" "$PANEL_DIR/"

log "Creating data directories..."
mkdir -p "$DOCKER_TEMPLATES_DIR" "$DOCKER_STACKS_DIR"
chown -R www-data:www-data "$PANEL_DIR" "$DOCKER_TEMPLATES_DIR" "$DOCKER_STACKS_DIR"
find "$PANEL_DIR" -type d -exec chmod 755 {} +

# ---------------
# Apache vhost for panel
# ---------------
log "Creating Apache vhost for the panel..."
VHOST_FILE="$APACHE_VHOST_PATH/phynx.conf"
cat > "$VHOST_FILE" <<CONF
<VirtualHost *:80>
    ServerName $PANEL_DOMAIN
    DocumentRoot $PANEL_DIR

    <Directory $PANEL_DIR>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  "/var/log/apache2/$PANEL_NAME-error.log"
    CustomLog "/var/log/apache2/$PANEL_NAME-access.log" combined
</VirtualHost>
CONF

a2ensite $PANEL_NAME.conf
a2dissite 000-default.conf || true
systemctl reload apache2
ok "Panel vhost created: http://$PANEL_DOMAIN"

# ---------------
# Database setup
# ---------------
log "Configuring MariaDB and database $DB_NAME ..."
MYSQL_ROOT_CMD=(mysql)
# Create DB and user
${MYSQL_ROOT_CMD[@]} -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
${MYSQL_ROOT_CMD[@]} -e "CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';"
${MYSQL_ROOT_CMD[@]} -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;"

# Run migrations (apply .sql files)
log "Applying SQL migrations..."
shopt -s nullglob
for sql in "$PANEL_DIR"/migrations/*.sql; do
  log "Applying $(basename "$sql")"
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$sql" || warn "Migration $(basename "$sql") reported issues"
done
# Apply baseline if present
if [ -f "$PANEL_DIR/database.sql" ]; then
  log "Applying database.sql"
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$PANEL_DIR/database.sql" || warn "database.sql reported issues"
fi
ok "DB ready"

# ---------------
# .env file
# ---------------
log "Creating .env file..."
ENV_FILE="$PANEL_DIR/.env"
cat > "$ENV_FILE" <<ENV
DB_HOST=$DB_HOST
DB_USER=$DB_USER
DB_PASS=$DB_PASS
DB_NAME=$DB_NAME

SITE_URL=http://$PANEL_DOMAIN
ADMIN_EMAIL=$ADMIN_EMAIL

APACHE_VHOST_PATH=$APACHE_VHOST_PATH
DNS_ZONE_PATH=$DNS_ZONE_PATH
WEB_ROOT=$WEB_ROOT

CERTBOT_BIN=$CERTBOT_BIN
APACHE_RELOAD_CMD=$APACHE_RELOAD_CMD

DOCKER_CLI_PATH=$DOCKER_CLI_PATH
DOCKER_COMPOSE_PATH=
DOCKER_TEMPLATES_DIR=$DOCKER_TEMPLATES_DIR
DOCKER_STACKS_DIR=$DOCKER_STACKS_DIR
COMPOSE_CONVERT_WINDOWS_PATHS=1

SSH_KEYS_BASE=/home
BIND_RNDC_BIN=/usr/sbin/rndc
ENV
chown www-data:www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"
ok ".env created"

# ---------------
# Cron for panel scheduler
# ---------------
log "Installing cron for panel scheduler..."
CRON_LINE="* * * * * php $PANEL_DIR/cli/run_cron.php >/dev/null 2>&1"
( crontab -u root -l 2>/dev/null | grep -v -F "$PANEL_DIR/cli/run_cron.php"; echo "$CRON_LINE" ) | crontab -u root -
ok "Cron installed"

# ---------------
# Certbot hint (optional)
# ---------------
log "If DNS points to this server, obtain a certificate with:"
echo "  $CERTBOT_BIN certonly --apache -d $PANEL_DOMAIN --email $ADMIN_EMAIL --agree-tos --non-interactive"

# ---------------
# Final notes
# ---------------
cat <<SUMMARY
${GREEN}Installation complete.${NC}

Panel URL:  http://$PANEL_DOMAIN
Panel path: $PANEL_DIR
.env path:  $ENV_FILE

Installed components:
- Apache2 with required modules (proxy, proxy_fcgi, ssl, rewrite, headers, setenvif)
- MariaDB server
- PHP-FPM 8.1 and 8.2 with common extensions
- Certbot with Apache plugin
- Bind9 (zones dir: $DNS_ZONE_PATH)
- ModSecurity with CRS (enabled)
- Firewall: ${INSTALL_CSF} == yes -> CSF/LFD enabled and UFW disabled; otherwise UFW enabled for 22/80/443/53

Next steps:
- Point DNS for $PANEL_DOMAIN to this server and run Certbot command to enable HTTPS.
- Log in to the panel and configure:
  * Admin > Server Settings > General Settings (.env overrides)
  * Admin > Web Server Settings > VHost Templates
  * Admin > PHP Settings > PHP Versions (per-domain PHP-FPM sockets)
  * Admin > SSL Automation (Certbot)

System features prepared for panel pages:
- Multiple PHP-FPM versions installed (8.1, 8.2) with sockets under /run/php
- ModSecurity/WAF enabled (security2 + CRS)
- Bind9 zones directory prepared for DNS templates ($DNS_ZONE_PATH)
- Firewall baseline (UFW or CSF/LFD)
- Scheduler cron installed for the panel

SUMMARY