#!/bin/bash

# Production Deployment Script for Ubuntu 24.04 LTS
# Web Hosting Panel Deployment with Security Hardening

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PANEL_USER="hosting-panel"
PANEL_DIR="/var/www/hosting-panel"
DB_NAME="hosting_panel"
DB_USER="panel_user"
WEB_ROOT="/var/www/sites"
DNS_ZONE_PATH="/etc/bind/zones"
LOG_FILE="/var/log/hosting-panel-install.log"

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1" | tee -a "$LOG_FILE"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" | tee -a "$LOG_FILE"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$LOG_FILE"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE"
}

# Check if running as root
if [[ $EUID -eq 0 ]]; then
   print_error "This script should not be run as root for security reasons"
   exit 1
fi

# Check if sudo is available
if ! command -v sudo &> /dev/null; then
    print_error "sudo is required but not installed"
    exit 1
fi

print_status "Starting Hosting Panel deployment for Ubuntu 24.04 LTS"

# Update system
print_status "Updating system packages..."
sudo apt update && sudo apt upgrade -y

# Install required packages
print_status "Installing required packages..."
sudo apt install -y \
    apache2 \
    mysql-server \
    php8.3 \
    php8.3-fpm \
    php8.3-mysql \
    php8.3-curl \
    php8.3-gd \
    php8.3-xml \
    php8.3-mbstring \
    php8.3-zip \
    php8.3-bcmath \
    php8.3-json \
    php8.3-intl \
    bind9 \
    bind9utils \
    bind9-doc \
    certbot \
    python3-certbot-apache \
    fail2ban \
    ufw \
    git \
    curl \
    wget \
    unzip \
    htop \
    iotop \
    rsync \
    logrotate \
    cron

# Enable required Apache modules
print_status "Enabling Apache modules..."
sudo a2enmod rewrite
sudo a2enmod ssl
sudo a2enmod headers
sudo a2enmod proxy
sudo a2enmod proxy_fcgi
sudo a2enmod setenvif
sudo systemctl reload apache2

# Create panel user
print_status "Creating panel user..."
if ! id "$PANEL_USER" &>/dev/null; then
    sudo useradd -r -s /bin/bash -d "$PANEL_DIR" -m "$PANEL_USER"
    print_success "Created user $PANEL_USER"
else
    print_warning "User $PANEL_USER already exists"
fi

# Create directory structure
print_status "Creating directory structure..."
sudo mkdir -p "$PANEL_DIR"
sudo mkdir -p "$WEB_ROOT"
sudo mkdir -p "$DNS_ZONE_PATH"
sudo mkdir -p "/var/log/hosting-panel"
sudo mkdir -p "/etc/hosting-panel"

# Set proper permissions
sudo chown -R "$PANEL_USER:www-data" "$PANEL_DIR"
sudo chown -R "www-data:www-data" "$WEB_ROOT"
sudo chown -R "bind:bind" "$DNS_ZONE_PATH"
sudo chmod 755 "$PANEL_DIR"
sudo chmod 755 "$WEB_ROOT"
sudo chmod 755 "$DNS_ZONE_PATH"

# Generate random database password
DB_PASS=$(openssl rand -base64 32)
ADMIN_PASS=$(openssl rand -base64 16)

print_status "Configuring MySQL..."
# Secure MySQL installation
sudo mysql -e "DELETE FROM mysql.user WHERE User='';"
sudo mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
sudo mysql -e "DROP DATABASE IF EXISTS test;"
sudo mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
sudo mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;"
sudo mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
sudo mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

print_success "Database configured"

