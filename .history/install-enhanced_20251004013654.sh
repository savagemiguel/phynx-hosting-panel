#!/bin/bash

# Phynx Hosting Panel Enhanced Installation Script
# Version: 2.0
# Description: Enhanced installer with Ubuntu 22+ checking and custom Phynx deployment
# Author: Phynx Team

set -e  # Exit on any error

# ===============================
# Configuration and Variables
# ===============================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Panel Configuration
PANEL_NAME="phynx"
PANEL_DISPLAY_NAME="Phynx Hosting Panel"
PANEL_VERSION="2.0"
PANEL_DIR="/var/www/$PANEL_NAME"
PMA_DIR="$PANEL_DIR/phynx"
LOG_FILE="/var/log/phynx-install.log"
ENV_FILE="$PANEL_DIR/.env"

# Database Configuration
DB_NAME="phynx_panel"
DB_USER="phynx_user"
PMA_DB_USER="phynx_user"

# Web Server Configuration
APACHE_SITE="/etc/apache2/sites-available/$PANEL_NAME.conf"
NGINX_SITE="/etc/nginx/sites-available/$PANEL_NAME"

# Custom Port Configuration
HTTP_PORT="2087"      # Custom HTTP port for hosting panel
HTTPS_PORT="2083"     # Custom HTTPS port for hosting panel

# DNS and SSL
DNS_ZONE_PATH="/var/lib/bind/zones"
CERTBOT_BIN="/usr/bin/certbot"

# Default values (can be overridden with command line arguments)
WEB_SERVER="apache"  # or nginx
INSTALL_PMA="yes"
INSTALL_BIND="yes"
INSTALL_CSF="no"
PANEL_DOMAIN="panel.$(hostname -f 2>/dev/null || echo 'localhost')"
ADMIN_EMAIL="admin@$(hostname -d 2>/dev/null || echo 'localhost')"
SILENT_MODE="no"

# ===============================
# Helper Functions
# ===============================

print_banner() {
    clear
    echo -e "${BLUE}"
    echo "╔════════════════════════════════════════════════════════════════════════════════╗"
    echo "║                        ${PANEL_DISPLAY_NAME} Installer v${PANEL_VERSION}                        ║"
    echo "║                                                                                ║"
    echo "║  Enhanced installation script with custom Phynx deployment                  ║"
    echo "║  Supports Ubuntu 22.04+ with comprehensive security features                  ║"
    echo "╚════════════════════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}\n"
}

