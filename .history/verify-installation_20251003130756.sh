#!/bin/bash

# Phynx Panel Post-Installation Verification Script
# Checks if all components are working correctly after installation

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
PANEL_DIR="/var/www/phynx"
PMA_DIR="$PANEL_DIR/phynx"

print_banner() {
    clear
    echo -e "${BLUE}"
    echo "╔════════════════════════════════════════════════════╗"
    echo "║        Phynx Panel Installation Verification       ║"
    echo "║                                                    ║"
    echo "║   Checking all components after installation       ║"
    echo "╚════════════════════════════════════════════════════╝"
    echo -e "${NC}\n"
}

check_ok() {
    echo -e "${GREEN}✓${NC} $1"
}

check_fail() {
    echo -e "${RED}✗${NC} $1"
}

check_warn() {
    echo -e "${YELLOW}!${NC} $1"
}

print_section() {
    echo -e "\n${BLUE}=== $1 ===${NC}"
}

# Main verification
print_banner

# Check if running as root
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}Please run this script as root: sudo $0${NC}"
    exit 1
fi

print_section "Service Status"

# Check MySQL
if systemctl is-active --quiet mysql; then
    check_ok "MySQL is running"
else
    check_fail "MySQL is not running"
fi

# Check Apache/Nginx
if systemctl is-active --quiet apache2; then
    check_ok "Apache2 is running"
    WEB_SERVER="apache2"
elif systemctl is-active --quiet nginx; then
    check_ok "Nginx is running"
    WEB_SERVER="nginx"
else
    check_fail "No web server is running"
    WEB_SERVER="none"
fi

# Check PHP-FPM services
PHP_SERVICES=("php8.1-fpm" "php8.2-fpm" "php8.3-fpm" "php8.4-fpm")
PHP_RUNNING=false

