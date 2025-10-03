#!/bin/bash

# PHYNX Admin Installer for Ubuntu
# This script automates the installation of the PHYNX Admin tool and its dependencies.

# --- Configuration ---
APP_DIR="/var/www/phynx-admin"
APACHE_USER="www-data"
APACHE_GROUP="www-data"

# --- Color Codes for Output ---
C_RESET='\033[0m'
C_RED='\033[0;31m'
C_GREEN='\033[0;32m'
C_YELLOW='\033[0;33m'
C_BLUE='\033[0;34m'

# --- Helper Functions ---
function print_info {
    echo -e "${C_BLUE}[INFO]${C_RESET} $1"
}

function print_success {
    echo -e "${C_GREEN}[SUCCESS]${C_RESET} $1"
}

function print_warning {
    echo -e "${C_YELLOW}[WARNING]${C_RESET} $1"
}

function print_error {
    echo -e "${C_RED}[ERROR]${C_RESET} $1"
    exit 1
}

# --- Main Script ---

# 1. Check for root privileges
if [ "$EUID" -ne 0 ]; then
    print_error "This script must be run as root. Please use sudo."
fi

print_info "Starting PHYNX Admin installation..."

# 2. Update package lists and install dependencies
print_info "Updating package lists..."
apt-get update -y > /dev/null 2>&1

print_info "Installing dependencies (Apache, MySQL, PHP)..."
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    apache2 \
    mysql-server \
    php \
    libapache2-mod-php \
    php-mysql \
    php-json \
    php-mbstring \
    php-xml \
    git \
    curl > /dev/null 2>&1

if [ $? -ne 0 ]; then
    print_error "Failed to install required packages."
else
    print_success "Dependencies installed successfully."
fi

# 3. Secure MySQL and set root password
print_info "Configuring MySQL..."
read -s -p "Please enter a new password for the MySQL 'root' user: " MYSQL_ROOT_PASSWORD
echo

# Set the root password
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$MYSQL_ROOT_PASSWORD';"

# Remove anonymous users, disallow remote root login, remove test database
mysql -e "DELETE FROM mysql.user WHERE User='';"
mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
mysql -e "DROP DATABASE IF EXISTS test;"
mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
mysql -e "FLUSH PRIVILEGES;"

print_success "MySQL has been configured."

# 4. Create application directory and set permissions
print_info "Setting up application directory at $APP_DIR..."
mkdir -p "$APP_DIR/includes/config"
mkdir -p "$APP_DIR/includes/css"
mkdir -p "$APP_DIR/includes/js"
mkdir -p "$APP_DIR/exports"

if [ ! -d "$APP_DIR" ]; then
    print_error "Failed to create application directory."
fi

# 5. Create application files using heredocs
print_info "Creating application files..."

# --- Create c:\wamp64\www\pma\index.php ---
cp "c:/wamp64/www/pma/index.php" "$APP_DIR/index.php"

# --- Create c:\wamp64\www\pma\config.php ---
cp "c:/wamp64/www/pma/config.php" "$APP_DIR/config.php"

# --- Create c:\wamp64\www\pma\home_view.php ---
cp "c:/wamp64/www/pma/home_view.php" "$APP_DIR/home_view.php"

# --- Create c:\wamp64\www\pma\database_view.php ---
cp "c:/wamp64/www/pma/database_view.php" "$APP_DIR/database_view.php"

# --- Create c:\wamp64\www\pma\table_view.php ---
cp "c:/wamp64/www/pma/table_view.php" "$APP_DIR/table_view.php"

# --- Create c:\wamp64\www\pma\system_stats.php ---
cp "c:/wamp64/www/pma/system_stats.php" "$APP_DIR/system_stats.php"

# --- Create c:\wamp64\www\pma\version_update_logic.php ---
cp "c:/wamp64/www/pma/version_update_logic.php" "$APP_DIR/version_update_logic.php"

# --- Create c:\wamp64\www\pma\includes\js\script.js ---
cp "c:/wamp64/www/pma/includes/js/script.js" "$APP_DIR/includes/js/script.js"

# --- Create placeholder files for missing includes ---

