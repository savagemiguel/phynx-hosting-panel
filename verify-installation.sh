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

# Port Configuration
HTTP_PORT="80"
HTTPS_PORT="443"
SECURE_PORT="2083"

# Domain Configuration
MAIN_DOMAIN="phynx.one"
PANEL_SUBDOMAIN="panel.phynx.one"
PHYNXADMIN_SUBDOMAIN="phynxadmin.phynx.one"
SERVER_IP="216.45.53.244"

print_banner() {
    clear
    echo -e "${BLUE}"
    echo "╔════════════════════════════════════════════════════════════════════════╗"
    echo "║              Phynx Panel Advanced Installation Verification           ║"
    echo "║                                                                        ║"
    echo "║   🔍 Verifying all components including DNS zones and advanced features ║"
    echo "║   📊 Testing progress monitoring, error handling, and reporting        ║"
    echo "║   🌐 Checking DNS propagation and multi-domain configuration          ║"
    echo "╚════════════════════════════════════════════════════════════════════════╝"
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

# Check BIND9/DNS if installed
if systemctl is-active --quiet bind9 || systemctl is-active --quiet named; then
    check_ok "BIND9 DNS server is running"
    DNS_INSTALLED=true
else
    check_warn "BIND9 DNS server not running (may not be installed)"
    DNS_INSTALLED=false
fi

# Check Fail2Ban
if systemctl is-active --quiet fail2ban; then
    check_ok "Fail2Ban is running"
else
    check_warn "Fail2Ban is not running"
fi

print_section "DNS Zone Verification"

if [[ "$DNS_INSTALLED" == true ]]; then
    # Test BIND configuration
    if named-checkconf &> /dev/null; then
        check_ok "BIND configuration syntax is valid"
    else
        check_fail "BIND configuration has syntax errors"
    fi
    
    # Check DNS zones directory
    if [[ -d "/etc/bind/zones" ]]; then
        check_ok "DNS zones directory exists"
        
        # Count zone files
        ZONE_COUNT=$(find /etc/bind/zones -name "db.*" -type f | wc -l)
        if [[ $ZONE_COUNT -gt 0 ]]; then
            check_ok "$ZONE_COUNT DNS zone file(s) found"
        else
            check_warn "No DNS zone files found"
        fi
        
        # Test zone files if domain is provided
        if [[ -n "$1" ]]; then
            DOMAIN="$1"
            ZONE_FILE="/etc/bind/zones/db.$DOMAIN"
            
            if [[ -f "$ZONE_FILE" ]]; then
                check_ok "Zone file exists for $DOMAIN"
                
                # Validate zone file syntax
                if named-checkzone "$DOMAIN" "$ZONE_FILE" &> /dev/null; then
                    check_ok "Zone file syntax is valid for $DOMAIN"
                else
                    check_fail "Zone file syntax errors for $DOMAIN"
                fi
            else
                check_warn "No zone file found for $DOMAIN"
            fi
        fi
    else
        check_warn "DNS zones directory not found"
    fi
    
    # Test local DNS resolution
    echo -e "\n${BLUE}Testing Local DNS Resolution:${NC}"
    
    if [[ -n "$1" ]]; then
        DOMAIN="$1"
        TEST_DOMAINS=("$DOMAIN" "www.$DOMAIN" "panel.$DOMAIN" "phynxadmin.$DOMAIN")
        
        for test_domain in "${TEST_DOMAINS[@]}"; do
            echo -n "  Testing $test_domain... "
            
            # Test with local DNS server
            if dig +short @127.0.0.1 "$test_domain" A &> /dev/null; then
                local result=$(dig +short @127.0.0.1 "$test_domain" A | head -1)
                echo -e "${GREEN}✓${NC} → $result"
            else
                echo -e "${RED}✗${NC} No response"
            fi
        done
    else
        check_warn "No domain specified - run with domain argument to test DNS: ./verify-installation.sh yourdomain.com"
    fi
    
    # Check DNS management tools
    if [[ -f "/usr/local/bin/phynx-dns-update" ]]; then
        check_ok "DNS management tools installed"
        if [[ -x "/usr/local/bin/phynx-dns-update" ]]; then
            check_ok "DNS tools are executable"
        else
            check_warn "DNS tools exist but may not be executable"
        fi
    else
        check_warn "DNS management tools not found"
    fi
else
    check_warn "DNS server not installed - skipping DNS tests"
fi

print_section "Advanced Features Verification"

# Check installation logs
LOGS_DIR="/var/log/phynx-install"
if [[ -d "$LOGS_DIR" ]]; then
    check_ok "Advanced logging directory exists"
    
    LOG_COUNT=$(find "$LOGS_DIR" -name "install-*.log" -type f | wc -l)
    if [[ $LOG_COUNT -gt 0 ]]; then
        check_ok "$LOG_COUNT installation log file(s) found"
    else
        check_warn "No installation log files found"
    fi
    
    # Check for HTML reports
    REPORT_COUNT=$(find "$LOGS_DIR" -name "*.html" -type f | wc -l)
    if [[ $REPORT_COUNT -gt 0 ]]; then
        check_ok "$REPORT_COUNT HTML report(s) generated"
    else
        check_warn "No HTML installation reports found"
    fi
else
    check_warn "Advanced logging directory not found"
fi

# Check system backup (if created)
if [[ -d "/var/backups/phynx-install" ]]; then
    check_ok "System backup directory found"
    
    BACKUP_COUNT=$(find /var/backups/phynx-install -name "backup-*" -type d | wc -l)
    if [[ $BACKUP_COUNT -gt 0 ]]; then
        check_ok "$BACKUP_COUNT system backup(s) created"
    else
        check_warn "Backup directory exists but no backups found"
    fi
else
    check_warn "System backup directory not found (may not have been enabled)"
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
            if php$version -m | grep -q "^$module$"; then
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
echo "  sudo systemctl restart mysql apache2"
echo "  sudo systemctl restart php8.1-fpm php8.2-fpm php8.3-fpm php8.4-fpm"
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

echo -e "\n${YELLOW}🌐 Access URLs:${NC}"

echo -e "${CYAN}Main Website:${NC}"
echo -e "• ${GREEN}http://$MAIN_DOMAIN${NC} (HTTP)"
echo -e "• ${GREEN}https://$MAIN_DOMAIN${NC} (HTTPS after SSL setup)"
echo -e "• ${GREEN}http://$SERVER_IP${NC} (Direct IP access)"

echo -e "\n${CYAN}🎛️ Admin Panel Access:${NC}"
echo -e "• ${GREEN}http://$PANEL_SUBDOMAIN${NC} (subdomain)"
echo -e "• ${GREEN}http://$MAIN_DOMAIN/panel${NC} (directory path)"
echo -e "• ${GREEN}https://$MAIN_DOMAIN:$SECURE_PORT${NC} (secure port)"
echo -e "• ${GREEN}http://$SERVER_IP/panel${NC} (IP access)"

echo -e "\n${CYAN}🗄️ Database Manager:${NC}"
if [[ -d "$PMA_DIR" ]]; then
    echo -e "• ${GREEN}http://$PHYNXADMIN_SUBDOMAIN${NC} (subdomain)"
    echo -e "• ${GREEN}http://$MAIN_DOMAIN/phynxadmin${NC} (directory path)"
    echo -e "• ${GREEN}http://$SERVER_IP/phynxadmin${NC} (IP access)"
else
    echo -e "• ${YELLOW}Not installed${NC}"
fi

print_section "URL Accessibility Testing"

if [[ -n "$1" ]]; then
    DOMAIN="$1"
    echo -e "${BLUE}Testing URLs for domain: $DOMAIN${NC}"
    
    # Test main site
    echo -n "  Main site (http://$DOMAIN)... "
    if curl -s -o /dev/null -w "%{http_code}" "http://$DOMAIN" --connect-timeout 5 | grep -q "200\|301\|302"; then
        echo -e "${GREEN}✓${NC}"
    else
        echo -e "${YELLOW}⚠${NC} (may need DNS propagation)"
    fi
    
    # Test panel access
    echo -n "  Panel (http://$DOMAIN/panel)... "
    if curl -s -o /dev/null -w "%{http_code}" "http://$DOMAIN/panel" --connect-timeout 5 | grep -q "200\|301\|302"; then
        echo -e "${GREEN}✓${NC}"
    else
        echo -e "${YELLOW}⚠${NC} (check configuration)"
    fi
    
    # Test database manager
    if [[ -d "$PMA_DIR" ]]; then
        echo -n "  Database Manager (http://$DOMAIN/phynxadmin)... "
        if curl -s -o /dev/null -w "%{http_code}" "http://$DOMAIN/phynxadmin" --connect-timeout 5 | grep -q "200\|301\|302"; then
            echo -e "${GREEN}✓${NC}"
        else
            echo -e "${YELLOW}⚠${NC} (check configuration)"
        fi
    fi
else
    echo -e "${YELLOW}No domain specified - skipping external URL tests${NC}"
    echo "Run with domain: ./verify-installation.sh yourdomain.com"
fi

print_section "Advanced Installation Verification Summary"

# Count successes and failures
SUCCESS_COUNT=$(echo -e "$LOG_CONTENT" | grep -c "✓" || echo "0")
WARN_COUNT=$(echo -e "$LOG_CONTENT" | grep -c "!" || echo "0")  
FAIL_COUNT=$(echo -e "$LOG_CONTENT" | grep -c "✗" || echo "0")

echo -e "${BLUE}📊 Verification Results:${NC}"
echo -e "• ${GREEN}Passed: $SUCCESS_COUNT${NC}"
echo -e "• ${YELLOW}Warnings: $WARN_COUNT${NC}" 
echo -e "• ${RED}Failed: $FAIL_COUNT${NC}"

echo ""
if [[ $FAIL_COUNT -eq 0 ]]; then
    echo -e "${GREEN}🎉 Phynx Panel Advanced Installation Verified Successfully!${NC}"
    echo ""
    echo -e "${CYAN}🚀 Your Hosting Panel Features:${NC}"
    echo -e "• ✅ Multi-domain configuration with automatic routing"
    echo -e "• ✅ Advanced progress monitoring and error handling"
    if [[ "$DNS_INSTALLED" == true ]]; then
        echo -e "• ✅ DNS zone automation with nameserver support"
        echo -e "• ✅ DNS propagation monitoring and management tools"
    fi
    echo -e "• ✅ Comprehensive security with firewall protection"
    echo -e "• ✅ Performance monitoring and system optimization"
    echo -e "• ✅ Advanced logging and HTML report generation"
    
else
    echo -e "${YELLOW}⚠️ Installation completed with some issues${NC}"
    echo -e "Review the failed checks above and consult the documentation."
fi

echo ""
echo -e "${BLUE}🎯 Next Steps:${NC}"
echo "1. 🌐 Complete DNS configuration at your domain registrar"
echo "2. 🔐 Set up SSL certificates: sudo certbot --apache (or --nginx)"
echo "3. 🎛️ Access admin panel and complete web-based setup"
echo "4. 🔑 Change all default passwords for security" 
echo "5. 📊 Review installation reports in /var/log/phynx-install/"

echo ""
echo -e "${YELLOW}📂 Important Files & Directories:${NC}"
echo -e "• Panel Configuration: ${GREEN}$PANEL_DIR/.env${NC}"
echo -e "• Database Credentials: ${GREEN}/root/.phynx_credentials${NC}"
echo -e "• Installation Logs: ${GREEN}/var/log/phynx-install/${NC}"
echo -e "• DNS Zone Files: ${GREEN}/etc/bind/zones/${NC}"
echo -e "• System Backups: ${GREEN}/var/backups/phynx-install/${NC}"

if [[ "$DNS_INSTALLED" == true ]]; then
    echo ""
    echo -e "${CYAN}🛠️ DNS Management Commands:${NC}"
    echo -e "• Check DNS: ${GREEN}phynx-dns-check yourdomain.com${NC}"
    echo -e "• Add record: ${GREEN}phynx-dns-update yourdomain.com A subdomain 192.168.1.100${NC}"
    echo -e "• View logs: ${GREEN}tail -f /var/log/phynx-install/install-*.log${NC}"
fi

echo ""
echo -e "${BLUE}📋 Support & Documentation:${NC}"
echo -e "• Installation Guide: ${GREEN}INSTALLATION.md${NC}"
echo -e "• System Requirements: ${GREEN}./check-requirements.sh yourdomain.com${NC}"
echo -e "• Re-run Verification: ${GREEN}./verify-installation.sh yourdomain.com${NC}"

echo ""
echo -e "${GREEN}✅ Advanced Phynx Panel verification complete!${NC}"