log() { echo -e "${BLUE}[INFO]${NC} $*" | tee -a "$LOG_FILE"; }
ok() { echo -e "${GREEN}[OK]${NC} $*" | tee -a "$LOG_FILE"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $*" | tee -a "$LOG_FILE"; }
err() { echo -e "${RED}[ERROR]${NC} $*" | tee -a "$LOG_FILE"; }
die() { err "$*"; exit 1; }

# Function to check if running as root
require_root() {
    [[ $EUID -eq 0 ]] || die "This script must be run as root. Use: sudo $0"
}

# Enhanced Ubuntu version checking
check_ubuntu_version() {
    log "Checking Ubuntu version and compatibility..."
    
    # Check if lsb_release is available
    if ! command -v lsb_release &> /dev/null; then
        # Fallback to /etc/os-release
        if [[ -f /etc/os-release ]]; then
            source /etc/os-release
            OS_NAME="$NAME"
            OS_VERSION="$VERSION_ID"
        else
            die "Cannot determine OS version. lsb_release and /etc/os-release not available."
        fi
    else
        OS_NAME=$(lsb_release -si)
        OS_VERSION=$(lsb_release -sr)
    fi
    
    # Check if it's Ubuntu
    if [[ "$OS_NAME" != "Ubuntu" ]]; then
        die "This installer only supports Ubuntu. Detected: $OS_NAME"
    fi
    
    # Extract major version number
    MAJOR_VERSION=${OS_VERSION%%.*}
    
    # Check minimum version requirement (Ubuntu 22.04)
    if [[ $MAJOR_VERSION -lt 22 ]]; then
        die "Ubuntu 22.04 or higher is required. Detected: Ubuntu $OS_VERSION"
    fi
    
    # Special check for development versions
    if [[ $MAJOR_VERSION -gt 24 ]]; then
        warn "Ubuntu $OS_VERSION detected. This installer was tested up to Ubuntu 24.04."
        warn "Proceeding anyway, but some packages might not be available."
        read -p "Continue? [y/N]: " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
    
    ok "Ubuntu $OS_VERSION detected and compatible!"
    echo "  OS: $OS_NAME $OS_VERSION" >> "$LOG_FILE"
}

# System update with progress
update_system() {
    log "Updating system package lists and upgrading existing packages..."
    
    export DEBIAN_FRONTEND=noninteractive
    
    # Update package lists
    apt-get update -y || die "Failed to update package lists"
    
    # Upgrade existing packages
    apt-get upgrade -y || warn "Some packages failed to upgrade"
    
    # Install essential tools if not present
    apt-get install -y software-properties-common apt-transport-https ca-certificates curl wget gnupg lsb-release bc
    
    ok "System updated successfully"
}

# Install MySQL server with special handling
install_mysql_server() {
    log "Installing MySQL server..."
    
    # Pre-configure MySQL to avoid interactive prompts
    export DEBIAN_FRONTEND=noninteractive
    
    # Install MySQL packages
    if ! apt-get install -y mysql-server mysql-client; then
        err "Failed to install MySQL packages"
        
        # Try to fix package issues
        log "Attempting to fix MySQL installation..."
        apt-get update
        apt-get install -f
        
        # Try again
        if ! apt-get install -y mysql-server mysql-client; then
            die "Could not install MySQL server. Please check package repositories."
        fi
    fi
    
    # Ensure MySQL service exists
    if ! systemctl list-unit-files | grep -q mysql.service; then
        die "MySQL service not found after installation"
    fi
    
    ok "MySQL server installed successfully"
}

# Install required packages with better error handling
install_core_packages() {
    log "Installing core system packages..."
    
    local CORE_PACKAGES=(
        "php8.3"
        "php8.3-fpm"
        "php8.3-mysql"
        "php8.3-mbstring"
        "php8.3-xml"
        "php8.3-zip"
        "php8.3-curl"
        "php8.3-gd"
        "php8.3-opcache"
        "php8.3-readline"
        "php8.3-soap"
        "php8.3-intl"
        "php8.3-bcmath"
        "php8.3-ssh2"
        "php8.4"
        "php8.4-fpm"
        "php8.4-mysql"
        "php8.4-mbstring"
        "php8.4-xml"
        "php8.4-zip"
        "php8.4-curl"
        "php8.4-gd"
        "php8.4-opcache"
        "php8.4-readline"
        "php8.4-soap"
        "php8.4-intl"
        "php8.4-bcmath"
        "php8.4-ssh2"
        "unzip"
        "git"
        "cron"
        "certbot"
        "ufw"
        "fail2ban"
        "htop"
        "nano"
        "vim"
    )
    
    # Install packages with retry mechanism
    for package in "${CORE_PACKAGES[@]}"; do
        log "Installing $package..."
        if ! apt-get install -y "$package"; then
            warn "$package installation failed, retrying..."
            if ! apt-get install -y "$package"; then
                err "Failed to install $package after retry"
                read -p "Continue without $package? [y/N]: " -n 1 -r
                echo
                [[ $REPLY =~ ^[Yy]$ ]] || die "Installation aborted due to package failure"
            fi
        fi
    done
    
    ok "Core packages installed successfully"
}

# Install web server (Apache or Nginx)
install_web_server() {
    if [[ "$WEB_SERVER" == "nginx" ]]; then
        install_nginx
    else
        install_apache
    fi
}

install_apache() {
    log "Installing and configuring Apache2..."
    
    apt-get install -y apache2 apache2-utils
    
    # Enable required modules
    a2enmod rewrite ssl proxy proxy_fcgi setenvif headers
    
    # Configure custom HTTPS port (port 80 is already default in Apache)
    if ! grep -q "Listen $HTTPS_PORT ssl" /etc/apache2/ports.conf; then
        echo "Listen $HTTPS_PORT ssl" >> /etc/apache2/ports.conf
    fi
    
    # Configure Apache for PHP-FPM
    systemctl enable apache2
    systemctl start apache2
    
    ok "Apache2 installed and configured"
}

install_nginx() {
    log "Installing and configuring Nginx..."
    
    apt-get install -y nginx
    
    systemctl enable nginx
    systemctl start nginx
    
    ok "Nginx installed and configured"
}

# Configure and start MySQL service
configure_mysql_service() {
    log "Configuring MySQL service..."
    
    # Ensure MySQL directories exist with proper permissions
    mkdir -p /var/run/mysqld /var/lib/mysql /var/log/mysql
    chown mysql:mysql /var/run/mysqld /var/lib/mysql /var/log/mysql
    chmod 755 /var/run/mysqld
    
    # Ensure MySQL service is installed and configured
    systemctl stop mysql 2>/dev/null || true
    
    # Start and enable MySQL service
    systemctl enable mysql
    systemctl start mysql
    
    # Wait for MySQL to be ready
    local count=0
    local max_attempts=30
    
    log "Waiting for MySQL to start..."
    while ! mysqladmin ping --silent 2>/dev/null && [ $count -lt $max_attempts ]; do
        sleep 2
        ((count++))
        echo -n "."
    done
    echo ""
    
    if [ $count -eq $max_attempts ]; then
        err "MySQL failed to start after $max_attempts attempts"
        
        # Try to get more information about the failure
        log "MySQL service status:"
        systemctl status mysql --no-pager || true
        
        log "MySQL error log:"
        tail -20 /var/log/mysql/error.log 2>/dev/null || true
        
        # Try to restart MySQL with more debugging
        log "Attempting MySQL restart..."
        systemctl restart mysql
        
        # Wait again
        count=0
        while ! mysqladmin ping --silent 2>/dev/null && [ $count -lt 15 ]; do
            sleep 2
            ((count++))
            echo -n "."
        done
        echo ""
        
        if ! mysqladmin ping --silent 2>/dev/null; then
            warn "MySQL service still not responding. Running troubleshooting..."
            if ! troubleshoot_mysql; then
                die "MySQL service failed to start after troubleshooting. Please check system logs and try manual installation."
            fi
        fi
    fi
    
    ok "MySQL service is running"
}

# MySQL security and configuration
secure_mysql_installation() {
    log "Securing MySQL installation..."
    
    # Set non-interactive mode to prevent any prompts
    export DEBIAN_FRONTEND=noninteractive
    export MYSQL_PWD=""
    
    # Ensure MySQL is running first
    configure_mysql_service
    
    # Generate strong random passwords
    MYSQL_ROOT_PASS=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-25)
    DB_PASS=$(openssl rand -base64 24 | tr -d "=+/" | cut -c1-20)
    PMA_DB_PASS=$(openssl rand -base64 24 | tr -d "=+/" | cut -c1-20)
    
    # Simple and reliable MySQL root password setup
    log "Setting MySQL root password..."
    
    # Try different authentication methods for fresh MySQL installations
    local password_set=false
    
    # Method 1: Try with no password (common on fresh installs)
    if mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$MYSQL_ROOT_PASS';" 2>/dev/null; then
        log "Root password set using direct method (no existing password)"
        password_set=true
    # Method 2: Try with debian-sys-maint credentials
    elif mysql --defaults-file=/etc/mysql/debian.cnf -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$MYSQL_ROOT_PASS';" 2>/dev/null; then
        log "Root password set using debian-sys-maint credentials"
        password_set=true
    # Method 3: Try mysql_secure_installation style approach
    elif mysql -u root -e "SET PASSWORD FOR 'root'@'localhost' = PASSWORD('$MYSQL_ROOT_PASS');" 2>/dev/null; then
        log "Root password set using SET PASSWORD method"
        password_set=true
    # Method 4: Try alternative MySQL 8.0 method
    elif mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS';" 2>/dev/null; then
        log "Root password set using ALTER USER method"
        password_set=true
    else
        warn "Standard methods failed, trying alternative approach..."
        
        # Use dpkg-reconfigure for MySQL (non-interactive)
        echo "mysql-server mysql-server/root_password password $MYSQL_ROOT_PASS" | debconf-set-selections
        echo "mysql-server mysql-server/root_password_again password $MYSQL_ROOT_PASS" | debconf-set-selections
        
        # Restart MySQL and try again
        systemctl restart mysql
        sleep 3
        
        if mysql -u root -p"$MYSQL_ROOT_PASS" -e "SELECT 1;" 2>/dev/null; then
            log "Root password set using dpkg-reconfigure method"
            password_set=true
        fi
    fi
    
    if [ "$password_set" = false ]; then
        warn "Could not set MySQL root password using standard methods"
        log "MySQL will continue with default authentication - you can set password manually later"
        # Generate a random password but don't set it
        MYSQL_ROOT_PASS="NOT_SET_USE_DEFAULT_AUTH"
    fi
    
    # Verify password access
    if [ "$password_set" = true ]; then
        log "Verifying MySQL root access..."
        if mysql -u root -p"$MYSQL_ROOT_PASS" -e "SELECT 1;" 2>/dev/null; then
            ok "MySQL root password verified successfully"
        else
            warn "Password verification failed, but continuing with installation"
        fi
    fi
    # Set up MySQL authentication for operations
    local mysql_auth=""
    if [ "$MYSQL_ROOT_PASS" != "NOT_SET_USE_DEFAULT_AUTH" ]; then
        mysql_auth="-p$MYSQL_ROOT_PASS"
        export MYSQL_PWD="$MYSQL_ROOT_PASS"
    fi
    
    # Secure MySQL installation (remove anonymous users, test database, etc.)
    log "Applying MySQL security settings..."
    mysql -u root $mysql_auth -e "DELETE FROM mysql.user WHERE User='';" 2>/dev/null || warn "Could not remove anonymous users"
    mysql -u root $mysql_auth -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');" 2>/dev/null || warn "Could not remove remote root access"
    mysql -u root $mysql_auth -e "DROP DATABASE IF EXISTS test;" 2>/dev/null || warn "Test database not found"
    mysql -u root $mysql_auth -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';" 2>/dev/null || warn "Could not remove test database privileges"
    mysql -u root $mysql_auth -e "FLUSH PRIVILEGES;" || warn "Could not flush MySQL privileges"
    
    # Create databases and users
    log "Creating panel database and users..."
    mysql -u root $mysql_auth -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || die "Could not create panel database"
    mysql -u root $mysql_auth -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';" || die "Could not create panel database user"
    mysql -u root $mysql_auth -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';" || die "Could not grant privileges to panel user"
    
    # Create database user for custom Phynx if deploying it
    if [[ "$INSTALL_PMA" == "yes" ]]; then
        log "Creating Phynx database manager user..."
        mysql -u root $mysql_auth -e "CREATE USER IF NOT EXISTS '$PMA_DB_USER'@'localhost' IDENTIFIED BY '$PMA_DB_PASS';" || die "Could not create Phynx database user"
        mysql -u root $mysql_auth -e "GRANT ALL PRIVILEGES ON *.* TO '$PMA_DB_USER'@'localhost' WITH GRANT OPTION;" || die "Could not grant privileges to Phynx user"
    fi
    
    mysql -u root $mysql_auth -e "FLUSH PRIVILEGES;" || warn "Could not flush final MySQL privileges"
    
    # Clear password environment variable for security
    unset MYSQL_PWD
    
    # Save credentials securely
    cat > /root/.phynx_credentials << EOF
# Phynx Panel Database Credentials
# Generated on: $(date)
MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASS"
DB_NAME="$DB_NAME"
DB_USER="$DB_USER"
DB_PASSWORD="$DB_PASS"
PMA_DB_USER="$PMA_DB_USER"
PMA_DB_PASSWORD="$PMA_DB_PASS"
EOF
    
    chmod 600 /root/.phynx_credentials
    
    ok "MySQL secured and databases created"
}