# Clone or copy panel files
print_status "Installing panel files..."
if [[ -d "/tmp/hosting-panel" ]]; then
    sudo cp -r /tmp/hosting-panel/* "$PANEL_DIR/"
else
    print_error "Panel files not found in /tmp/hosting-panel"
    print_status "Please copy your panel files to $PANEL_DIR manually"
fi

# Create environment configuration
print_status "Creating configuration files..."
sudo tee "/etc/hosting-panel/.env" > /dev/null <<EOF
# Database Configuration
DB_HOST=localhost
DB_USER=$DB_USER
DB_PASS=$DB_PASS
DB_NAME=$DB_NAME

# Application Configuration
SITE_URL=https://$(hostname -f)
ADMIN_EMAIL=admin@$(hostname -f)
SITE_NAME=Hosting Panel
SITE_DESC=Professional Web Hosting Control Panel

# System Paths
APACHE_VHOST_PATH=/etc/apache2/sites-available
DNS_ZONE_PATH=$DNS_ZONE_PATH
WEB_ROOT=$WEB_ROOT

# SSL Configuration
CERTBOT_BIN=/usr/bin/certbot
APACHE_RELOAD_CMD=systemctl reload apache2
BIND_RELOAD_CMD=systemctl reload named

# Security Settings
SESSION_TIMEOUT=3600
CSRF_TOKEN_LIFETIME=7200
MAX_LOGIN_ATTEMPTS=5
LOGIN_LOCKOUT_TIME=900

# Backup Configuration
BACKUP_PATH=/var/backups/hosting-panel
BACKUP_RETENTION_DAYS=30

# Monitoring
ENABLE_MONITORING=true
STATS_RETENTION_DAYS=90
EOF

sudo chown "$PANEL_USER:$PANEL_USER" "/etc/hosting-panel/.env"
sudo chmod 600 "/etc/hosting-panel/.env"

# Create symlink to panel directory
if [[ ! -L "$PANEL_DIR/.env" ]]; then
    sudo ln -s "/etc/hosting-panel/.env" "$PANEL_DIR/.env"
fi

# Import database schema
print_status "Importing database schema..."
if [[ -f "$PANEL_DIR/database.sql" ]]; then
    sudo mysql "$DB_NAME" < "$PANEL_DIR/database.sql"
fi

if [[ -f "$PANEL_DIR/migrations/dns_templates_migration.sql" ]]; then
    sudo mysql "$DB_NAME" < "$PANEL_DIR/migrations/dns_templates_migration.sql"
fi

# Update admin password
sudo mysql "$DB_NAME" -e "UPDATE users SET password = PASSWORD('$ADMIN_PASS') WHERE username = 'admin';"

print_success "Database schema imported"

# Configure Apache
print_status "Configuring Apache..."
sudo tee "/etc/apache2/sites-available/hosting-panel.conf" > /dev/null <<EOF
<VirtualHost *:80>
    ServerName $(hostname -f)
    DocumentRoot $PANEL_DIR
    
    <Directory $PANEL_DIR>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/hosting-panel-error.log
    CustomLog \${APACHE_LOG_DIR}/hosting-panel-access.log combined
    
    # Redirect to HTTPS
    RewriteEngine on
    RewriteCond %{SERVER_NAME} =$(hostname -f)
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>

<VirtualHost *:443>
    ServerName $(hostname -f)
    DocumentRoot $PANEL_DIR
    
    <Directory $PANEL_DIR>
        AllowOverride All
        Require all granted
        
        # Security headers
        Header always set X-Content-Type-Options nosniff
        Header always set X-Frame-Options DENY
        Header always set X-XSS-Protection "1; mode=block"
        Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
        Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'"
    </Directory>
    
    # PHP-FPM Configuration
    <FilesMatch \\.php$>
        SetHandler "proxy:unix:/var/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>
    
    ErrorLog \${APACHE_LOG_DIR}/hosting-panel-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/hosting-panel-ssl-access.log combined
    
    # SSL will be configured by Certbot
</VirtualHost>
EOF

# Enable site
sudo a2ensite hosting-panel.conf
sudo a2dissite 000-default.conf
sudo systemctl reload apache2

print_success "Apache configured"

# Configure PHP-FPM
print_status "Configuring PHP-FPM..."
sudo tee "/etc/php/8.3/fpm/pool.d/hosting-panel.conf" > /dev/null <<EOF
[hosting-panel]
user = $PANEL_USER
group = www-data
listen = /var/run/php/php8.3-fpm-hosting-panel.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 20
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.process_idle_timeout = 10s
pm.max_requests = 1000

php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
php_admin_value[allow_url_fopen] = Off
php_admin_value[allow_url_include] = Off
php_admin_value[display_errors] = Off
php_admin_value[log_errors] = On
php_admin_value[error_log] = /var/log/hosting-panel/php-error.log
php_admin_value[session.save_path] = /var/lib/php/sessions/hosting-panel

security.limit_extensions = .php
EOF

sudo mkdir -p "/var/lib/php/sessions/hosting-panel"
sudo chown "$PANEL_USER:www-data" "/var/lib/php/sessions/hosting-panel"
sudo chmod 770 "/var/lib/php/sessions/hosting-panel"

sudo systemctl restart php8.3-fpm

print_success "PHP-FPM configured"

# Configure BIND
print_status "Configuring BIND DNS..."
sudo tee -a "/etc/bind/named.conf.local" > /dev/null <<EOF

// Hosting Panel Zone Files
include "/etc/bind/zones.conf";
EOF

sudo touch "/etc/bind/zones.conf"
sudo chown bind:bind "/etc/bind/zones.conf"

# Allow panel to reload BIND
echo "$PANEL_USER ALL=(ALL) NOPASSWD: /bin/systemctl reload named" | sudo tee -a /etc/sudoers.d/hosting-panel

sudo systemctl restart named

print_success "BIND DNS configured"

# Configure Firewall
print_status "Configuring firewall..."
sudo ufw --force reset
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 'Apache Full'
sudo ufw allow 53
sudo ufw --force enable

print_success "Firewall configured"

# Configure Fail2Ban
print_status "Configuring Fail2Ban..."
sudo tee "/etc/fail2ban/jail.local" > /dev/null <<EOF
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5

[apache-auth]
enabled = true

[apache-badbots]
enabled = true

[apache-noscript]
enabled = true

[apache-overflows]
enabled = true

[hosting-panel]
enabled = true
port = http,https
filter = hosting-panel
logpath = /var/log/apache2/hosting-panel-*access.log
maxretry = 3
EOF

sudo tee "/etc/fail2ban/filter.d/hosting-panel.conf" > /dev/null <<EOF
[Definition]
failregex = ^<HOST> .* "POST /login\.php HTTP/.*" 200
            ^<HOST> .* "POST /.*\.php HTTP/.*" 400
ignoreregex =
EOF

sudo systemctl restart fail2ban

print_success "Fail2Ban configured"

# Create systemd services
print_status "Creating systemd services..."

# Panel stats collector
sudo tee "/etc/systemd/system/hosting-panel-stats.service" > /dev/null <<EOF
[Unit]
Description=Hosting Panel Statistics Collector
After=network.target

[Service]
Type=oneshot
User=$PANEL_USER
ExecStart=/usr/bin/php $PANEL_DIR/cli/collect-stats.php
EOF

sudo tee "/etc/systemd/system/hosting-panel-stats.timer" > /dev/null <<EOF
[Unit]
Description=Run hosting panel stats collector every 5 minutes
Requires=hosting-panel-stats.service

[Timer]
OnCalendar=*:0/5
Persistent=true

[Install]
WantedBy=timers.target
EOF

# Backup service
sudo tee "/etc/systemd/system/hosting-panel-backup.service" > /dev/null <<EOF
[Unit]
Description=Hosting Panel Backup Service
After=network.target

[Service]
Type=oneshot
User=$PANEL_USER
ExecStart=/usr/bin/php $PANEL_DIR/cli/backup.php
EOF

sudo tee "/etc/systemd/system/hosting-panel-backup.timer" > /dev/null <<EOF
[Unit]
Description=Run hosting panel backup daily at 2 AM
Requires=hosting-panel-backup.service

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable hosting-panel-stats.timer
sudo systemctl enable hosting-panel-backup.timer
sudo systemctl start hosting-panel-stats.timer
sudo systemctl start hosting-panel-backup.timer

print_success "Systemd services created"

# Create CLI scripts
print_status "Creating CLI management scripts..."
sudo mkdir -p "$PANEL_DIR/cli"

# Stats collector
sudo tee "$PANEL_DIR/cli/collect-stats.php" > /dev/null <<'EOF'
#!/usr/bin/php
<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$stats = getSystemStats();
$cpu = getCPUUsage();

$query = "INSERT INTO system_stats (cpu_usage, memory_usage, disk_usage, load_average, network_rx, network_tx) VALUES (?, ?, ?, ?, ?, ?)";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ddddii", 
    $cpu,
    $stats['memory']['percent'],
    $stats['disk']['percent'],
    $stats['cpu']['load_1min'],
    $stats['network']['rx_bytes'],
    $stats['network']['tx_bytes']
);
mysqli_stmt_execute($stmt);

// Clean old stats (keep 90 days)
mysqli_query($conn, "DELETE FROM system_stats WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");

echo "Stats collected at " . date('Y-m-d H:i:s') . "\n";
EOF

sudo chmod +x "$PANEL_DIR/cli/collect-stats.php"

# Set proper ownership
sudo chown -R "$PANEL_USER:www-data" "$PANEL_DIR"
sudo chmod -R 755 "$PANEL_DIR"
sudo chmod -R 644 "$PANEL_DIR"/*.php
sudo chmod -R 644 "$PANEL_DIR"/*/*.php

