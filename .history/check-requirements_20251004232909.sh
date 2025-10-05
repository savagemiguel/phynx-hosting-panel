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
    echo "╔══════════════════════════════════════════════════════════════════╗"
    echo "║              Phynx Panel Advanced Pre-Installation Check        ║"
    echo "║                                                                  ║"
    echo "║   🌐 Validates system requirements for advanced features         ║"
    echo "║   🔍 Checks DNS/BIND9, monitoring, and performance capabilities  ║"
    echo "║   📊 Verifies resources for progress bars and error handling     ║"
    echo "╚══════════════════════════════════════════════════════════════════╝"
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
REQUIRED_COMMANDS=("apt-get" "systemctl" "mysql" "openssl" "wget" "curl" "git" "unzip" "dig" "named-checkconf")
for cmd in "${REQUIRED_COMMANDS[@]}"; do
    if command -v "$cmd" &> /dev/null; then
        check_pass "$cmd command available"
    else
        check_warn "$cmd command not found (will be installed)"
    fi
done

print_header "DNS and Network Requirements"

# Check if domain is provided as argument
if [[ -n "$1" ]]; then
    DOMAIN="$1"
    echo "Testing domain: $DOMAIN"
    
    # Test DNS resolution
    if dig +short "$DOMAIN" A &> /dev/null; then
        check_pass "Domain $DOMAIN is resolvable"
    else
        check_warn "Domain $DOMAIN not currently resolvable (normal for new domains)"
    fi
    
    # Check if domain points to this server
    CURRENT_IP=$(curl -s http://ipecho.net/plain || curl -s http://icanhazip.com)
    DOMAIN_IP=$(dig +short "$DOMAIN" A | head -1)
    
    if [[ "$DOMAIN_IP" == "$CURRENT_IP" ]]; then
        check_pass "Domain $DOMAIN points to this server ($CURRENT_IP)"
    else
        check_warn "Domain $DOMAIN points to $DOMAIN_IP, server is $CURRENT_IP"
        echo "         This is normal if you haven't configured DNS yet"
    fi
else
    check_warn "No domain specified - run with './check-requirements.sh yourdomain.com' to test domain"
fi

# Check for reserved/blocked ports
REQUIRED_PORTS=(80 443 2083 53)
print_header "Port Availability"

for port in "${REQUIRED_PORTS[@]}"; do
    if netstat -tulpn 2>/dev/null | grep ":$port " &> /dev/null; then
        if [[ "$port" == "53" ]]; then
            check_warn "Port $port in use (DNS) - will be reconfigured if needed"
        else
            check_warn "Port $port in use - may need reconfiguration"
        fi
    else
        check_pass "Port $port available"
    fi
done

print_header "Enhanced System Requirements"

# Memory check for advanced features
TOTAL_MEM=$(grep MemTotal /proc/meminfo | awk '{print int($2/1024)}')
if [[ $TOTAL_MEM -ge 2048 ]]; then
    check_pass "RAM: ${TOTAL_MEM}MB (excellent for all features)"
elif [[ $TOTAL_MEM -ge 1024 ]]; then
    check_pass "RAM: ${TOTAL_MEM}MB (good for standard installation)"
else
    check_warn "RAM: ${TOTAL_MEM}MB (minimum - some advanced features may be limited)"
fi

# CPU cores check
CPU_CORES=$(nproc)
if [[ $CPU_CORES -ge 2 ]]; then
    check_pass "CPU Cores: $CPU_CORES (good for parallel processing)"
else
    check_warn "CPU Cores: $CPU_CORES (may impact installation speed)"
fi

# Disk space check
AVAILABLE_SPACE=$(df / | tail -1 | awk '{print int($4/1024/1024)}')
if [[ $AVAILABLE_SPACE -ge 5 ]]; then
    check_pass "Disk Space: ${AVAILABLE_SPACE}GB available (excellent)"
elif [[ $AVAILABLE_SPACE -ge 2 ]]; then
    check_pass "Disk Space: ${AVAILABLE_SPACE}GB available (sufficient)"
else
    check_fail "Disk Space: ${AVAILABLE_SPACE}GB available (insufficient - need 2GB+)"
fi

print_header "PHP Requirements"
# Check if PHP is installed and version
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -v | head -n 1 | cut -d ' ' -f 2 | cut -d '.' -f 1,2)
    if [[ "$PHP_VERSION" == "8.1" ]] || [[ "$PHP_VERSION" == "8.2" ]] || [[ "$PHP_VERSION" == "8.3" ]] || [[ "$PHP_VERSION" == "8.4" ]]; then
        check_pass "PHP $PHP_VERSION installed (compatible)"
    else
        check_warn "PHP $PHP_VERSION installed (will install PHP 8.1/8.2/8.3/8.4)"
    fi
else
    check_pass "PHP not installed (will install PHP 8.1, 8.2, 8.3, and 8.4)"
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
REQUIRED_PORTS=(80 443 22 3306 2083 2087 666)
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

print_header "Docker Availability"
if command -v docker &> /dev/null; then
    if systemctl is-active --quiet docker; then
        check_pass "Docker is installed and running"
    else
        check_warn "Docker installed but not running"
    fi
    
    # Check if user can access docker (when not running as root)
    if [[ $EUID -ne 0 ]]; then
        if groups $USER | grep -q docker; then
            check_pass "User is in docker group"
        else
            check_warn "User not in docker group (will be added)"
        fi
    fi