# Troubleshoot MySQL installation issues
troubleshoot_mysql() {
    log "Troubleshooting MySQL issues..."
    
    echo -e "${YELLOW}MySQL Service Status:${NC}"
    systemctl status mysql --no-pager || true
    echo ""
    
    echo -e "${YELLOW}MySQL Process Check:${NC}"
    ps aux | grep mysql | grep -v grep || echo "No MySQL processes found"
    echo ""
    
    echo -e "${YELLOW}MySQL Socket File Check:${NC}"
    if [[ -S "/var/run/mysqld/mysqld.sock" ]]; then
        echo "✓ MySQL socket file exists"
        ls -la /var/run/mysqld/mysqld.sock
    else
        echo "✗ MySQL socket file missing"
        echo "Socket directory contents:"
        ls -la /var/run/mysqld/ 2>/dev/null || echo "Socket directory doesn't exist"
    fi
    echo ""
    
    echo -e "${YELLOW}MySQL Configuration Check:${NC}"
    if [[ -f "/etc/mysql/mysql.conf.d/mysqld.cnf" ]]; then
        echo "MySQL configuration file exists"
        grep -E "socket|port|bind-address" /etc/mysql/mysql.conf.d/mysqld.cnf 2>/dev/null || true
    fi
    echo ""
    
    echo -e "${YELLOW}MySQL Error Log (last 20 lines):${NC}"
    tail -20 /var/log/mysql/error.log 2>/dev/null || echo "Error log not found"
    echo ""
    
    echo -e "${YELLOW}Disk Space Check:${NC}"
    df -h /var/lib/mysql 2>/dev/null || df -h /
    echo ""
    
    # Attempt to fix common issues
    echo -e "${YELLOW}Attempting common fixes:${NC}"
    
    # Fix permissions
    chown -R mysql:mysql /var/lib/mysql /var/log/mysql /var/run/mysqld 2>/dev/null || true
    
    # Create socket directory if missing
    if [[ ! -d "/var/run/mysqld" ]]; then
        mkdir -p /var/run/mysqld
        chown mysql:mysql /var/run/mysqld
        echo "✓ Created MySQL socket directory"
    fi
    
    # Try to start MySQL again
    echo "Attempting to restart MySQL service..."
    systemctl stop mysql 2>/dev/null || true
    sleep 3
    systemctl start mysql
    sleep 5
    
    if systemctl is-active --quiet mysql; then
        echo -e "${GREEN}✓ MySQL service restarted successfully${NC}"
        return 0
    else
        echo -e "${RED}✗ MySQL service still not running${NC}"
        return 1
    fi
}

# Install panel files
install_panel_files() {
    log "Installing panel files to $PANEL_DIR..."
    
    # Create panel directory
    mkdir -p "$PANEL_DIR"
    
    # Check if we're running from panel directory
    if [[ -f "index.php" && -d "admin" ]]; then
        log "Copying panel files from current directory..."
        
        # Copy all files except installer
        rsync -av --exclude='install-enhanced.sh' --exclude='.git' --exclude='*.log' . "$PANEL_DIR/"
        
        # Create necessary directories
        mkdir -p "$PANEL_DIR"/{logs,uploads,tmp,backups}
        
        # Set proper ownership and permissions
        chown -R www-data:www-data "$PANEL_DIR"
        find "$PANEL_DIR" -type d -exec chmod 755 {} \;
        find "$PANEL_DIR" -type f -exec chmod 644 {} \;
        
        # Make writable directories
        chmod 775 "$PANEL_DIR"/{logs,uploads,tmp,backups}
        
        ok "Panel files installed successfully"
    else
        die "Panel source files not found. Please run this script from the panel root directory."
    fi
}

# Deploy and configure custom Phynx
deploy_custom_pma() {
    if [[ "$INSTALL_PMA" != "yes" ]]; then
        log "Skipping custom Phynx deployment (disabled)"
        return 0
    fi
    
    log "Deploying custom Phynx..."
    
    if [[ -d "phynx" ]]; then
        # Copy your custom Phynx files
        cp -r phynx "$PMA_DIR"
        
        # Set proper ownership and permissions
        chown -R www-data:www-data "$PMA_DIR"
        find "$PMA_DIR" -type d -exec chmod 755 {} \;
        find "$PMA_DIR" -type f -exec chmod 644 {} \;
        
        # Create necessary directories for Phynx
        mkdir -p "$PMA_DIR"/{tmp,uploads,save,upload}
        chown -R www-data:www-data "$PMA_DIR"/{tmp,uploads,save,upload}
        chmod 777 "$PMA_DIR"/{tmp,uploads,save,upload}
        
        # Configure your custom Phynx if config template exists
        if [[ -f "$PMA_DIR/config.sample.php" ]]; then
            source /root/.phynx_credentials
            
            # Create PMA config from template with database credentials
            sed "s/{{PMA_DB_USER}}/$PMA_DB_USER/g; s/{{PMA_DB_PASSWORD}}/$PMA_DB_PASS/g" \
                "$PMA_DIR/config.sample.php" > "$PMA_DIR/config.inc.php"
            
            chown www-data:www-data "$PMA_DIR/config.inc.php"
            chmod 644 "$PMA_DIR/config.inc.php"
        fi
        
        ok "Custom Phynx deployed at $PMA_DIR"
    else
        warn "Custom Phynx directory 'phynx' not found. Skipping deployment."
        INSTALL_PMA="no"
    fi
}