# Create .htaccess for security
sudo tee "$PANEL_DIR/.htaccess" > /dev/null <<EOF
# Security configurations
<Files ~ "^\.">
    Order allow,deny
    Deny from all
</Files>

<Files ~ "config\.php$">
    Order allow,deny
    Deny from all
</Files>

<DirectoryMatch "^/.*/migrations/">
    Order allow,deny
    Deny from all
</DirectoryMatch>

# Enable pretty URLs
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"
EOF

print_success "Installation completed successfully!"

print_status "==================================================="
print_success "🎉 HOSTING PANEL INSTALLATION COMPLETE"
print_status "==================================================="
print_status "Panel URL: https://$(hostname -f)"
print_status "Admin Username: admin"
print_status "Admin Password: $ADMIN_PASS"
print_status "Database: $DB_NAME"
print_status "Database User: $DB_USER"
print_status "Database Password: $DB_PASS"
print_status "==================================================="

print_warning "IMPORTANT SECURITY STEPS:"
print_status "1. Change admin password immediately after first login"
print_status "2. Setup SSL certificate with: sudo certbot --apache -d $(hostname -f)"
print_status "3. Review firewall rules: sudo ufw status"
print_status "4. Check fail2ban status: sudo fail2ban-client status"
print_status "5. Monitor logs in: /var/log/hosting-panel/"

print_status "Installation log saved to: $LOG_FILE"
print_success "System is ready for production use!"