else
    check_pass "Docker not installed (optional, will be installed for container management)"
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
    # Check if we can connect
    if mysqladmin ping --silent 2>/dev/null; then
        check_pass "MySQL is accessible"
    else
        check_warn "MySQL is running but not accessible"
    fi
elif systemctl list-unit-files | grep -q mysql.service; then
    check_warn "MySQL is installed but not running"
else
    check_pass "MySQL not installed (will be installed)"
fi

# Advanced features assessment
print_header "Advanced Features Assessment"
ADVANCED_READY=true

echo -e "${CYAN}🚀 Advanced Installation Features:${NC}"
if [[ $TOTAL_MEM -ge 2048 ]]; then
    echo -e "  ${GREEN}✓ RAM (${TOTAL_MEM}MB)${NC} - Excellent for all advanced features"
elif [[ $TOTAL_MEM -ge 1024 ]]; then
    echo -e "  ${GREEN}✓ RAM (${TOTAL_MEM}MB)${NC} - Good for standard + DNS features"
else
    echo -e "  ${YELLOW}⚠ RAM (${TOTAL_MEM}MB)${NC} - Limited advanced feature performance"
    ADVANCED_READY=false
fi

if [[ $CPU_CORES -ge 2 ]]; then
    echo -e "  ${GREEN}✓ CPU Cores ($CPU_CORES)${NC} - Parallel processing optimizations available"
else
    echo -e "  ${YELLOW}⚠ CPU Cores ($CPU_CORES)${NC} - Sequential processing (slower installation)"
fi

if [[ $AVAILABLE_SPACE -ge 5 ]]; then
    echo -e "  ${GREEN}✓ Disk Space (${AVAILABLE_SPACE}GB)${NC} - Plenty for logs, backups, reports"
elif [[ $AVAILABLE_SPACE -ge 2 ]]; then
    echo -e "  ${GREEN}✓ Disk Space (${AVAILABLE_SPACE}GB)${NC} - Sufficient for installation"
else
    echo -e "  ${RED}✗ Disk Space (${AVAILABLE_SPACE}GB)${NC} - Need 2GB+ minimum"
    ADVANCED_READY=false
fi

# Summary
echo ""
print_header "Pre-Installation Check Summary" 
echo -e "Passed: ${GREEN}$PASS_COUNT${NC}"
echo -e "Warnings: ${YELLOW}$WARN_COUNT${NC}"
echo -e "Failed: ${RED}$FAIL_COUNT${NC}"

echo ""
if [[ $FAIL_COUNT -eq 0 ]]; then
    echo -e "${GREEN}✅ System ready for Phynx Panel installation with Advanced Features!${NC}"
    echo ""
    
    if [[ "$ADVANCED_READY" == true ]]; then
        echo -e "${BLUE}🎯 Recommended: Full Advanced Installation${NC}"
        echo "  sudo ./install-enhanced.sh --domain=yourdomain.com --setup-dns"
    else
        echo -e "${YELLOW}⚡ Recommended: Standard Installation${NC}"
        echo "  sudo ./install-enhanced.sh --domain=yourdomain.com --no-dns"
    fi
    
    echo ""
    echo -e "${CYAN}📋 Available Advanced Options:${NC}"
    echo "  --web-server=nginx|apache   # Web server choice (default: apache)"
    echo "  --domain=yourdomain.com     # Main domain for multi-domain setup"
    echo "  --email=admin@yourdomain.com # Admin email address"
    echo "  --setup-dns                 # Create DNS zones automatically (default)"
    echo "  --no-dns                    # Skip DNS zone creation"
    echo "  --csf                       # Use CSF firewall instead of UFW"
    echo "  --no-pma                    # Skip Phynx Manager installation"
    echo "  --no-bind                   # Skip BIND9 DNS server"
    echo "  --silent                    # Skip interactive prompts"
    echo ""
    echo -e "${GREEN}🌟 New Advanced Features:${NC}"
    echo "  • 🌐 Automatic DNS zone creation with nameservers"
    echo "  • 📊 Real-time progress bars with ETA calculations"
    echo "  • 🛡️ Comprehensive error handling and automatic rollback"
    echo "  • 🎛️ Interactive configuration menus and wizards"
    echo "  • 📋 HTML installation reports and analytics"
    echo "  • 🔍 DNS propagation monitoring across global servers"
    echo "  • ⚡ Performance monitoring and system optimization"
    echo ""
    echo -e "${BLUE}Example Commands:${NC}"
    echo "  # Full installation with DNS:"
    echo "  sudo ./install-enhanced.sh --domain=mysite.com --email=admin@mysite.com"
    echo ""
    echo "  # High-security installation:"
    echo "  sudo ./install-enhanced.sh --csf --setup-dns --domain=secure.mysite.com"
    echo ""
    echo "  # Minimal installation:"
    echo "  sudo ./install-enhanced.sh --no-dns --no-pma --silent"
    echo ""
    
    exit 0
else
    echo -e "${RED}❌ System not ready for installation${NC}"
    echo ""
    echo -e "${YELLOW}Please resolve the failed checks above before proceeding.${NC}"
    echo ""
    echo -e "${BLUE}Common Solutions:${NC}"
    echo "  • Run as root: sudo ./check-requirements.sh yourdomain.com"
    echo "  • Update packages: sudo apt update && sudo apt upgrade -y"
    echo "  • Free up disk space if needed"
    echo "  • Check internet connectivity"
    echo "  • Resolve any port conflicts"
    echo ""
    exit 1
fi