# Configure PHP for optimal performance
configure_php() {
    log "Configuring PHP for production use..."
        
    # Configure PHP 8.3
    if [[ -f "/etc/php/8.3/fpm/php.ini" ]]; then
        configure_php_ini "8.3"
    fi
    
    # Configure PHP 8.4
    if [[ -f "/etc/php/8.4/fpm/php.ini" ]]; then
        configure_php_ini "8.4"
    fi
    
    # Restart PHP-FPM services
    systemctl restart php8.3-fpm 2>/dev/null || true
    systemctl restart php8.4-fpm 2>/dev/null || true
    systemctl enable php8.3-fpm 2>/dev/null || true
    systemctl enable php8.4-fpm 2>/dev/null || true
    
    ok "PHP configured successfully"
}

configure_php_ini() {
    local PHP_VERSION="$1"
    local PHP_INI="/etc/php/$PHP_VERSION/fpm/php.ini"
    
    log "Configuring PHP $PHP_VERSION..."
    
    # Backup original
    cp "$PHP_INI" "$PHP_INI.backup-$(date +%Y%m%d)"
    
    # Apply optimizations
    sed -i 's/^memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
    sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 100M/' "$PHP_INI"
    sed -i 's/^post_max_size = .*/post_max_size = 100M/' "$PHP_INI"
    sed -i 's/^max_execution_time = .*/max_execution_time = 300/' "$PHP_INI"
    sed -i 's/^max_input_vars = .*/max_input_vars = 3000/' "$PHP_INI"
    sed -i 's/^;date.timezone.*/date.timezone = UTC/' "$PHP_INI"
    sed -i 's/^;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/' "$PHP_INI"
    
    # Security settings
    sed -i 's/^expose_php = .*/expose_php = Off/' "$PHP_INI"
    sed -i 's/^allow_url_fopen = .*/allow_url_fopen = Off/' "$PHP_INI"
    sed -i 's/^;session.cookie_httponly.*/session.cookie_httponly = 1/' "$PHP_INI"
    sed -i 's/^;session.cookie_secure.*/session.cookie_secure = 1/' "$PHP_INI"
}

# Create web server configuration
configure_web_server() {
    if [[ "$WEB_SERVER" == "nginx" ]]; then
        configure_nginx_vhost
    else
        configure_apache_vhost
    fi
}

configure_apache_vhost() {
    log "Creating Apache virtual host configuration for ports 80 and $HTTPS_PORT..."
    
    cat > "$APACHE_SITE" << EOF
<VirtualHost *:80>
    ServerName $PANEL_DOMAIN
    DocumentRoot $PANEL_DIR
    
    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'"
    
    # Main directory
    <Directory $PANEL_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # PHP-FPM configuration
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Custom phpMyAdmin location
    Alias /phynxadmin "$PMA_DIR"
    <Directory "$PMA_DIR">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Deny access to sensitive files
    <Files "config.php">
        Require all denied
    </Files>
    <Files ".env">
        Require all denied
    </Files>
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
    
    # Logging
    ErrorLog \${APACHE_LOG_DIR}/${PANEL_NAME}_error.log
    CustomLog \${APACHE_LOG_DIR}/${PANEL_NAME}_access.log combined
</VirtualHost>

# SSL VirtualHost (will be configured by Certbot)
<IfModule mod_ssl.c>
<VirtualHost *:$HTTPS_PORT>
    ServerName $PANEL_DOMAIN
    DocumentRoot $PANEL_DIR
    
    # SSL Configuration (will be managed by Certbot)
    # SSLEngine on
    # Include /etc/letsencrypt/options-ssl-apache.conf
    
    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'"
    
    # Main directory
    <Directory $PANEL_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # PHP-FPM configuration
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Custom phpMyAdmin location
    Alias /phynxadmin "$PMA_DIR"
    <Directory "$PMA_DIR">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Deny access to sensitive files
    <Files "config.php">
        Require all denied
    </Files>
    <Files ".env">
        Require all denied
    </Files>
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
    
    # Logging
    ErrorLog \${APACHE_LOG_DIR}/${PANEL_NAME}_ssl_error.log
    CustomLog \${APACHE_LOG_DIR}/${PANEL_NAME}_ssl_access.log combined
</VirtualHost>
</IfModule>
EOF

    # Enable site and required modules
    a2ensite "$PANEL_NAME"
    a2dissite 000-default 2>/dev/null || true
    systemctl reload apache2
    
    ok "Apache virtual host configured"
}

configure_nginx_vhost() {
    log "Creating Nginx server block configuration for ports 80 and $HTTPS_PORT..."
    
    cat > "$NGINX_SITE" << EOF
server {
    listen 80;
    server_name $PANEL_DOMAIN;
    root $PANEL_DIR;
    index index.php index.html;
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    # Main location
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    
    # Custom phpMyAdmin
    location /pma {
        alias $PMA_DIR;
        try_files \$uri \$uri/ /pma/index.php?\$query_string;
    }
    
    location ~ /pma/.*\\.php\$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $PMA_DIR\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # PHP processing
    location ~ \\.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\\.php)(/.+)\$;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Deny access to sensitive files
    location ~ /\\.ht { deny all; }
    location ~ /\\.env { deny all; }
    location ~ /config\\.php { deny all; }
    
    # Static files optimization
    location ~* \\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)\$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }
}
EOF

    # Enable site
    ln -sf "$NGINX_SITE" /etc/nginx/sites-enabled/
    rm -f /etc/nginx/sites-enabled/default
    nginx -t && systemctl reload nginx
    
    ok "Nginx server block configured"
}

# Create environment configuration
create_environment_config() {
    log "Creating environment configuration..."
    
    source /root/.phynx_credentials
    
    cat > "$ENV_FILE" << EOF
# Phynx Panel Environment Configuration
# Generated on: $(date)

# Database Configuration
DB_HOST=localhost
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASSWORD=$DB_PASS

# Panel Configuration
PANEL_NAME=$PANEL_DISPLAY_NAME
PANEL_URL=http://$PANEL_DOMAIN
PANEL_DOMAIN=$PANEL_DOMAIN
ADMIN_EMAIL=$ADMIN_EMAIL

# Security
SECRET_KEY=$(openssl rand -base64 64 | tr -d "=+/" | cut -c1-64)
JWT_SECRET=$(openssl rand -base64 32)

# phpMyAdmin Configuration
PMA_ENABLED=$INSTALL_PMA
PMA_PATH=/pma
PMA_DB_USER=$PMA_DB_USER
PMA_DB_PASSWORD=$PMA_DB_PASS

# Paths
PANEL_ROOT=$PANEL_DIR
UPLOADS_PATH=$PANEL_DIR/uploads
LOGS_PATH=$PANEL_DIR/logs
BACKUPS_PATH=$PANEL_DIR/backups

# Server Configuration
WEB_SERVER=$WEB_SERVER
PHP_VERSION=8.1
BIND_ENABLED=$INSTALL_BIND
DNS_ZONE_PATH=$DNS_ZONE_PATH

# Development/Production
APP_ENV=production
DEBUG_MODE=false
LOG_LEVEL=info

# SSL/HTTPS
FORCE_HTTPS=false
SSL_ENABLED=false

# Backup Configuration
AUTO_BACKUP=true
BACKUP_RETENTION_DAYS=30

# Security Features
FAIL2BAN_ENABLED=true
FIREWALL_ENABLED=true
CSF_ENABLED=$INSTALL_CSF
EOF

    chown www-data:www-data "$ENV_FILE"
    chmod 640 "$ENV_FILE"
    
    ok "Environment configuration created"
}