cat <<'EOF' > "$APP_DIR/login.php"
<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['user'])) {
        $_SESSION['db_user'] = $_POST['user'];
        $_SESSION['db_pass'] = $_POST['pass'];
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html><html><head><title>Login</title>
<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;background:#2c3e50;}form{background:#34495e;padding:40px;border-radius:8px;color:white;}input{display:block;width:100%;padding:8px;margin-bottom:10px;border-radius:4px;border:0;box-sizing:border-box;}button{width:100%;padding:10px;background:#f57c00;color:white;border:0;border-radius:4px;cursor:pointer;}</style>
</head><body><form method="POST"><h2>PHYNX Login</h2><input type="text" name="user" placeholder="Username" value="root" required><input type="password" name="pass" placeholder="Password"><button type="submit">Login</button></form></body></html>
EOF

cat <<'EOF' > "$APP_DIR/includes/config/funcs.api.php"
<?php
class functions {
    public static function generateBreadcrumbs() { return '<div class="breadcrumbs">Home</div>'; }
    public static function getServerInfo($conn) {
        return [
            'server_status' => 'Online',
            'server_type' => 'MySQL',
            'ssl_enabled' => 'No',
            'server_version' => $conn->server_info,
            'protocol_version' => $conn->protocol_version
        ];
    }
    public static function getTableStructure($conn, $table) {
        $cols = [];
        $pk = null;
        $res = $conn->query("DESCRIBE `$table`");
        while($row = $res->fetch_assoc()) {
            $cols[] = $row;
            if ($row['Key'] == 'PRI') $pk = $row['Field'];
        }
        return ['columns' => $cols, 'primary_key' => $pk];
    }
    public static function mysqlFunctions() { return ['NOW', 'UUID', 'CURDATE']; }
    public static function updateTableRow($conn, $table, $data, $pk_field, $pk_val) { return false; }
    public static function insertTableRow($conn, $table, $data) { return false; }
    public static function deleteTableRow($conn, $table, $pk_field, $pk_val) { return false; }
}
EOF

cat <<'EOF' > "$APP_DIR/includes/css/styles.css"
/* Basic Placeholder Styles */
:root { --primary-color: #f57c00; --background-color: #2c3e50; --text-color: #ecf0f1; --border-color: #34495e; --header-bg: #34495e; --sidebar-bg: #2c3e50; --success-color: #2ecc71; --warning-color: #f1c40f; --error-color: #e74c3c; --text-muted: #95a5a6; }
body { margin: 0; font-family: sans-serif; background-color: var(--background-color); color: var(--text-color); }
#header { background: var(--header-bg); padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; }
#header .logo { font-size: 1.5em; font-weight: bold; }
#main { display: flex; }
#navigation { width: 250px; background: var(--sidebar-bg); padding: 15px; height: calc(100vh - 50px); overflow-y: auto; }
#content { flex-grow: 1; padding: 20px; }
.content-header { margin-bottom: 20px; }
a { color: var(--primary-color); text-decoration: none; }
.db-tree .db-item a { display: block; padding: 5px; border-radius: 4px; }
.db-tree .db-item a:hover, .db-tree .table-link.selected { background-color: var(--border-color); }
.tabs { display: flex; border-bottom: 2px solid var(--border-color); margin-bottom: 20px; }
.tab { padding: 10px 15px; color: var(--text-muted); }
.tab.active { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 8px 12px; border: 1px solid var(--border-color); text-align: left; }
.data-table thead { background-color: var(--header-bg); }
.btn { background-color: var(--primary-color); color: #000; padding: 10px 15px; border: 0; border-radius: 4px; cursor: pointer; font-weight: bold; }
.btn:hover { background-color: #ff9800; }
.success-message { padding: 15px; background-color: rgba(46, 204, 113, 0.2); border-left: 4px solid var(--success-color); margin-bottom: 15px; }
.error-message { padding: 15px; background-color: rgba(231, 76, 60, 0.2); border-left: 4px solid var(--error-color); margin-bottom: 15px; }
EOF

# Create other placeholder view files
for view in sql_view search_view export_view import_view backup_view restore_view edit_privileges user_accounts_view export_users_view delete_user create_database_view delete_database_view settings_view config_view logs_view php_ini_view logout_view triggers_view foreign_keys_views; do
    echo "<h1>$view - Not Implemented</h1>" > "$APP_DIR/${view}.php"
done

print_success "Application files created."

# 6. Set up the version_check database
print_info "Setting up the 'version_check' database..."

DB_SETUP_SQL="
CREATE DATABASE IF NOT EXISTS version_check;
USE version_check;
CREATE TABLE IF NOT EXISTS versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL,
    product_key VARCHAR(255),
    current_version VARCHAR(20) NOT NULL,
    latest_version VARCHAR(20) NOT NULL,
    download_url TEXT,
    filename VARCHAR(255),
    release_notes TEXT,
    release_date DATE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    update_available BOOLEAN DEFAULT FALSE,
    is_latest BOOLEAN DEFAULT FALSE,
    INDEX idx_product (product_name)
);
-- Insert initial data to prevent errors on first load
INSERT INTO versions (product_name, current_version, latest_version, download_url, release_notes)
VALUES ('PHYNX Admin', '1.0.0', '1.0.0', '', 'Initial setup version.')
ON DUPLICATE KEY UPDATE current_version = '1.0.0';
"

mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "$DB_SETUP_SQL"
if [ $? -ne 0 ]; then
    print_warning "Could not set up the 'version_check' database automatically. Please check MySQL credentials."
else
    print_success "'version_check' database created and populated."
fi

# 7. Configure Apache
print_info "Configuring Apache..."

APACHE_CONF_FILE="/etc/apache2/sites-available/phynx-admin.conf"

cat <<EOF > "$APACHE_CONF_FILE"
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot $APP_DIR
    
    <Directory $APP_DIR>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/phynx-admin_error.log
    CustomLog \${APACHE_LOG_DIR}/phynx-admin_access.log combined
</VirtualHost>
EOF

a2ensite phynx-admin.conf > /dev/null 2>&1
a2enmod rewrite > /dev/null 2>&1

print_success "Apache virtual host created."

# 8. Set final permissions and restart Apache
print_info "Setting final permissions and restarting Apache..."
chown -R "$APACHE_USER":"$APACHE_GROUP" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chmod -R 775 "$APP_DIR/exports" # Make exports directory writable

systemctl restart apache2
if [ $? -ne 0 ]; then
    print_error "Failed to restart Apache. Please check the configuration."
fi

print_success "Apache restarted."

# 9. Final instructions
SERVER_IP=$(curl -s ifconfig.me)
echo
print_success "PHYNX Admin installation is complete!"
echo -e "You can now access it at: ${C_YELLOW}http://$SERVER_IP/${C_RESET}"
echo -e "Or if you have a domain name pointing to this server, use that instead."
echo
print_info "Default Login Details:"
echo -e "  - Username: ${C_YELLOW}root${C_RESET}"
echo -e "  - Password: ${C_YELLOW}(The password you set during installation)${C_RESET}"
echo
print_warning "For security, it is highly recommended to create a dedicated MySQL user for this application instead of using root."

exit 0