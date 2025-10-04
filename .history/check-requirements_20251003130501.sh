#!/bin/bash

# Phynx Panel Pre-Installation Checker
# Validates system requirements before running the main installer

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Counters
PASS_COUNT=0
FAIL_COUNT=0
WARN_COUNT=0

# Functions
check_pass() {
    echo -e "${GREEN}✓${NC} $1"
    ((PASS_COUNT++))
}

check_fail() {
    echo -e "${RED}✗${NC} $1"
    ((FAIL_COUNT++))
}

check_warn() {
    echo -e "${YELLOW}!${NC} $1"
    ((WARN_COUNT++))
}

print_header() {
    echo -e "${BLUE}=== $1 ===${NC}"
}

print_banner() {
    echo -e "${BLUE}"
    echo "╔════════════════════════════════════════════════════╗"
    echo "║         Phynx Panel Pre-Installation Check        ║"
    echo "║                                                    ║"
    echo "║   This script validates system requirements        ║"
    echo "║   before running the main installation             ║"
    echo "╚════════════════════════════════════════════════════╝"
    echo -e "${NC}\n"
}

# Main checks
print_banner

print_header "System Information"
if [[ -f /etc/os-release ]]; then
    source /etc/os-release
    echo "OS: $NAME $VERSION_ID"
    
    # Check Ubuntu version
    if [[ "$NAME" == "Ubuntu" ]]; then
        MAJOR_VERSION=${VERSION_ID%%.*}
        if [[ $MAJOR_VERSION -ge 22 ]]; then
            check_pass "Ubuntu $VERSION_ID (compatible)"
        else
            check_fail "Ubuntu $VERSION_ID (requires 22.04 or higher)"
        fi
    else
        check_fail "Not Ubuntu (this installer only supports Ubuntu)"
    fi
else
    check_fail "Cannot determine OS version"
fi

print_header "User Privileges"
if [[ $EUID -eq 0 ]]; then
    check_pass "Running as root"
else
    check_fail "Must run as root (use sudo)"
fi

print_header "Internet Connection"
if ping -c 1 google.com &> /dev/null; then
    check_pass "Internet connection available"
else
    check_fail "No internet connection (required for package installation)"
fi

print_header "Required Commands"
REQUIRED_COMMANDS=("apt-get" "systemctl" "mysql" "openssl" "wget" "curl" "git" "unzip")
for cmd in "${REQUIRED_COMMANDS[@]}"; do
    if command -v "$cmd" &> /dev/null; then
        check_pass "$cmd command available"
    else
        check_warn "$cmd command not found (will be installed)"
    fi
done

print_header "PHP Requirements"
# Check if PHP is installed and version
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -v | head -n 1 | cut -d ' ' -f 2 | cut -d '.' -f 1,2)
    if [[ "$PHP_VERSION" == "8.1" ]] || [[ "$PHP_VERSION" == "8.2" ]] || [[ "$PHP_VERSION" == "8.3" ]]; then
        check_pass "PHP $PHP_VERSION installed (compatible)"
    else
        check_warn "PHP $PHP_VERSION installed (will install PHP 8.1/8.2)"
    fi
else
    check_pass "PHP not installed (will install PHP 8.1 and 8.2)"
fi

print_header "Security Checks"
# Check for common security tools
if command -v fail2ban-client &> /dev/null; then
    check_warn "Fail2Ban already installed"
else
    check_pass "Fail2Ban not installed (will be configured)"
fi

if command -v ufw &> /dev/null; then
    UFW_STATUS=$(ufw status | head -1 | awk '{print $2}')
    if [[ "$UFW_STATUS" == "active" ]]; then
        check_warn "UFW firewall is active (rules will be added)"
    else
        check_pass "UFW available but inactive"
    fi
else
    check_pass "UFW not installed (will be configured)"
fi

print_header "System Resources"
# Check RAM
TOTAL_RAM=$(free -m | awk 'NR==2{printf "%d", $2}')
if [[ $TOTAL_RAM -ge 1024 ]]; then
    check_pass "RAM: ${TOTAL_RAM}MB (sufficient)"
elif [[ $TOTAL_RAM -ge 512 ]]; then
    check_warn "RAM: ${TOTAL_RAM}MB (minimum, consider upgrading)"
else
    check_fail "RAM: ${TOTAL_RAM}MB (insufficient, minimum 512MB required)"
fi

# Check disk space
AVAILABLE_SPACE=$(df / | awk 'NR==2 {print int($4/1024)}')
if [[ $AVAILABLE_SPACE -ge 2048 ]]; then
    check_pass "Disk space: ${AVAILABLE_SPACE}MB available (sufficient)"
elif [[ $AVAILABLE_SPACE -ge 1024 ]]; then
    check_warn "Disk space: ${AVAILABLE_SPACE}MB available (tight, monitor usage)"
else
    check_fail "Disk space: ${AVAILABLE_SPACE}MB available (insufficient, minimum 1GB required)"
fi

print_header "Network Ports"
REQUIRED_PORTS=(80 443 22 3306)
for port in "${REQUIRED_PORTS[@]}"; do
    if netstat -tuln 2>/dev/null | grep ":$port " &>/dev/null; then
        if [[ $port -eq 3306 ]]; then
            check_warn "Port $port already in use (MySQL might be installed)"
        else
            check_warn "Port $port already in use (may conflict)"
        fi
    else
        check_pass "Port $port available"
    fi
done

print_header "Panel Files"
if [[ -f "index.php" && -d "admin" ]]; then
    check_pass "Panel files found in current directory"
else
    check_fail "Panel files not found (run from panel root directory)"
fi

if [[ -d "phynx" ]]; then
    check_pass "Custom Phynx directory found"
else
    check_warn "Custom Phynx directory not found (will skip Phynx installation)"
fi

if [[ -f "database.sql" ]]; then
    check_pass "Database schema file found"
else
    check_warn "Database schema file not found (manual import required)"
fi

print_header "Existing Installations"
if [[ -d "/var/www/html/phynx" ]]; then
    check_warn "Existing Phynx installation found (will be overwritten)"
fi

if systemctl is-active --quiet apache2; then
    check_warn "Apache2 is already running"
elif systemctl is-active --quiet nginx; then
    check_warn "Nginx is already running"
else
    check_pass "No conflicting web server running"
fi

if systemctl is-active --quiet mysql; then
    check_warn "MySQL is already running"
else
    check_pass "MySQL not running (will be installed)"
fi

# Summary
echo ""
print_header "Pre-Installation Check Summary"
echo -e "Passed: ${GREEN}$PASS_COUNT${NC}"
echo -e "Warnings: ${YELLOW}$WARN_COUNT${NC}"
echo -e "Failed: ${RED}$FAIL_COUNT${NC}"

echo ""
if [[ $FAIL_COUNT -eq 0 ]]; then
    echo -e "${GREEN}✓ System ready for installation!${NC}"
    echo ""
    echo "To proceed with installation, run:"
    echo "  sudo ./install-enhanced.sh"
    echo ""
    echo "Optional parameters:"
    echo "  --web-server=nginx          # Use Nginx instead of Apache"
    echo "  --domain=panel.example.com  # Set custom domain"
    echo "  --no-pma                    # Skip Phynx installation"
    echo "  --csf                       # Use CSF firewall instead of UFW"
    exit 0
else
    echo -e "${RED}✗ System not ready for installation${NC}"
    echo ""
    echo "Please resolve the failed checks before proceeding."
    exit 1
fi