# Install BIND9 for DNS management (optional)
install_bind9() {
    if [[ "$INSTALL_BIND" != "yes" ]]; then
        log "Skipping BIND9 installation (disabled)"
        return 0
    fi
    
    log "Installing BIND9 for DNS management..."
    
    apt-get install -y bind9 bind9utils bind9-doc
    
    # Create zones directory
    mkdir -p "$DNS_ZONE_PATH"
    chown bind:bind "$DNS_ZONE_PATH"
    chmod 755 "$DNS_ZONE_PATH"
    
    # Basic BIND configuration
    cat > /etc/bind/named.conf.local << EOF
//
// Do any local configuration here
//

// Consider adding the 1918 zones here, if they are not used in your
// organization
//include "/etc/bind/zones.rfc1918";

// Phynx Panel managed zones will be included here
include "$DNS_ZONE_PATH/phynx-zones.conf";
EOF

    # Create empty zones configuration
    touch "$DNS_ZONE_PATH/phynx-zones.conf"
    chown bind:bind "$DNS_ZONE_PATH/phynx-zones.conf"
    
    # Enable and start BIND9 service (try both service names for compatibility)
    if systemctl enable named 2>/dev/null; then
        systemctl start named
        log "BIND9 service enabled as 'named'"
    elif systemctl enable bind9 2>/dev/null; then
        systemctl start bind9
        log "BIND9 service enabled as 'bind9'"
    else
        warn "Could not enable BIND9 service automatically"
    fi
    
    ok "BIND9 installed and configured"
}

# Configure firewall with UFW or CSF
configure_firewall() {
    if [[ "$INSTALL_CSF" == "yes" ]]; then
        install_csf_firewall
    else
        configure_ufw_firewall
    fi
}

configure_ufw_firewall() {
    log "Configuring UFW firewall..."
    
    # Reset to defaults
    ufw --force reset
    
    # Set default policies
    ufw default deny incoming
    ufw default allow outgoing
    
    # Allow essential services
    ufw allow ssh
    ufw allow 80/tcp
    ufw allow $HTTPS_PORT/tcp
    
    # Allow DNS if BIND is installed
    if [[ "$INSTALL_BIND" == "yes" ]]; then
        ufw allow 53
    fi
    
    # Enable firewall
    ufw --force enable
    
    ok "UFW firewall configured and enabled"
}

install_csf_firewall() {
    log "Installing and configuring CSF/LFD firewall..."
    
    # Download and install CSF
    cd /tmp
    wget https://github.com/waytotheweb/scripts/raw/refs/heads/main/csf.tgz
    tar -xzf csf.tgz
    cd csf
    sh install.sh
    
    # Basic CSF configuration
    sed -i 's/TESTING = "1"/TESTING = "0"/' /etc/csf/csf.conf
    sed -i "s/TCP_IN = .*/TCP_IN = \"22,53,80,$HTTPS_PORT,993,995\"/" /etc/csf/csf.conf
    sed -i "s/TCP_OUT = .*/TCP_OUT = \"22,25,53,80,110,$HTTPS_PORT,587,993,995\"/" /etc/csf/csf.conf
    
    # Disable UFW if it's enabled
    ufw --force disable 2>/dev/null || true
    
    # Start CSF (handle systemd service issues)
    log "Starting CSF/LFD services..."
    
    # Try to enable services, but don't fail if they can't be enabled
    if ! systemctl enable csf 2>/dev/null; then
        warn "Could not enable csf service automatically - will start manually"
    fi
    if ! systemctl enable lfd 2>/dev/null; then
        warn "Could not enable lfd service automatically - will start manually"
    fi
    
    # Start services
    systemctl start csf 2>/dev/null || warn "CSF service failed to start"
    systemctl start lfd 2>/dev/null || warn "LFD service failed to start"
    
    # Verify CSF is working
    if /usr/sbin/csf -v >/dev/null 2>&1; then
        log "CSF firewall is active and working"
    else
        warn "CSF may not be working properly - check configuration manually"
    fi
    
    cd "$PANEL_DIR"
    ok "CSF/LFD firewall installed and configured"
}

# Configure Fail2Ban for additional security
configure_fail2ban() {
    log "Configuring Fail2Ban for enhanced security..."
    
    cat > /etc/fail2ban/jail.local << EOF
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 3

[sshd]
enabled = true
port = ssh
logpath = %(sshd_log)s
backend = %(sshd_backend)s

[apache-auth]
enabled = true
port = 80,$HTTPS_PORT
logpath = %(apache_error_log)s

[apache-badbots]
enabled = true
port = 80,$HTTPS_PORT
logpath = %(apache_access_log)s
bantime = 86400
maxretry = 1

[apache-noscript]
enabled = true
port = 80,$HTTPS_PORT
logpath = %(apache_access_log)s

[apache-overflows]
enabled = true
port = 80,$HTTPS_PORT
logpath = %(apache_error_log)s
maxretry = 2
EOF

    systemctl restart fail2ban
    systemctl enable fail2ban
    
    ok "Fail2Ban configured and enabled"
}