for service in "${PHP_SERVICES[@]}"; do
    if systemctl is-active --quiet "$service"; then
        VERSION=${service%%-*}
        VERSION=${VERSION#php}
        check_ok "PHP $VERSION FPM is running"
        PHP_RUNNING=true
    fi
done

if [[ "$PHP_RUNNING" == false ]]; then
    check_fail "No PHP-FPM services are running"
fi

# Check Fail2Ban
if systemctl is-active --quiet fail2ban; then
    check_ok "Fail2Ban is running"
else
    check_warn "Fail2Ban is not running"
fi

print_section "File System Checks"

# Check panel directory
if [[ -d "$PANEL_DIR" ]]; then
    check_ok "Panel directory exists: $PANEL_DIR"
else
    check_fail "Panel directory not found: $PANEL_DIR"
    exit 1
fi

# Check panel files
if [[ -f "$PANEL_DIR/index.php" ]]; then
    check_ok "Main panel files found"
else
    check_fail "Main panel files missing"
fi

# Check phpMyAdmin
if [[ -d "$PMA_DIR" ]]; then
    check_ok "Custom phpMyAdmin directory found"
    if [[ -f "$PMA_DIR/index.php" ]]; then
        check_ok "phpMyAdmin files are present"
    else
        check_warn "phpMyAdmin directory exists but files may be missing"
    fi
else
    check_warn "Custom phpMyAdmin not installed"
fi

# Check configuration files
if [[ -f "$PANEL_DIR/.env" ]]; then
    check_ok "Environment configuration found"
else
    check_fail "Environment configuration missing"
fi

if [[ -f "/root/.phynx_credentials" ]]; then
    check_ok "Database credentials file found"
else
    check_fail "Database credentials file missing"
fi

print_section "Permissions Check"

# Check ownership
OWNER=$(stat -c %U "$PANEL_DIR")
if [[ "$OWNER" == "www-data" ]]; then
    check_ok "Panel directory owned by www-data"
else
    check_fail "Panel directory not owned by www-data (current: $OWNER)"
fi

# Check writable directories
WRITABLE_DIRS=("$PANEL_DIR/uploads" "$PANEL_DIR/logs" "$PANEL_DIR/tmp")
for dir in "${WRITABLE_DIRS[@]}"; do
    if [[ -d "$dir" && -w "$dir" ]]; then
        check_ok "$(basename "$dir") directory is writable"
    else
        check_fail "$(basename "$dir") directory is not writable or missing"
    fi
done

print_section "Database Connectivity"

# Test database connection
if [[ -f "/root/.phynx_credentials" ]]; then
    source /root/.phynx_credentials
    
    # Test root connection
    if mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "SELECT 1;" &>/dev/null; then
        check_ok "MySQL root connection successful"
        
        # Test panel database
        if mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "USE $DB_NAME; SELECT 1;" &>/dev/null; then
            check_ok "Panel database '$DB_NAME' accessible"
        else
            check_fail "Panel database '$DB_NAME' not accessible"
        fi
        
        # Test panel user
        if mysql -u "$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1;" &>/dev/null; then
            check_ok "Panel database user '$DB_USER' can connect"
        else
            check_fail "Panel database user '$DB_USER' cannot connect"
        fi
        
    else
        check_fail "Cannot connect to MySQL with root credentials"
    fi
else
    check_fail "Database credentials file not found"
fi

print_section "Web Server Configuration"

# Check web server configuration
if [[ "$WEB_SERVER" == "apache2" ]]; then
    if [[ -f "/etc/apache2/sites-available/phynx.conf" ]]; then
        check_ok "Apache virtual host configuration found"
        if a2ensite phynx &>/dev/null; then
            check_ok "Apache virtual host is enabled"
        else
            check_warn "Apache virtual host may not be enabled"
        fi
    else
        check_fail "Apache virtual host configuration missing"
    fi
elif [[ "$WEB_SERVER" == "nginx" ]]; then
    if [[ -f "/etc/nginx/sites-available/phynx" ]]; then
        check_ok "Nginx server block configuration found"
        if [[ -L "/etc/nginx/sites-enabled/phynx" ]]; then
            check_ok "Nginx server block is enabled"
        else
            check_fail "Nginx server block is not enabled"
        fi
    else
        check_fail "Nginx server block configuration missing"
    fi
fi

print_section "Network Accessibility"

# Check if web server is listening on port 80
if netstat -tuln 2>/dev/null | grep -q ":80 "; then
    check_ok "Web server listening on port 80"
else
    check_fail "Web server not listening on port 80"
fi

# Test local HTTP access
if curl -s -o /dev/null -w "%{http_code}" "http://localhost" | grep -q "200\|301\|302"; then
    check_ok "Local HTTP access working"
else
    check_warn "Local HTTP access may have issues"
fi

# Check firewall status
if command -v ufw &>/dev/null && ufw status | grep -q "Status: active"; then
    check_ok "UFW firewall is active"
    if ufw status | grep -q "80/tcp"; then
        check_ok "HTTP port allowed through firewall"
    else
        check_warn "HTTP port may not be allowed through firewall"
    fi
elif command -v csf &>/dev/null && csf -l &>/dev/null; then
    check_ok "CSF firewall is active"
else
    check_warn "No active firewall detected"
fi

print_section "SSL/HTTPS Setup"

# Check if SSL certificates exist
if [[ -d "/etc/letsencrypt/live" ]]; then
    CERT_DOMAINS=$(find /etc/letsencrypt/live -maxdepth 1 -type d ! -name "live" -exec basename {} \;)
    if [[ -n "$CERT_DOMAINS" ]]; then
        check_ok "SSL certificates found for: $CERT_DOMAINS"
    else
        check_warn "Let's Encrypt directory exists but no certificates found"
    fi
else
    check_warn "No SSL certificates found (run certbot to enable HTTPS)"
fi

print_section "PHP Configuration"

# Check PHP versions and modules
PHP_VERSIONS=("8.1" "8.2" "8.3" "8.4")
for version in "${PHP_VERSIONS[@]}"; do
    if command -v "php$version" &> /dev/null; then
        PHP_VERSION=$(php$version -v | head -1 | cut -d' ' -f2)
        check_ok "PHP $version installed: $PHP_VERSION"
        
        # Check required PHP modules for this version
        REQUIRED_MODULES=("mysql" "mbstring" "xml" "zip" "curl" "gd" "json")
        for module in "${REQUIRED_MODULES[@]}"; do
                check_ok "PHP $version module '$module' loaded"
            else
                check_warn "PHP $version module '$module' not loaded"
            fi
        done
    fi
done

print_section "System Resources"

# Check disk space
DISK_USAGE=$(df / | awk 'NR==2 {print int($5)}')
if [[ $DISK_USAGE -lt 80 ]]; then
    check_ok "Disk usage: ${DISK_USAGE}% (healthy)"
elif [[ $DISK_USAGE -lt 90 ]]; then
    check_warn "Disk usage: ${DISK_USAGE}% (monitor closely)"
else
    check_fail "Disk usage: ${DISK_USAGE}% (critically high)"
fi

# Check memory usage
MEMORY_USAGE=$(free | awk 'NR==2{printf "%.0f", $3*100/$2}')
if [[ $MEMORY_USAGE -lt 80 ]]; then
    check_ok "Memory usage: ${MEMORY_USAGE}% (healthy)"
elif [[ $MEMORY_USAGE -lt 90 ]]; then
    check_warn "Memory usage: ${MEMORY_USAGE}% (monitor closely)"
else
    check_fail "Memory usage: ${MEMORY_USAGE}% (critically high)"
fi

print_section "Quick Fixes for Common Issues"

echo -e "\nIf you encountered any ${RED}failures${NC} above, try these fixes:"
echo ""
echo "🔧 Service Issues:"
echo "  sudo systemctl restart mysql apache2 php8.1-fpm"
echo ""
echo "🔧 Permission Issues:"
echo "  sudo chown -R www-data:www-data $PANEL_DIR"
echo "  sudo chmod 775 $PANEL_DIR/{uploads,logs,tmp}"
echo ""
echo "🔧 Database Issues:"
echo "  sudo mysql_secure_installation"
echo "  # Then re-import database schema"
echo ""
echo "🔧 Web Server Issues:"
echo "  # For Apache:"
echo "  sudo a2ensite phynx && sudo systemctl reload apache2"
echo "  # For Nginx:"
echo "  sudo ln -sf /etc/nginx/sites-available/phynx /etc/nginx/sites-enabled/"
echo "  sudo nginx -t && sudo systemctl reload nginx"
echo ""
echo "🔧 SSL Setup:"
echo "  sudo certbot --apache -d your-domain.com  # For Apache"
echo "  sudo certbot --nginx -d your-domain.com   # For Nginx"

print_section "Access Information"

echo -e "\nIf everything looks good, access your panel at:"

# Get server IP
SERVER_IP=$(hostname -I | awk '{print $1}')
echo -e "• ${GREEN}http://$SERVER_IP${NC} (local IP)"

# Check if domain is configured
if [[ -f "$PANEL_DIR/.env" ]] && grep -q "PANEL_DOMAIN" "$PANEL_DIR/.env"; then
    PANEL_DOMAIN=$(grep "PANEL_DOMAIN" "$PANEL_DIR/.env" | cut -d'=' -f2)
    echo -e "• ${GREEN}http://$PANEL_DOMAIN${NC} (configured domain)"
fi

# phpMyAdmin access
if [[ -d "$PMA_DIR" ]]; then
    echo -e "• ${GREEN}http://$SERVER_IP/phynx${NC} (Phynx Database Manager)"
fi

echo -e "\n${BLUE}Next Steps:${NC}"
echo "1. Complete the web-based setup at your panel URL"
echo "2. Change all default passwords"
echo "3. Configure SSL certificates for HTTPS"
echo "4. Set up DNS records for your domain"
echo "5. Review and customize panel settings"

echo -e "\n${YELLOW}Important Files:${NC}"
echo "• Configuration: $PANEL_DIR/.env"
echo "• Database credentials: /root/.phynx_credentials"
echo "• Installation log: /var/log/phynx-install.log"

echo -e "\nVerification complete!"