# Set up cron jobs for panel maintenance
setup_cron_jobs() {
    log "Setting up cron jobs for panel maintenance..."
    
    # Panel scheduler (every minute)
    CRON_LINE="* * * * * www-data cd $PANEL_DIR && php cli/run_cron.php >/dev/null 2>&1"
    
    # Add to crontab if not already present
    if ! crontab -l 2>/dev/null | grep -F "$PANEL_DIR/cli/run_cron.php" >/dev/null; then
        (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -
    fi
    
    # Daily maintenance tasks
    MAINTENANCE_CRON="0 2 * * * root $PANEL_DIR/scripts/daily_maintenance.sh >/dev/null 2>&1"
    if ! crontab -l 2>/dev/null | grep -F "daily_maintenance.sh" >/dev/null; then
        (crontab -l 2>/dev/null; echo "$MAINTENANCE_CRON") | crontab -
    fi
    
    ok "Cron jobs configured"
}

# Import database schema
import_database_schema() {
    log "Importing database schema..."
    
    source /root/.phynx_credentials
    
    # Set up MySQL authentication
    local mysql_auth=""
    if [ "$MYSQL_ROOT_PASSWORD" != "NOT_SET_USE_DEFAULT_AUTH" ]; then
        mysql_auth="-p$MYSQL_ROOT_PASSWORD"
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    fi
    
    if [[ -f "database.sql" ]]; then
        mysql -u root $mysql_auth "$DB_NAME" < database.sql
        ok "Database schema imported from database.sql"
    elif [[ -f "$PANEL_DIR/database.sql" ]]; then
        mysql -u root $mysql_auth "$DB_NAME" < "$PANEL_DIR/database.sql"
        ok "Database schema imported from $PANEL_DIR/database.sql"
    else
        warn "No database.sql file found. You'll need to import the schema manually."
    fi
    
    # Clear password environment variable
    unset MYSQL_PWD
}

# Final system optimization
optimize_system() {
    log "Applying final system optimizations..."
    
    # Update locate database
    updatedb 2>/dev/null || true
    
    # Clear package cache
    apt-get autoremove -y
    apt-get autoclean
    
    # Set proper timezone
    timedatectl set-timezone America/New_York 2>/dev/null || true
    
    # Optimize MySQL for small servers (with error handling)
    if [[ ! -f /etc/mysql/mysql.conf.d/phynx-optimization.cnf ]]; then
        log "Creating MySQL optimization configuration..."
        
        # Create optimization config
        cat > /etc/mysql/mysql.conf.d/phynx-optimization.cnf << EOF
[mysqld]
# Phynx Panel MySQL Optimization
innodb_buffer_pool_size = 128M
innodb_log_file_size = 32M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT
query_cache_type = 1
query_cache_size = 32M
max_connections = 100
thread_cache_size = 8
table_open_cache = 1024
EOF
        
        # Test MySQL restart with optimization config
        log "Testing MySQL with optimization settings..."
        if systemctl restart mysql 2>/dev/null; then
            log "MySQL optimization applied successfully"
        else
            warn "MySQL failed to start with optimization config, reverting..."
            
            # Remove the problematic config file
            rm -f /etc/mysql/mysql.conf.d/phynx-optimization.cnf
            
            # Restart MySQL without optimization
            if systemctl restart mysql 2>/dev/null; then
                warn "MySQL restarted without optimization config"
            else
                err "MySQL failed to restart even without optimization config"
                # Try to start MySQL service anyway for the rest of the installation
                systemctl start mysql 2>/dev/null || warn "Could not start MySQL service"
            fi
        fi
    fi
    
    # Ensure MySQL is running before completing optimization
    if ! systemctl is-active --quiet mysql; then
        warn "MySQL is not running after optimization, attempting to start..."
        systemctl start mysql 2>/dev/null || warn "Could not start MySQL - manual intervention may be required"
    fi
    
    ok "System optimization completed"
}

# Display installation summary
display_installation_summary() {
    source /root/.phynx_credentials
    
    clear
    echo -e "${GREEN}"
    echo "╔════════════════════════════════════════════════════════════════════════════════╗"
    echo "║                    ${PANEL_DISPLAY_NAME} Installation Complete!                    ║"
    echo "╚════════════════════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
    
    echo -e "\n${CYAN}📋 Installation Summary${NC}"
    echo "================================"
    echo -e "Panel URL: ${GREEN}http://$PANEL_DOMAIN${NC}"
    echo -e "Panel Directory: ${BLUE}$PANEL_DIR${NC}"
    
    if [[ "$INSTALL_PMA" == "yes" ]]; then
        echo -e "phpMyAdmin URL: ${GREEN}http://$PANEL_DOMAIN/pma${NC}"
    fi
    
    echo -e "\n${CYAN}🔐 Database Credentials${NC}"
    echo "================================"
    echo -e "MySQL Root Password: ${YELLOW}$MYSQL_ROOT_PASSWORD${NC}"
    echo -e "Panel Database: ${BLUE}$DB_NAME${NC}"
    echo -e "Panel DB User: ${BLUE}$DB_USER${NC}"
    echo -e "Panel DB Password: ${YELLOW}$DB_PASSWORD${NC}"
    
    if [[ "$INSTALL_PMA" == "yes" ]]; then
        echo -e "Phynx DB User: ${BLUE}$PMA_DB_USER${NC}"
        echo -e "Phynx DB Password: ${YELLOW}$PMA_DB_PASSWORD${NC}"
    fi
    
    echo -e "\n${CYAN}⚙️ System Information${NC}"
    echo "================================"
    echo -e "OS: ${GREEN}$(lsb_release -d | cut -f2)${NC}"
    echo -e "Web Server: ${GREEN}$WEB_SERVER${NC}"
    echo -e "PHP Versions: ${GREEN}$(php8.4 -v | head -1 | cut -d' ' -f2), $(php8.2 -v | head -1 | cut -d' ' -f2)${NC}"
    echo -e "MySQL Version: ${GREEN}$(mysql --version | cut -d' ' -f3 | cut -d',' -f1)${NC}"
    
    echo -e "\n${CYAN}🔒 Security Features${NC}"
    echo "================================"
    echo -e "Firewall: ${GREEN}$(if [[ "$INSTALL_CSF" == "yes" ]]; then echo "CSF/LFD"; else echo "UFW"; fi)${NC}"
    echo -e "Fail2Ban: ${GREEN}Enabled${NC}"
    echo -e "SSL Ready: ${YELLOW}Run Certbot to enable HTTPS${NC}"
    
    if [[ "$INSTALL_BIND" == "yes" ]]; then
        echo -e "DNS Server: ${GREEN}BIND9 (Zone path: $DNS_ZONE_PATH)${NC}"
    fi
    
    echo -e "\n${CYAN}📁 Important Paths${NC}"
    echo "================================"
    echo -e "Configuration: ${BLUE}$ENV_FILE${NC}"
    echo -e "Credentials: ${BLUE}/root/.phynx_credentials${NC}"
    echo -e "Logs: ${BLUE}$LOG_FILE${NC}"
    echo -e "Uploads: ${BLUE}$PANEL_DIR/uploads${NC}"
    echo -e "Backups: ${BLUE}$PANEL_DIR/backups${NC}"
    
    echo -e "\n${CYAN}🚀 Next Steps${NC}"
    echo "================================"
    echo "1. Point your domain DNS to this server's IP"
    echo "2. Run: certbot --apache -d $PANEL_DOMAIN (for Apache) or certbot --nginx -d $PANEL_DOMAIN (for Nginx)"
    echo "3. Visit your panel URL to complete the web-based setup"
    echo "4. Change all default passwords immediately"
    echo "5. Review and customize the configuration in $ENV_FILE"
    
    echo -e "\n${CYAN}📊 Service Status${NC}"
    echo "================================"
    
    # Check service status
    services=("mysql" "$WEB_SERVER" "php8.4-fpm" "fail2ban")
    if [[ "$INSTALL_BIND" == "yes" ]]; then
        # Check which BIND9 service name is active
        if systemctl is-active --quiet named 2>/dev/null; then
            services+=("named")
        elif systemctl is-active --quiet bind9 2>/dev/null; then
            services+=("bind9")
        else
            services+=("bind9")  # Default to bind9 for display
        fi
    fi
    if [[ "$INSTALL_CSF" == "yes" ]]; then
        # Check CSF/LFD status (they might not be proper systemd services)
        if systemctl is-active --quiet csf 2>/dev/null; then
            services+=("csf")
        elif /usr/sbin/csf -v >/dev/null 2>&1; then
            services+=("csf-manual")  # CSF is working but not as systemd service
        fi
        
        if systemctl is-active --quiet lfd 2>/dev/null; then
            services+=("lfd")
        elif pgrep lfd >/dev/null 2>&1; then
            services+=("lfd-manual")  # LFD is running but not as systemd service
        fi
    fi
    
    for service in "${services[@]}"; do
        case "$service" in
            "csf-manual")
                echo -e "csf: ${GREEN}✓ Running (manual)${NC}"
                ;;
            "lfd-manual")
                echo -e "lfd: ${GREEN}✓ Running (manual)${NC}"
                ;;
            *)
                if systemctl is-active --quiet "$service"; then
                    echo -e "${service}: ${GREEN}✓ Running${NC}"
                else
                    echo -e "${service}: ${RED}✗ Not running${NC}"
                fi
                ;;
        esac
    done
    
    echo -e "\n${YELLOW}⚠️ Security Reminder${NC}"
    echo "================================"
    echo -e "• ${GREEN}All MySQL passwords have been randomly generated${NC}"
    echo "• Credentials are saved in /root/.phynx_credentials"
    echo "• Change default panel admin password after first login"
    echo "• Keep MySQL root password secure"
    echo "• Review firewall rules for your specific needs"
    echo "• Set up regular backups"
    echo "• Monitor logs regularly"
    
    echo -e "\n${GREEN}Installation completed successfully!${NC}"
    echo -e "For support, visit: ${BLUE}https://phynx.one/support${NC}"
    
    # Save installation summary
    cat > /root/phynx-installation-summary.txt << EOF
Phynx Panel Installation Summary
Generated: $(date)

Panel URL: http://$PANEL_DOMAIN
Panel Directory: $PANEL_DIR
phpMyAdmin: http://$PANEL_DOMAIN/pma (if enabled)

Database Credentials:
- MySQL Root Password: $MYSQL_ROOT_PASSWORD
- Panel Database: $DB_NAME
- Panel DB User: $DB_USER
- Panel DB Password: $DB_PASSWORD
- Phynx DB User: $PMA_DB_USER
- Phynx DB Password: $PMA_DB_PASSWORD

Configuration Files:
- Environment: $ENV_FILE
- Credentials: /root/.phynx_credentials
- Installation Log: $LOG_FILE

To enable HTTPS:
certbot --$WEB_SERVER -d $PANEL_DOMAIN --email $ADMIN_EMAIL --agree-tos --non-interactive

Services Status:
$(for service in "${services[@]}"; do
    if systemctl is-active --quiet "$service"; then
        echo "- $service: Running"
    else
        echo "- $service: Not running"
    fi
done)
EOF
}

# Parse command line arguments
parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --web-server=*)
                WEB_SERVER="${1#*=}"
                shift
                ;;
            --domain=*)
                PANEL_DOMAIN="${1#*=}"
                shift
                ;;
            --email=*)
                ADMIN_EMAIL="${1#*=}"
                shift
                ;;
            --no-pma)
                INSTALL_PMA="no"
                shift
                ;;
            --no-bind)
                INSTALL_BIND="no"
                shift
                ;;
            --csf)
                INSTALL_CSF="yes"
                shift
                ;;
            --help|-h)
                show_help
                exit 0
                ;;
            *)
                warn "Unknown option: $1"
                shift
                ;;
        esac
    done
}

show_help() {
    echo "Phynx Panel Enhanced Installer"
    echo ""
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --web-server=apache|nginx   Choose web server (default: apache)"
    echo "  --domain=example.com        Set panel domain name"
    echo "  --email=admin@example.com   Set admin email address"
    echo "  --http-port=PORT            Set custom HTTP port (default: 2087)"
    echo "  --https-port=PORT           Set custom HTTPS port (default: 2083)"
    echo "  --no-pma                    Skip custom Phynx deployment"
    echo "  --no-bind                   Skip BIND9 DNS server installation"
    echo "  --csf                       Install CSF/LFD instead of UFW firewall"
    echo "  --silent                    Skip interactive prompts (use defaults)"
    echo "  --help, -h                  Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0                                    # Interactive installation with prompts"
    echo "  $0 --web-server=nginx --domain=panel.mydomain.com"
    echo "  $0 --no-pma --csf                   # Skip phpMyAdmin, use CSF firewall"
    echo "  $0 --domain=panel.site.com --email=admin@site.com --http-port=8080"
}

# Parse command line arguments
parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --web-server=*)
                WEB_SERVER="${1#*=}"
                ;;
            --domain=*)
                PANEL_DOMAIN="${1#*=}"
                ;;
            --email=*)
                ADMIN_EMAIL="${1#*=}"
                ;;
            --http-port=*)
                HTTP_PORT="${1#*=}"
                ;;
            --https-port=*)
                HTTPS_PORT="${1#*=}"
                ;;
            --no-pma)
                INSTALL_PMA="no"
                ;;
            --no-bind)
                INSTALL_BIND="no"
                ;;
            --csf)
                INSTALL_CSF="yes"
                ;;
            --silent)
                SILENT_MODE="yes"
                ;;
            --help|-h)
                show_help
                exit 0
                ;;
            *)
                echo "Unknown option: $1"
                show_help
                exit 1
                ;;
        esac
        shift
    done
}

# Interactive prompts for missing configuration
prompt_for_missing_config() {
    echo -e "${CYAN}=== Interactive Configuration Setup ===${NC}"
    echo ""
    
    # Prompt for domain if using default
    local default_domain="panel.$(hostname -f 2>/dev/null || echo 'localhost')"
    if [[ "$PANEL_DOMAIN" == "$default_domain" ]]; then
        echo -e "${YELLOW}Domain Configuration:${NC}"
        echo "Current domain: $PANEL_DOMAIN"
        echo ""
        read -p "Enter your custom domain (or press Enter to use default): " custom_domain
        if [[ -n "$custom_domain" ]]; then
            PANEL_DOMAIN="$custom_domain"
            echo -e "${GREEN}✓${NC} Domain set to: $PANEL_DOMAIN"
        else
            echo -e "${YELLOW}!${NC} Using default domain: $PANEL_DOMAIN"
        fi
        echo ""
    fi
    
    # Prompt for admin email if using default
    local default_email="admin@$(hostname -d 2>/dev/null || echo 'localhost')"
    if [[ "$ADMIN_EMAIL" == "$default_email" ]] || [[ "$ADMIN_EMAIL" == "admin@localhost" ]]; then
        echo -e "${YELLOW}Admin Email Configuration:${NC}"
        echo "Current email: $ADMIN_EMAIL"
        echo ""
        while true; do
            read -p "Enter your admin email address: " admin_email
            if [[ -n "$admin_email" ]]; then
                # Basic email validation
                if [[ "$admin_email" =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
                    ADMIN_EMAIL="$admin_email"
                    echo -e "${GREEN}✓${NC} Admin email set to: $ADMIN_EMAIL"
                    break
                else
                    echo -e "${RED}✗${NC} Invalid email format. Please try again."
                fi
            else
                echo -e "${YELLOW}!${NC} Using default email: $ADMIN_EMAIL"
                break
            fi
        done
        echo ""
    fi
    
    # Prompt for port customization
    echo -e "${YELLOW}Port Configuration:${NC}"
    echo "Current HTTP port: $HTTP_PORT"
    echo "Current HTTPS port: $HTTPS_PORT"
    echo ""
    read -p "Do you want to use different ports? [y/N]: " -n 1 -r change_ports
    echo ""
    if [[ $change_ports =~ ^[Yy]$ ]]; then
        while true; do
            read -p "Enter HTTP port (current: $HTTP_PORT): " new_http_port
            if [[ -n "$new_http_port" ]]; then
                if [[ "$new_http_port" =~ ^[0-9]+$ ]] && [ "$new_http_port" -ge 1024 ] && [ "$new_http_port" -le 65535 ]; then
                    HTTP_PORT="$new_http_port"
                    echo -e "${GREEN}✓${NC} HTTP port set to: $HTTP_PORT"
                    break
                else
                    echo -e "${RED}✗${NC} Invalid port. Please enter a number between 1024-65535."
                fi
            else
                break
            fi
        done
        
        while true; do
            read -p "Enter HTTPS port (current: $HTTPS_PORT): " new_https_port
            if [[ -n "$new_https_port" ]]; then
                if [[ "$new_https_port" =~ ^[0-9]+$ ]] && [ "$new_https_port" -ge 1024 ] && [ "$new_https_port" -le 65535 ]; then
                    HTTPS_PORT="$new_https_port"
                    echo -e "${GREEN}✓${NC} HTTPS port set to: $HTTPS_PORT"
                    break
                else
                    echo -e "${RED}✗${NC} Invalid port. Please enter a number between 1024-65535."
                fi
            else
                break
            fi
        done
        echo ""
    fi
    
    # Web server selection
    echo -e "${YELLOW}Web Server Selection:${NC}"
    echo "Current web server: $WEB_SERVER"
    echo ""
    read -p "Do you want to use Nginx instead of Apache? [y/N]: " -n 1 -r use_nginx
    echo ""
    if [[ $use_nginx =~ ^[Yy]$ ]]; then
        WEB_SERVER="nginx"
        echo -e "${GREEN}✓${NC} Web server set to: Nginx"
    else
        echo -e "${YELLOW}!${NC} Using: Apache (default)"
    fi
    echo ""
    
    # Additional options
    echo -e "${YELLOW}Additional Options:${NC}"
    read -p "Skip Phynx database manager installation? [y/N]: " -n 1 -r skip_pma
    echo ""
    if [[ $skip_pma =~ ^[Yy]$ ]]; then
        INSTALL_PMA="no"
        echo -e "${YELLOW}!${NC} Phynx database manager will be skipped"
    fi
    
    read -p "Use CSF firewall instead of UFW? [y/N]: " -n 1 -r use_csf
    echo ""
    if [[ $use_csf =~ ^[Yy]$ ]]; then
        INSTALL_CSF="yes"
        echo -e "${GREEN}✓${NC} CSF firewall will be used"
    fi
    
    echo -e "${CYAN}=== Configuration Complete ===${NC}"
    echo ""
}

# Display installation summary with access URLs
display_installation_summary() {
    local SERVER_IP=$(hostname -I | awk '{print $1}')
    
    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║              Installation Complete!                ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${CYAN}🎉 Phynx Hosting Panel has been successfully installed!${NC}"
    echo ""
    echo -e "${YELLOW}Access URLs:${NC}"
    echo -e "• ${GREEN}HTTP${NC}:  http://$SERVER_IP"
    echo -e "• ${GREEN}HTTPS${NC}: https://$SERVER_IP:$HTTPS_PORT (after SSL setup)"
    echo ""
    if [[ "$PANEL_DOMAIN" != "panel.$(hostname -f 2>/dev/null || echo 'localhost')" ]]; then
        echo -e "• ${GREEN}Domain HTTP${NC}:  http://$PANEL_DOMAIN"
        echo -e "• ${GREEN}Domain HTTPS${NC}: https://$PANEL_DOMAIN:$HTTPS_PORT (after SSL setup)"
        echo ""
    fi
    echo -e "${YELLOW}Database Access:${NC}"
    if [[ -d "$PMA_DIR" ]]; then
        echo -e "• ${GREEN}Phynx DB Manager${NC}: http://$SERVER_IP/phynx"
    fi
    echo ""
    echo -e "${YELLOW}Default Admin Credentials:${NC}"
    echo -e "• ${GREEN}Username${NC}: admin"
    echo -e "• ${GREEN}Password${NC}: admin123 (please change immediately)"
    echo ""
    echo -e "${YELLOW}Next Steps:${NC}"
    echo -e "1. Change the default admin password"
    echo -e "2. Configure SSL certificate with: certbot --apache -d $PANEL_DOMAIN --http-01-port 80 --https-port $HTTPS_PORT"
    echo -e "3. Review firewall settings"
    echo -e "4. Configure DNS settings if needed"
    echo ""
    echo -e "${CYAN}Log file: $LOG_FILE${NC}"
}

# ===============================
# Main Installation Process
# ===============================

main() {
    # Initialize log file
    touch "$LOG_FILE"
    chmod 644 "$LOG_FILE"
    
    # Show banner
    print_banner
    
    # Parse command line arguments
    parse_arguments "$@"
    
    # Interactive prompts for missing configuration (if not in silent mode)
    if [[ "$SILENT_MODE" != "yes" ]]; then
        prompt_for_missing_config
    fi
    
    # Pre-installation checks
    log "Starting Phynx Panel Enhanced Installation..."
    require_root
    check_ubuntu_version
    
    # Confirm installation
    echo -e "${YELLOW}Installation Configuration:${NC}"
    echo "• Panel Domain: $PANEL_DOMAIN"
    echo "• Admin Email: $ADMIN_EMAIL"
    echo "• Web Server: $WEB_SERVER"
    echo "• HTTP Port: $HTTP_PORT"
    echo "• HTTPS Port: $HTTPS_PORT"
    echo "• Deploy custom Phynx: $INSTALL_PMA"
    echo "• Install BIND9: $INSTALL_BIND"
    echo "• Use CSF Firewall: $INSTALL_CSF"
    echo ""
    
    read -p "Proceed with installation? [Y/n]: " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Nn]$ ]]; then
        echo "Installation cancelled."
        exit 0
    fi
    
    # Start installation process
    log "Beginning installation process..."
    
    # Core system setup
    update_system
    install_core_packages
    install_mysql_server
    install_web_server
    
    # Database setup
    secure_mysql_installation
    
    # Panel installation
    install_panel_files
    deploy_custom_pma
    
    # Configuration
    configure_php
    configure_web_server
    create_environment_config
    
    # Optional components
    install_bind9
    
    # Security setup
    configure_firewall
    configure_fail2ban
    
    # Maintenance setup
    setup_cron_jobs
    import_database_schema
    
    # Final optimization
    optimize_system
    
    # Show results
    display_installation_summary
    
    log "Installation process completed successfully!"
}

# Run main installation
main "$@"