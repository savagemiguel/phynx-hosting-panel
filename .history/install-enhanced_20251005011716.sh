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
HTTP_PORT="80"        # Standard HTTP port for hosting panel
HTTPS_PORT="443"      # Standard HTTPS port for SSL
SECURE_PORT="2083"    # Custom secure port for admin panel

# Server IP Configuration
SERVER_IP="216.45.53.244"  # Fixed server IP address

# Domain Configuration
MAIN_DOMAIN="phynx.one"                    # Main website domain
PANEL_SUBDOMAIN="panel.phynx.one"         # Admin panel subdomain
PHYNXADMIN_SUBDOMAIN="phynxadmin.phynx.one" # Database manager subdomain

# DNS and SSL
DNS_ZONE_PATH="/var/lib/bind/zones"
CERTBOT_BIN="/usr/bin/certbot"

# Default values (can be overridden with command line arguments)
WEB_SERVER="apache"  # or nginx
INSTALL_PMA="yes"
INSTALL_BIND="yes"
INSTALL_CSF="no"
SETUP_DNS_ZONES="yes"    # Automatically create DNS zones
PANEL_DOMAIN="$MAIN_DOMAIN"
ADMIN_EMAIL="admin@$MAIN_DOMAIN"
SILENT_MODE="no"

# ===============================
# Helper Functions
# ===============================

print_banner() {
    clear
    echo -e "${BLUE}"
    echo "╔════════════════════════════════════════════════════════════════════════════════╗"
    echo "║                        ${PANEL_DISPLAY_NAME} Installer v${PANEL_VERSION}                      ║"
    echo "║                                                                                ║"
    echo "║  Enhanced installation script with custom Phynx deployment                  ║"
    echo "║  Supports Ubuntu 22.04+ with comprehensive security features                  ║"
    echo "╚════════════════════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}\n"
}

# ===============================
# Advanced Progress System
# ===============================

# Global progress variables
CURRENT_STEP=0
TOTAL_STEPS=0
STEP_START_TIME=0
INSTALLATION_START_TIME=$(date +%s)

# Installation steps configuration
declare -A INSTALLATION_STEPS
INSTALLATION_STEPS=(
    [1]="System validation and prerequisites"
    [2]="Package repository updates"
    [3]="Core system packages installation"
    [4]="Web server installation and configuration"
    [5]="Database server setup"
    [6]="PHP runtime environment setup"
    [7]="SSL certificates and security"
    [8]="Firewall and intrusion prevention"
    [9]="Phynx panel deployment"
    [10]="Database initialization"
    [11]="Virtual host configuration"
    [12]="Service optimization"
    [13]="Security hardening"
    [14]="Final verification and cleanup"
)

# Progress bar function with advanced features
show_progress() {
    local current=$1
    local total=$2
    local message="$3"
    local sub_message="$4"
    
    # Calculate percentage (prevent division by zero)
    local percent=0
    local completed=0
    if [[ $total -gt 0 ]]; then
        percent=$((current * 100 / total))
        completed=$((current * 50 / total))
    fi
    local remaining=$((50 - completed))
    
    # Create progress bar
    local bar=""
    for ((i=0; i<completed; i++)); do
        bar+="█"
    done
    for ((i=0; i<remaining; i++)); do
        bar+="░"
    done
    
    # Calculate time elapsed and ETA
    local current_time=$(date +%s)
    local elapsed=$((current_time - INSTALLATION_START_TIME))
    local estimated_total_time=0
    local eta=0
    
    # Prevent division by zero
    if [[ $current -gt 0 ]]; then
        estimated_total_time=$((elapsed * total / current))
        eta=$((estimated_total_time - elapsed))
    fi
    
    # Format time
    local elapsed_fmt=$(printf "%02d:%02d" $((elapsed / 60)) $((elapsed % 60)))
    local eta_fmt=$(printf "%02d:%02d" $((eta / 60)) $((eta % 60)))
    
    # Clear previous lines and show progress
    echo -ne "\033[2K\r"  # Clear line
    echo -e "${CYAN}╔═══════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC} ${YELLOW}Step $current of $total${NC}: $message"
    if [[ -n "$sub_message" ]]; then
        echo -e "${CYAN}║${NC} ${sub_message}"
    fi
    echo -e "${CYAN}║${NC}"
    echo -e "${CYAN}║${NC} [$bar] ${GREEN}${percent}%${NC}"
    echo -e "${CYAN}║${NC} ${BLUE}Elapsed:${NC} $elapsed_fmt ${BLUE}│${NC} ${BLUE}ETA:${NC} $eta_fmt"
    echo -e "${CYAN}╚═══════════════════════════════════════════════════════════════════════╝${NC}"
}

# Live progress bar for operations
live_progress() {
    local operation_name="$1"
    local duration="$2"
    local steps="${3:-20}"
    
    echo -e "${CYAN}${operation_name}...${NC}"
    
    for ((i=0; i<=steps; i++)); do
        local percent=$((i * 100 / steps))
        local completed=$((i * 50 / steps))
        local remaining=$((50 - completed))
        
        # Create progress bar
        local bar=""
        for ((j=0; j<completed; j++)); do
            bar+="█"
        done
        for ((j=0; j<remaining; j++)); do
            bar+="░"
        done
        
        # Clear line and show progress
        echo -ne "\r${CYAN}║${NC} [$bar] ${GREEN}${percent}%${NC}"
        
        # Sleep for the interval (fallback to 0.1 if bc is not available)
        local sleep_time
        if command -v bc >/dev/null 2>&1; then
            sleep_time=$(echo "scale=2; $duration / $steps" | bc -l 2>/dev/null || echo "0.1")
        else
            sleep_time="0.1"
        fi
        sleep "$sleep_time"
    done
    
    echo -e " ${GREEN}✓ Complete${NC}"
}

# Background process with progress indicator
run_with_progress() {
    local command="$1"
    local message="$2"
    local max_time="${3:-60}"
    
    echo -e "${CYAN}${message}...${NC}"
    
    # Start the command in background
    eval "$command" &
    local pid=$!
    
    local elapsed=0
    local spinner="⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏"
    local spinner_len=${#spinner}
    
    while kill -0 $pid 2>/dev/null; do
        local i=$((elapsed % spinner_len))
        local char="${spinner:$i:1}"
        echo -ne "\r${CYAN}║${NC} ${char} ${message}... ${BLUE}${elapsed}s${NC}"
        
        sleep 1
        ((elapsed++))
        
        # Timeout protection
        if [[ $elapsed -gt $max_time ]]; then
            kill $pid 2>/dev/null
            echo -e "\r${RED}✗ Timeout after ${elapsed}s${NC}"
            return 1
        fi
    done
    
    wait $pid
    local exit_code=$?
    
    if [[ $exit_code -eq 0 ]]; then
        echo -e "\r${CYAN}║${NC} ${GREEN}✓${NC} ${message} ${GREEN}completed in ${elapsed}s${NC}"
    else
        echo -e "\r${CYAN}║${NC} ${RED}✗${NC} ${message} ${RED}failed after ${elapsed}s${NC}"
    fi
    
    return $exit_code
}

# Step progress function
start_step() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    STEP_START_TIME=$(date +%s)
    local step_desc="${INSTALLATION_STEPS[$CURRENT_STEP]}"
    show_progress $CURRENT_STEP $TOTAL_STEPS "$step_desc" "Initializing..."
}

# Sub-step progress function
update_step() {
    local sub_message="$1"
    local step_desc="${INSTALLATION_STEPS[$CURRENT_STEP]}"
    show_progress $CURRENT_STEP $TOTAL_STEPS "$step_desc" "$sub_message"
}

# Complete step function
complete_step() {
    local step_time=$(($(date +%s) - STEP_START_TIME))
    local step_desc="${INSTALLATION_STEPS[$CURRENT_STEP]}"
    show_progress $CURRENT_STEP $TOTAL_STEPS "$step_desc" "${GREEN}✓ Completed in ${step_time}s${NC}"
    sleep 0.5  # Brief pause to show completion
}

# Initialize progress system
init_progress() {
    TOTAL_STEPS=${#INSTALLATION_STEPS[@]}
    CURRENT_STEP=0
    
    echo -e "\n${PURPLE}🚀 Starting Phynx Hosting Panel Installation${NC}"
    echo -e "${YELLOW}Total installation steps: $TOTAL_STEPS${NC}\n"
    
    # Create installation log header
    {
        echo "==============================================="
        echo "Phynx Hosting Panel Installation Log"
        echo "Started: $(date)"
        echo "Server IP: $SERVER_IP"
        echo "Domain: $MAIN_DOMAIN"
        echo "==============================================="
    } >> "$LOG_FILE"
}

# Enhanced logging functions
log() { 
    local msg="$*"
    echo -e "${BLUE}[INFO]${NC} $msg" | tee -a "$LOG_FILE"
}

ok() { 
    local msg="$*"
    echo -e "${GREEN}[OK]${NC} $msg" | tee -a "$LOG_FILE"
}

warn() { 
    local msg="$*"
    echo -e "${YELLOW}[WARN]${NC} $msg" | tee -a "$LOG_FILE"
}

err() { 
    local msg="$*"
    echo -e "${RED}[ERROR]${NC} $msg" | tee -a "$LOG_FILE"
}

die() { 
    err "$*"
    echo -e "\n${RED}💥 Installation failed!${NC}"
    echo -e "${YELLOW}Check the log file: $LOG_FILE${NC}"
    trigger_rollback
    exit 1
}

# ===============================
# Advanced Error Handling & Rollback System
# ===============================

# Rollback tracking
declare -a ROLLBACK_COMMANDS
declare -a INSTALLED_PACKAGES
declare -a CREATED_FILES
declare -a MODIFIED_FILES
declare -a STARTED_SERVICES

# Add rollback command
add_rollback() {
    local command="$1"
    ROLLBACK_COMMANDS+=("$command")
    echo "ROLLBACK: $command" >> "$LOG_FILE"
}

# Track installed packages
track_package() {
    local package="$1"
    INSTALLED_PACKAGES+=("$package")
}

# Track created files
track_file() {
    local file="$1"
    CREATED_FILES+=("$file")
}

# Track modified files (create backup first)
track_modification() {
    local file="$1"
    if [[ -f "$file" ]]; then
        local backup="${file}.phynx_backup_$(date +%s)"
        cp "$file" "$backup" 2>/dev/null || true
        MODIFIED_FILES+=("$file:$backup")
        add_rollback "restore_file '$file' '$backup'"
    fi
}

# Track started services
track_service() {
    local service="$1"
    STARTED_SERVICES+=("$service")
}

# Restore file from backup
restore_file() {
    local original="$1"
    local backup="$2"
    if [[ -f "$backup" ]]; then
        cp "$backup" "$original"
        rm -f "$backup"
        log "Restored: $original"
    fi
}

# Track operation for progress monitoring
add_operation() {
    local operation_name="$1"
    ROLLBACK_OPERATIONS+=("$operation_name")
    log_structured "INFO" "operation" "Starting operation: $operation_name"
}

# Track completed operation
track_operation() {
    local operation_name="$1"
    ROLLBACK_OPERATIONS+=("$operation_name")
    INSTALLATION_STATS[operations]=$((INSTALLATION_STATS[operations] + 1))
    log_structured "INFO" "operation" "Completed operation: $operation_name"
}

# Execute rollback
trigger_rollback() {
    echo -e "\n${YELLOW}🔄 Initiating rollback procedure...${NC}"
    
    # Stop services that were started
    if [[ ${#STARTED_SERVICES[@]} -gt 0 ]]; then
        echo -e "${YELLOW}Stopping services...${NC}"
        for service in "${STARTED_SERVICES[@]}"; do
            systemctl stop "$service" 2>/dev/null || true
            systemctl disable "$service" 2>/dev/null || true
            log "Stopped service: $service"
        done
    fi
    
    # Execute rollback commands in reverse order
    if [[ ${#ROLLBACK_COMMANDS[@]} -gt 0 ]]; then
        echo -e "${YELLOW}Executing rollback commands...${NC}"
        for ((i=${#ROLLBACK_COMMANDS[@]}-1; i>=0; i--)); do
            eval "${ROLLBACK_COMMANDS[$i]}" 2>/dev/null || true
        done
    fi
    
    # Remove created files
    if [[ ${#CREATED_FILES[@]} -gt 0 ]]; then
        echo -e "${YELLOW}Removing created files...${NC}"
        for file in "${CREATED_FILES[@]}"; do
            rm -rf "$file" 2>/dev/null || true
            log "Removed: $file"
        done
    fi
    
    # Remove installed packages (optional, commented for safety)
    # if [[ ${#INSTALLED_PACKAGES[@]} -gt 0 ]]; then
    #     echo -e "${YELLOW}Removing installed packages...${NC}"
    #     apt-get remove --purge -y "${INSTALLED_PACKAGES[@]}" 2>/dev/null || true
    # fi
    
    echo -e "${GREEN}✓ Rollback completed${NC}"
}

# Enhanced command execution with error handling
execute_with_retry() {
    local command="$1"
    local description="$2"
    local max_retries=${3:-3}
    local retry_delay=${4:-5}
    
    for ((i=1; i<=max_retries; i++)); do
        if [[ $i -gt 1 ]]; then
            warn "Retry $((i-1))/$((max_retries-1)) for: $description"
            sleep $retry_delay
        fi
        
        update_step "$description (attempt $i/$max_retries)"
        
        if eval "$command" >> "$LOG_FILE" 2>&1; then
            ok "$description"
            return 0
        else
            if [[ $i -eq $max_retries ]]; then
                err "Failed after $max_retries attempts: $description"
                return 1
            fi
        fi
    done
}

# Safe package installation with tracking
install_packages() {
    local packages=("$@")
    
    for package in "${packages[@]}"; do
        update_step "Installing package: $package"
        
        if execute_with_retry "apt-get install -y $package" "Install $package" 3 5; then
            track_package "$package"
            add_rollback "apt-get remove --purge -y $package"
        else
            die "Failed to install package: $package"
        fi
    done
}

# Safe service management
manage_service() {
    local action="$1"
    local service="$2"
    
    update_step "${action^} service: $service"
    
    case $action in
        "start")
            if execute_with_retry "systemctl start $service" "Start $service service"; then
                track_service "$service"
                add_rollback "systemctl stop $service"
            else
                die "Failed to start service: $service"
            fi
            ;;
        "enable")
            if execute_with_retry "systemctl enable $service" "Enable $service service"; then
                add_rollback "systemctl disable $service"
            else
                die "Failed to enable service: $service"
            fi
            ;;
        "reload")
            execute_with_retry "systemctl reload $service" "Reload $service service" || \
            execute_with_retry "systemctl restart $service" "Restart $service service" || \
            die "Failed to reload service: $service"
            ;;
    esac
}

# Trap for cleanup on exit
cleanup_on_exit() {
    local exit_code=$?
    if [[ $exit_code -ne 0 ]]; then
        echo -e "\n${RED}Installation interrupted with exit code: $exit_code${NC}"
        trigger_rollback
    fi
}

# Set up trap for cleanup
trap cleanup_on_exit EXIT

# ===============================
# System Validation & Health Checks
# ===============================

# System requirements
MIN_RAM_GB=2
MIN_DISK_GB=10
MIN_CPU_CORES=1
REQUIRED_COMMANDS=("curl" "wget" "git" "systemctl" "apt-get")

# Comprehensive system validation
validate_system() {
    local validation_failed=0
    
    update_step "Performing system validation checks..."
    
    # Check RAM
    local ram_gb=$(( $(grep MemTotal /proc/meminfo | awk '{print $2}') / 1024 / 1024 ))
    if [[ $ram_gb -lt $MIN_RAM_GB ]]; then
        err "Insufficient RAM: ${ram_gb}GB available, ${MIN_RAM_GB}GB required"
        validation_failed=1
    else
        ok "RAM check passed: ${ram_gb}GB available"
    fi
    
    # Check disk space
    local disk_gb=$(df / | tail -1 | awk '{print int($4/1024/1024)}')
    if [[ $disk_gb -lt $MIN_DISK_GB ]]; then
        err "Insufficient disk space: ${disk_gb}GB available, ${MIN_DISK_GB}GB required"
        validation_failed=1
    else
        ok "Disk space check passed: ${disk_gb}GB available"
    fi
    
    # Check CPU cores
    local cpu_cores=$(nproc)
    if [[ $cpu_cores -lt $MIN_CPU_CORES ]]; then
        err "Insufficient CPU cores: $cpu_cores available, $MIN_CPU_CORES required"
        validation_failed=1
    else
        ok "CPU check passed: $cpu_cores cores available"
    fi
    
    # Check required commands
    for cmd in "${REQUIRED_COMMANDS[@]}"; do
        if ! command -v "$cmd" &> /dev/null; then
            err "Required command not found: $cmd"
            validation_failed=1
        else
            ok "Command available: $cmd"
        fi
    done
    
    # Check internet connectivity
    update_step "Testing internet connectivity..."
    if ! curl -s --max-time 10 https://google.com > /dev/null; then
        err "No internet connectivity detected"
        validation_failed=1
    else
        ok "Internet connectivity verified"
    fi
    
    # Check if ports are available
    for port in 80 443 22 $SECURE_PORT; do
        if netstat -tuln 2>/dev/null | grep -q ":$port "; then
            warn "Port $port is already in use"
        else
            ok "Port $port is available"
        fi
    done
    
    if [[ $validation_failed -eq 1 ]]; then
        die "System validation failed. Please resolve the issues above."
    fi
    
    ok "All system validation checks passed"
}

# Dependency validation
validate_dependencies() {
    update_step "Validating package dependencies..."
    
    # Update package list first
    execute_with_retry "apt-get update" "Update package lists" 3 5 || die "Failed to update package lists"
    
    # Check if packages are available
    local packages_to_check=(
        "apache2" "nginx" "mysql-server" "php8.1" "php8.2" "php8.3" "php8.4"
        "ufw" "fail2ban" "certbot" "bind9" "git" "curl" "wget" "unzip"
    )
    
    local unavailable_packages=()
    
    for package in "${packages_to_check[@]}"; do
        if ! apt-cache show "$package" &> /dev/null; then
            unavailable_packages+=("$package")
        fi
    done
    
    if [[ ${#unavailable_packages[@]} -gt 0 ]]; then
        warn "Some packages may not be available: ${unavailable_packages[*]}"
        warn "Installation will continue but some features might be skipped"
    else
        ok "All required packages are available"
    fi
}

# Health check after installation
perform_health_check() {
    local health_issues=0
    
    update_step "Performing post-installation health checks..."
    
    # Check services
    local services_to_check=()
    [[ "$WEB_SERVER" == "apache" ]] && services_to_check+=("apache2")
    [[ "$WEB_SERVER" == "nginx" ]] && services_to_check+=("nginx")
    services_to_check+=("mysql" "php8.4-fpm")
    [[ "$INSTALL_BIND" == "yes" ]] && services_to_check+=("bind9")
    
    for service in "${services_to_check[@]}"; do
        if systemctl is-active --quiet "$service"; then
            ok "Service $service is running"
        else
            err "Service $service is not running"
            health_issues=1
        fi
    done
    
    # Check web server response
    update_step "Testing web server response..."
    sleep 2  # Give services time to start
    
    if curl -s -o /dev/null -w "%{http_code}" "http://localhost" | grep -q "200\|403\|301\|302"; then
        ok "Web server is responding"
    else
        err "Web server is not responding properly"
        health_issues=1
    fi
    
    # Check database connection
    update_step "Testing database connection..."
    source /root/.phynx_credentials 2>/dev/null || true
    
    if [[ -n "$MYSQL_ROOT_PASS" ]]; then
        if mysql -u root -p"$MYSQL_ROOT_PASS" -e "SELECT 1;" &> /dev/null; then
            ok "Database connection successful"
        else
            err "Database connection failed"
            health_issues=1
        fi
    else
        warn "Database credentials not found, skipping connection test"
    fi
    
    # Check file permissions
    update_step "Checking file permissions..."
    
    if [[ -d "$PANEL_DIR" ]]; then
        if [[ $(stat -c %U "$PANEL_DIR") == "www-data" ]]; then
            ok "Panel directory ownership correct"
        else
            warn "Panel directory ownership may be incorrect"
        fi
    fi
    
    # Check SSL readiness
    if command -v certbot &> /dev/null; then
        ok "Certbot is available for SSL setup"
    else
        warn "Certbot not found, SSL setup will need manual configuration"
    fi
    
    if [[ $health_issues -eq 0 ]]; then
        ok "All health checks passed"
        return 0
    else
        warn "Some health checks failed, but installation completed"
        return 1
    fi
}

# Performance optimization checks
check_system_performance() {
    update_step "Analyzing system performance..."
    
    # Check system load
    local load_avg=$(uptime | awk -F'load average:' '{print $2}' | awk '{print $1}' | sed 's/,//')
    local load_threshold="2.0"
    
    if (( $(echo "$load_avg > $load_threshold" | bc -l 2>/dev/null || echo 0) )); then
        warn "High system load detected: $load_avg (threshold: $load_threshold)"
    else
        ok "System load is acceptable: $load_avg"
    fi
    
    # Check available memory during installation
    local available_mem=$(free -m | awk 'NR==2{printf "%.0f", $7}')
    if [[ $available_mem -lt 500 ]]; then
        warn "Low available memory: ${available_mem}MB"
    else
        ok "Available memory: ${available_mem}MB"
    fi
    
    # Check disk I/O if iostat is available
    if command -v iostat &> /dev/null; then
        local io_wait=$(iostat -c 1 2 | tail -1 | awk '{print $4}')
        if (( $(echo "$io_wait > 20" | bc -l 2>/dev/null || echo 0) )); then
            warn "High I/O wait detected: ${io_wait}%"
        else
            ok "I/O performance acceptable: ${io_wait}% wait"
        fi
    fi
}

# ===============================
# Performance Monitoring & Optimization
# ===============================

# Resource monitoring during installation
start_resource_monitor() {
    # Create a background process to monitor resources
    (
        while true; do
            local timestamp=$(date '+%H:%M:%S')
            local cpu_usage=$(top -bn1 | grep "Cpu(s)" | awk '{print $2}' | cut -d'%' -f1)
            local mem_usage=$(free | grep Mem | awk '{printf("%.1f", ($3/$2) * 100.0)}')
            local load_avg=$(uptime | awk -F'load average:' '{print $2}' | awk '{print $1}' | sed 's/,//')
            
            echo "$timestamp CPU:${cpu_usage}% MEM:${mem_usage}% LOAD:${load_avg}" >> "${LOG_FILE}.resources"
            sleep 30
        done
    ) &
    
    RESOURCE_MONITOR_PID=$!
    add_rollback "kill $RESOURCE_MONITOR_PID 2>/dev/null || true"
}

# Stop resource monitoring
stop_resource_monitor() {
    if [[ -n "$RESOURCE_MONITOR_PID" ]]; then
        kill $RESOURCE_MONITOR_PID 2>/dev/null || true
    fi
}

# Optimize system for installation
optimize_for_installation() {
    update_step "Optimizing system for installation..."
    
    # Temporarily disable unnecessary services to free up resources
    local services_to_stop=("snapd" "unattended-upgrades")
    
    for service in "${services_to_stop[@]}"; do
        if systemctl is-active --quiet "$service"; then
            systemctl stop "$service" 2>/dev/null || true
            add_rollback "systemctl start $service 2>/dev/null || true"
            log "Temporarily stopped $service to free resources"
        fi
    done
    
    # Set high priority for apt operations
    export DEBIAN_PRIORITY=critical
    export APT_LISTCHANGES_FRONTEND=none
    
    # Configure apt for faster downloads
    echo 'Acquire::Languages "none";' > /etc/apt/apt.conf.d/99translations
    echo 'Acquire::GzipIndexes "true";' >> /etc/apt/apt.conf.d/99translations
    echo 'Acquire::CompressionTypes::Order:: "gz";' >> /etc/apt/apt.conf.d/99translations
    
    track_file "/etc/apt/apt.conf.d/99translations"
    
    ok "System optimized for installation"
}

# Installation time estimation
estimate_installation_time() {
    local base_time=300  # 5 minutes base
    local ram_gb=$(( $(grep MemTotal /proc/meminfo | awk '{print $2}') / 1024 / 1024 ))
    local cpu_cores=$(nproc)
    
    # Adjust based on hardware
    local time_adjustment=0
    
    # RAM factor (less RAM = more time)
    if [[ $ram_gb -lt 4 ]]; then
        time_adjustment=$((time_adjustment + 120))  # +2 minutes
    fi
    
    # CPU factor (fewer cores = more time)
    if [[ $cpu_cores -eq 1 ]]; then
        time_adjustment=$((time_adjustment + 180))  # +3 minutes
    elif [[ $cpu_cores -eq 2 ]]; then
        time_adjustment=$((time_adjustment + 60))   # +1 minute
    fi
    
    # Add time for optional components
    [[ "$INSTALL_PMA" == "yes" ]] && time_adjustment=$((time_adjustment + 60))
    [[ "$INSTALL_BIND" == "yes" ]] && time_adjustment=$((time_adjustment + 90))
    [[ "$INSTALL_CSF" == "yes" ]] && time_adjustment=$((time_adjustment + 120))
    
    local estimated_time=$((base_time + time_adjustment))
    local minutes=$((estimated_time / 60))
    local seconds=$((estimated_time % 60))
    
    echo "Estimated installation time: ${minutes}m ${seconds}s"
    echo "This estimate is based on your system specifications:"
    echo "  • RAM: ${ram_gb}GB"
    echo "  • CPU Cores: ${cpu_cores}"
    echo "  • Optional components: PMA=$INSTALL_PMA, BIND=$INSTALL_BIND, CSF=$INSTALL_CSF"
}

# Parallel processing optimization
enable_parallel_processing() {
    # Configure make to use multiple cores
    export MAKEFLAGS="-j$(nproc)"
    
    # Configure apt for parallel downloads
    echo 'Acquire::Queue-Mode "access";' >> /etc/apt/apt.conf.d/99translations
    
    # Set optimal dpkg options
    echo 'DPkg::Options {"--force-confdef";"--force-confold"};' >> /etc/apt/apt.conf.d/99translations
    
    ok "Parallel processing optimizations enabled"
}

# ===============================
# Advanced Logging & Analytics System
# ===============================

# Installation analytics
declare -A INSTALLATION_STATS
INSTALLATION_STATS[start_time]=$(date +%s)
INSTALLATION_STATS[packages_installed]=0
INSTALLATION_STATS[services_configured]=0
INSTALLATION_STATS[errors_encountered]=0
INSTALLATION_STATS[warnings_issued]=0

# Enhanced logging with structured format
log_structured() {
    local level="$1"
    local component="$2"
    local message="$3"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    # Structured log entry
    echo "[$timestamp] [$level] [$component] $message" >> "$LOG_FILE"
    
    # Console output with colors
    case $level in
        "INFO")  echo -e "${BLUE}[INFO]${NC} $message" ;;
        "OK")    echo -e "${GREEN}[OK]${NC} $message" ;;
        "WARN")  echo -e "${YELLOW}[WARN]${NC} $message"; ((INSTALLATION_STATS[warnings_issued]++)) ;;
        "ERROR") echo -e "${RED}[ERROR]${NC} $message"; ((INSTALLATION_STATS[errors_encountered]++)) ;;
    esac
}

# Performance logging
log_performance() {
    local operation="$1"
    local duration="$2"
    local details="$3"
    
    echo "[PERF] Operation: $operation | Duration: ${duration}s | Details: $details" >> "$LOG_FILE.performance"
}

# Create detailed installation report
generate_installation_report() {
    local report_file="/var/log/phynx-installation-report.html"
    local end_time=$(date +%s)
    local total_duration=$((end_time - INSTALLATION_STATS[start_time]))
    
    update_step "Generating comprehensive installation report..."
    
    cat > "$report_file" << EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phynx Hosting Panel Installation Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-card { background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #007bff; }
        .stat-value { font-size: 24px; font-weight: bold; color: #007bff; }
        .stat-label { color: #6c757d; font-size: 14px; }
        .section { margin: 20px 0; }
        .section h3 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 5px; }
        .log-entry { font-family: monospace; background: #f8f9fa; padding: 5px; margin: 2px 0; border-radius: 3px; font-size: 12px; }
        .error { border-left: 4px solid #dc3545; }
        .warning { border-left: 4px solid #ffc107; }
        .success { border-left: 4px solid #28a745; }
        .info { border-left: 4px solid #17a2b8; }
        .footer { text-align: center; margin-top: 30px; color: #6c757d; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Phynx Hosting Panel Installation Report</h1>
            <p>Installation completed on $(date)</p>
        </div>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-value">$(printf "%02d:%02d" $((total_duration / 60)) $((total_duration % 60)))</div>
                <div class="stat-label">Total Installation Time</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${INSTALLATION_STATS[packages_installed]}</div>
                <div class="stat-label">Packages Installed</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${INSTALLATION_STATS[services_configured]}</div>
                <div class="stat-label">Services Configured</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${INSTALLATION_STATS[warnings_issued]}</div>
                <div class="stat-label">Warnings</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${INSTALLATION_STATS[errors_encountered]}</div>
                <div class="stat-label">Errors (Recovered)</div>
            </div>
        </div>
        
        <div class="section">
            <h3>🌐 Access Information</h3>
            <p><strong>Main Website:</strong> <a href="http://$MAIN_DOMAIN">http://$MAIN_DOMAIN</a></p>
            <p><strong>Admin Panel:</strong> <a href="http://$PANEL_SUBDOMAIN">http://$PANEL_SUBDOMAIN</a> | <a href="http://$MAIN_DOMAIN/panel">http://$MAIN_DOMAIN/panel</a></p>
            <p><strong>Database Manager:</strong> <a href="http://$PHYNXADMIN_SUBDOMAIN">http://$PHYNXADMIN_SUBDOMAIN</a> | <a href="http://$MAIN_DOMAIN/phynxadmin">http://$MAIN_DOMAIN/phynxadmin</a></p>
            <p><strong>Secure Admin:</strong> <a href="https://$MAIN_DOMAIN:$SECURE_PORT">https://$MAIN_DOMAIN:$SECURE_PORT</a></p>
            <p><strong>IP Access:</strong> <a href="http://$SERVER_IP">http://$SERVER_IP</a></p>
        </div>
        
        <div class="section">
            <h3>🔧 System Configuration</h3>
            <ul>
                <li><strong>Web Server:</strong> $WEB_SERVER</li>
                <li><strong>PHP Version:</strong> $(php --version 2>/dev/null | head -1 || echo "Not detected")</li>
                <li><strong>Database:</strong> $(mysql --version 2>/dev/null || echo "Not detected")</li>
                <li><strong>SSL:</strong> $(certbot --version 2>/dev/null || echo "Not installed")</li>
                <li><strong>Firewall:</strong> $(if [[ "$INSTALL_CSF" == "yes" ]]; then echo "CSF/LFD"; else echo "UFW"; fi)</li>
                <li><strong>DNS Server:</strong> $(if [[ "$INSTALL_BIND" == "yes" ]]; then echo "BIND9"; else echo "Not installed"; fi)</li>
            </ul>
        </div>
        
        <div class="section">
            <h3>📊 System Resources</h3>
            <ul>
                <li><strong>CPU Cores:</strong> $(nproc)</li>
                <li><strong>RAM:</strong> $(( $(grep MemTotal /proc/meminfo | awk '{print $2}') / 1024 / 1024 ))GB</li>
                <li><strong>Disk Space:</strong> $(df -h / | tail -1 | awk '{print $4}') available</li>
                <li><strong>Server IP:</strong> $SERVER_IP</li>
            </ul>
        </div>
        
        <div class="section">
            <h3>📋 Installation Steps Completed</h3>
EOF

    # Add installation steps to report
    for i in $(seq 1 $TOTAL_STEPS); do
        echo "            <div class=\"log-entry success\">✓ Step $i: ${INSTALLATION_STEPS[$i]}</div>" >> "$report_file"
    done
    
    cat >> "$report_file" << EOF
        </div>
        
        <div class="section">
            <h3>🔍 Recent Log Entries</h3>
EOF

    # Add last 20 log entries
    if [[ -f "$LOG_FILE" ]]; then
        tail -20 "$LOG_FILE" | while IFS= read -r line; do
            local class="info"
            [[ "$line" == *"ERROR"* ]] && class="error"
            [[ "$line" == *"WARN"* ]] && class="warning"
            [[ "$line" == *"OK"* ]] && class="success"
            
            echo "            <div class=\"log-entry $class\">$(echo "$line" | sed 's/</\&lt;/g' | sed 's/>/\&gt;/g')</div>" >> "$report_file"
        done
    fi
    
    cat >> "$report_file" << EOF
        </div>
        
        <div class="footer">
            <p>Report generated by Phynx Hosting Panel Installer v$PANEL_VERSION</p>
            <p>Full logs available at: $LOG_FILE</p>
        </div>
    </div>
</body>
</html>
EOF

    track_file "$report_file"
    ok "Installation report generated: $report_file"
}

# Backup system state before installation
create_system_backup() {
    local backup_dir="/var/backups/phynx-pre-install"
    update_step "Creating system state backup..."
    
    mkdir -p "$backup_dir"
    track_file "$backup_dir"
    
    # Backup important config files
    local files_to_backup=(
        "/etc/apache2/apache2.conf"
        "/etc/nginx/nginx.conf"
        "/etc/mysql/mysql.conf.d/mysqld.cnf"
        "/etc/php/*/fpm/php.ini"
        "/etc/ufw/ufw.conf"
    )
    
    for file_pattern in "${files_to_backup[@]}"; do
        for file in $file_pattern; do
            if [[ -f "$file" ]]; then
                local backup_path="$backup_dir$(dirname "$file")"
                mkdir -p "$backup_path"
                cp "$file" "$backup_path/" 2>/dev/null || true
                log_structured "INFO" "BACKUP" "Backed up: $file"
            fi
        done
    done
    
    # Backup package list
    dpkg --get-selections > "$backup_dir/packages.list"
    
    # Backup service states
    systemctl list-units --type=service --state=running --no-pager --plain | awk '{print $1}' > "$backup_dir/running-services.list"
    
    ok "System backup completed: $backup_dir"
}

# ===============================
# DNS Zone Management & Auto-Configuration
# ===============================

# Create DNS zones and records automatically
setup_dns_zones() {
    log "Setting up DNS zones for $MAIN_DOMAIN"
    
    # Ensure BIND9 is installed for DNS management
    if ! command -v named &> /dev/null; then
        if [[ "$INSTALL_BIND" != "yes" ]]; then
            warn "BIND9 not installed. Installing now for DNS management..."
            install_bind9
        fi
    fi
    
    # Create primary zone configuration
    create_primary_dns_zone
    
    # Create reverse DNS zone
    create_reverse_dns_zone
    
    # Setup nameserver records
    configure_nameservers
    
    # Add all required DNS records
    add_dns_records
    
    # Restart BIND9 to apply changes
    systemctl restart bind9
    systemctl enable bind9
    
    # Verify DNS configuration
    verify_dns_setup
    
    # Test local DNS resolution
    test_local_dns
    
    ok "DNS zones configured successfully"
}

# Create primary DNS zone file
create_primary_dns_zone() {
    local zone_file="/etc/bind/zones/db.$MAIN_DOMAIN"
    local zone_dir="/etc/bind/zones"
    
    # Create zones directory if it doesn't exist
    mkdir -p "$zone_dir"
    
    log "Creating primary DNS zone for $MAIN_DOMAIN"
    
    # Get current date serial (YYYYMMDDNN format)
    local serial=$(date +%Y%m%d01)
    
    cat > "$zone_file" << EOF
;
; DNS Zone file for $MAIN_DOMAIN
; Generated automatically by Phynx Hosting Panel
; Date: $(date)
;
\$TTL 14400
@       IN      SOA     ns1.$MAIN_DOMAIN. admin.$MAIN_DOMAIN. (
                        $serial         ; Serial (YYYYMMDDNN)
                        3600            ; Refresh (1 hour)
                        1800            ; Retry (30 minutes)
                        1209600         ; Expire (2 weeks)
                        86400 )         ; Minimum TTL (1 day)

; Nameserver records
@       IN      NS      ns1.$MAIN_DOMAIN.
@       IN      NS      ns2.$MAIN_DOMAIN.

; Main domain A record
@       IN      A       $SERVER_IP
www     IN      A       $SERVER_IP

; Nameserver A records
ns1     IN      A       $SERVER_IP
ns2     IN      A       $SERVER_IP

; Subdomain records for panel access
panel   IN      A       $SERVER_IP
phynxadmin IN   A       $SERVER_IP

; Mail exchange records
@       IN      MX      10      mail.$MAIN_DOMAIN.
mail    IN      A       $SERVER_IP

; Additional service records
ftp     IN      A       $SERVER_IP
smtp    IN      A       $SERVER_IP
pop     IN      A       $SERVER_IP
imap    IN      A       $SERVER_IP

; CNAME records for common services
webmail IN      CNAME   $MAIN_DOMAIN.
cpanel  IN      CNAME   panel.$MAIN_DOMAIN.
whm     IN      CNAME   panel.$MAIN_DOMAIN.

; TXT records for verification and security
@       IN      TXT     "v=spf1 a mx ip4:$SERVER_IP ~all"
_dmarc  IN      TXT     "v=DMARC1; p=quarantine; rua=mailto:admin@$MAIN_DOMAIN"

; SRV records for services
_http._tcp      IN      SRV     10 5 80 $MAIN_DOMAIN.
_https._tcp     IN      SRV     10 5 443 $MAIN_DOMAIN.
EOF

    chmod 644 "$zone_file"
    chown bind:bind "$zone_file"
    
    ok "Primary DNS zone created: $zone_file"
}

# Create reverse DNS zone
create_reverse_dns_zone() {
    local reverse_zone=$(echo "$SERVER_IP" | awk -F. '{print $3"."$2"."$1}').in-addr.arpa
    local reverse_file="/etc/bind/zones/db.$reverse_zone"
    
    log "Creating reverse DNS zone for $SERVER_IP"
    
    # Extract last octet of IP for PTR record
    local last_octet=$(echo "$SERVER_IP" | awk -F. '{print $4}')
    local serial=$(date +%Y%m%d01)
    
    cat > "$reverse_file" << EOF
;
; Reverse DNS Zone file for $SERVER_IP
; Generated automatically by Phynx Hosting Panel
; Date: $(date)
;
\$TTL 14400
@       IN      SOA     ns1.$MAIN_DOMAIN. admin.$MAIN_DOMAIN. (
                        $serial         ; Serial
                        3600            ; Refresh
                        1800            ; Retry
                        1209600         ; Expire
                        86400 )         ; Minimum TTL

; Nameserver records
        IN      NS      ns1.$MAIN_DOMAIN.
        IN      NS      ns2.$MAIN_DOMAIN.

; PTR record
$last_octet     IN      PTR     $MAIN_DOMAIN.
$last_octet     IN      PTR     ns1.$MAIN_DOMAIN.
$last_octet     IN      PTR     ns2.$MAIN_DOMAIN.
$last_octet     IN      PTR     panel.$MAIN_DOMAIN.
$last_octet     IN      PTR     mail.$MAIN_DOMAIN.
EOF

    chmod 644 "$reverse_file"
    chown bind:bind "$reverse_file"
    
    ok "Reverse DNS zone created: $reverse_file"
}

# Configure nameservers in BIND
configure_nameservers() {
    log "Configuring nameservers in BIND"
    
    # Backup original named.conf.local
    cp /etc/bind/named.conf.local /etc/bind/named.conf.local.backup.$(date +%Y%m%d)
    
    # Add zone configurations to named.conf.local
    cat >> /etc/bind/named.conf.local << EOF

//
// DNS Zones for $MAIN_DOMAIN - Added by Phynx Hosting Panel
// Date: $(date)
//

// Primary zone for $MAIN_DOMAIN
zone "$MAIN_DOMAIN" {
    type master;
    file "/etc/bind/zones/db.$MAIN_DOMAIN";
    allow-transfer { any; };
    allow-query { any; };
    allow-update { none; };
};

// Reverse zone for $SERVER_IP
zone "$(echo "$SERVER_IP" | awk -F. '{print $3"."$2"."$1}').in-addr.arpa" {
    type master;
    file "/etc/bind/zones/db.$(echo "$SERVER_IP" | awk -F. '{print $3"."$2"."$1}').in-addr.arpa";
    allow-transfer { any; };
    allow-query { any; };
    allow-update { none; };
};

EOF

    ok "Nameserver configuration updated"
}

# Add comprehensive DNS records
add_dns_records() {
    log "Adding comprehensive DNS records"
    
    # Create additional records for common hosting services
    local zone_file="/etc/bind/zones/db.$MAIN_DOMAIN"
    
    # Add CAA records for SSL certificate authority authorization
    cat >> "$zone_file" << EOF

; CAA records for SSL certificate authority
@       IN      CAA     0 issue "letsencrypt.org"
@       IN      CAA     0 issuewild "letsencrypt.org"
@       IN      CAA     0 iodef "mailto:admin@$MAIN_DOMAIN"

; Additional CNAME records for services
autoconfig      IN      CNAME   $MAIN_DOMAIN.
autodiscover    IN      CNAME   $MAIN_DOMAIN.
EOF

    ok "Additional DNS records added"
}

# Verify DNS setup and configuration
verify_dns_setup() {
    log "Verifying DNS configuration"
    
    # Test BIND configuration syntax
    if ! named-checkconf; then
        error "BIND configuration syntax error"
        return 1
    fi
    
    # Test zone file syntax
    if ! named-checkzone "$MAIN_DOMAIN" "/etc/bind/zones/db.$MAIN_DOMAIN"; then
        error "DNS zone file syntax error"
        return 1
    fi
    
    # Check if BIND is running
    if ! systemctl is-active --quiet bind9; then
        warn "BIND9 service not running, attempting to start..."
        systemctl start bind9
        sleep 2
        
        if ! systemctl is-active --quiet bind9; then
            error "Failed to start BIND9 service"
            return 1
        fi
    fi
    
    ok "DNS configuration verified successfully"
}

# Check DNS propagation and external accessibility
check_dns_propagation() {
    log "Checking DNS propagation for $MAIN_DOMAIN"
    
    # Array of public DNS servers to test against
    local dns_servers=("8.8.8.8" "1.1.1.1" "208.67.222.222" "9.9.9.9")
    local propagation_success=0
    local total_servers=${#dns_servers[@]}
    
    echo -e "${CYAN}Testing DNS propagation across public DNS servers:${NC}"
    
    for dns_server in "${dns_servers[@]}"; do
        echo -n "Testing against $dns_server... "
        
        # Test A record resolution
        local result=$(dig +short @"$dns_server" "$MAIN_DOMAIN" A 2>/dev/null)
        
        if [[ "$result" == "$SERVER_IP" ]]; then
            echo -e "${GREEN}✓ Success${NC}"
            ((propagation_success++))
        else
            echo -e "${RED}✗ Failed${NC} (Got: $result, Expected: $SERVER_IP)"
        fi
    done
    
    # Calculate propagation percentage (prevent division by zero)
    local propagation_percent=0
    if [[ $total_servers -gt 0 ]]; then
        propagation_percent=$((propagation_success * 100 / total_servers))
    fi
    
    echo ""
    echo -e "${CYAN}DNS Propagation Status:${NC}"
    echo -e "• Success Rate: ${GREEN}$propagation_success/$total_servers${NC} (${propagation_percent}%)"
    
    if [[ $propagation_percent -ge 75 ]]; then
        ok "DNS propagation successful (${propagation_percent}%)"
        return 0
    elif [[ $propagation_percent -ge 50 ]]; then
        warn "DNS propagation in progress (${propagation_percent}%)"
        return 0
    else
        error "DNS propagation failed (${propagation_percent}%)"
        return 1
    fi
}

# Setup external DNS instructions
show_external_dns_instructions() {
    echo -e "\n${PURPLE}╔════════════════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${PURPLE}║                           🌐 DNS Configuration Instructions                    ║${NC}"
    echo -e "${PURPLE}╚════════════════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${CYAN}📋 Domain Registrar Configuration:${NC}"
    echo ""
    echo -e "${YELLOW}1. Login to your domain registrar (GoDaddy, Namecheap, etc.)${NC}"
    echo -e "${YELLOW}2. Navigate to DNS Management / Nameservers section${NC}"
    echo -e "${YELLOW}3. Set the following nameservers for $MAIN_DOMAIN:${NC}"
    echo ""
    echo -e "   ${GREEN}Primary Nameserver:${NC}   ns1.$MAIN_DOMAIN"
    echo -e "   ${GREEN}Secondary Nameserver:${NC} ns2.$MAIN_DOMAIN"
    echo ""
    echo -e "${YELLOW}4. If your registrar requires IP addresses for nameservers:${NC}"
    echo -e "   ${GREEN}ns1.$MAIN_DOMAIN${NC} → $SERVER_IP"
    echo -e "   ${GREEN}ns2.$MAIN_DOMAIN${NC} → $SERVER_IP"
    echo ""
    echo -e "${CYAN}🔗 Alternative: If you want to use your current DNS provider:${NC}"
    echo -e "${YELLOW}Add these records to your existing DNS zone:${NC}"
    echo ""
    echo -e "   ${GREEN}A Record:${NC}     $MAIN_DOMAIN → $SERVER_IP"
    echo -e "   ${GREEN}A Record:${NC}     www.$MAIN_DOMAIN → $SERVER_IP"
    echo -e "   ${GREEN}A Record:${NC}     panel.$MAIN_DOMAIN → $SERVER_IP"
    echo -e "   ${GREEN}A Record:${NC}     phynxadmin.$MAIN_DOMAIN → $SERVER_IP"
    echo -e "   ${GREEN}MX Record:${NC}    $MAIN_DOMAIN → mail.$MAIN_DOMAIN (Priority: 10)"
    echo ""
    echo -e "${BLUE}💡 DNS propagation typically takes 4-48 hours to complete worldwide.${NC}"
    echo ""
}

# Monitor DNS propagation in real-time
monitor_dns_propagation() {
    local max_attempts=10
    local attempt=1
    local check_interval=30
    
    log "Starting DNS propagation monitoring..."
    echo -e "${CYAN}Monitoring DNS propagation for $MAIN_DOMAIN (checking every ${check_interval}s)${NC}"
    
    while [[ $attempt -le $max_attempts ]]; do
        echo -e "\n${BLUE}[Attempt $attempt/$max_attempts]${NC} $(date)"
        
        if check_dns_propagation; then
            ok "DNS propagation completed successfully!"
            return 0
        fi
        
        if [[ $attempt -lt $max_attempts ]]; then
            echo -e "${YELLOW}Waiting ${check_interval}s before next check...${NC}"
            sleep $check_interval
        fi
        
        ((attempt++))
    done
    
    warn "DNS propagation monitoring completed. Manual verification may be needed."
    show_external_dns_instructions
}

# Create DNS management tools
create_dns_management_tools() {
    log "Creating DNS management tools"
    
    # Create DNS update script
    cat > /usr/local/bin/phynx-dns-update << 'EOF'
#!/bin/bash
# Phynx DNS Update Tool
# Usage: phynx-dns-update <domain> <type> <name> <value>

DOMAIN="$1"
TYPE="$2"
NAME="$3"
VALUE="$4"

if [[ $# -ne 4 ]]; then
    echo "Usage: $0 <domain> <type> <name> <value>"
    echo "Example: $0 example.com A subdomain 192.168.1.100"
    exit 1
fi

ZONE_FILE="/etc/bind/zones/db.$DOMAIN"

if [[ ! -f "$ZONE_FILE" ]]; then
    echo "Error: Zone file for $DOMAIN not found"
    exit 1
fi

# Add DNS record
echo "$NAME    IN    $TYPE    $VALUE" >> "$ZONE_FILE"

# Update serial number
CURRENT_SERIAL=$(grep -E "^[[:space:]]*[0-9]+[[:space:]]*;" "$ZONE_FILE" | head -1 | awk '{print $1}')
NEW_SERIAL=$((CURRENT_SERIAL + 1))
sed -i "s/$CURRENT_SERIAL/$NEW_SERIAL/" "$ZONE_FILE"

# Reload BIND
systemctl reload bind9

echo "DNS record added successfully: $NAME $TYPE $VALUE"
EOF

    chmod +x /usr/local/bin/phynx-dns-update
    
    # Create DNS check script
    cat > /usr/local/bin/phynx-dns-check << 'EOF'
#!/bin/bash
# Phynx DNS Check Tool
# Usage: phynx-dns-check <domain>

DOMAIN="$1"

if [[ -z "$DOMAIN" ]]; then
    echo "Usage: $0 <domain>"
    exit 1
fi

echo "Checking DNS for $DOMAIN..."
echo "A Record: $(dig +short $DOMAIN A)"
echo "NS Records: $(dig +short $DOMAIN NS)"
echo "MX Records: $(dig +short $DOMAIN MX)"
EOF

    chmod +x /usr/local/bin/phynx-dns-check
    
    ok "DNS management tools created"
}

# Show DNS completion information
show_dns_completion_info() {
    echo -e "\n${PURPLE}╔════════════════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${PURPLE}║                           🌐 DNS Configuration Complete                        ║${NC}"
    echo -e "${PURPLE}╚════════════════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    
    echo -e "${CYAN}✅ DNS Zones Created Successfully:${NC}"
    echo ""
    echo -e "${GREEN}📁 Primary Zone:${NC} $MAIN_DOMAIN"
    echo -e "   • Location: /etc/bind/zones/db.$MAIN_DOMAIN"
    echo -e "   • Records: A, CNAME, MX, TXT, SRV, CAA"
    echo ""
    
    echo -e "${GREEN}📁 Reverse Zone:${NC} $(echo "$SERVER_IP" | awk -F. '{print $3"."$2"."$1}').in-addr.arpa"
    echo -e "   • Location: /etc/bind/zones/db.$(echo "$SERVER_IP" | awk -F. '{print $3"."$2"."$1}').in-addr.arpa"
    echo -e "   • PTR Records configured"
    echo ""
    
    echo -e "${CYAN}🔧 DNS Management Tools:${NC}"
    echo -e "   • ${GREEN}phynx-dns-update${NC} - Add/modify DNS records"
    echo -e "   • ${GREEN}phynx-dns-check${NC} - Check DNS resolution"
    echo ""
    
    echo -e "${CYAN}🎯 Your Website Domains:${NC}"
    echo -e "   • ${GREEN}$MAIN_DOMAIN${NC} → $SERVER_IP"
    echo -e "   • ${GREEN}www.$MAIN_DOMAIN${NC} → $SERVER_IP"
    echo -e "   • ${GREEN}panel.$MAIN_DOMAIN${NC} → $SERVER_IP (Admin Panel)"
    echo -e "   • ${GREEN}phynxadmin.$MAIN_DOMAIN${NC} → $SERVER_IP (Database Manager)"
    echo -e "   • ${GREEN}mail.$MAIN_DOMAIN${NC} → $SERVER_IP (Email Server)"
    echo ""
    
    echo -e "${YELLOW}⚠️  Important Next Steps:${NC}"
    echo -e "   1. Configure your domain registrar's nameservers"
    echo -e "   2. Wait for DNS propagation (4-48 hours)"
    echo -e "   3. Verify website accessibility"
    echo ""
}

# Test local DNS resolution
test_local_dns() {
    log "Testing local DNS resolution"
    
    local tests_passed=0
    local total_tests=0
    
    # Test domains to check
    local domains=(
        "$MAIN_DOMAIN"
        "www.$MAIN_DOMAIN"
        "panel.$MAIN_DOMAIN"
        "phynxadmin.$MAIN_DOMAIN"
        "mail.$MAIN_DOMAIN"
    )
    
    echo -e "${CYAN}Local DNS Resolution Test:${NC}"
    
    for domain in "${domains[@]}"; do
        ((total_tests++))
        echo -n "Testing $domain... "
        
        # Use local DNS server (127.0.0.1)
        local result=$(dig +short @127.0.0.1 "$domain" A 2>/dev/null)
        
        if [[ "$result" == "$SERVER_IP" ]]; then
            echo -e "${GREEN}✓ Pass${NC}"
            ((tests_passed++))
        else
            echo -e "${RED}✗ Fail${NC} (Got: $result)"
        fi
    done
    
    echo ""
    echo -e "${CYAN}Local DNS Test Results:${NC}"
    local test_percent=0
    if [[ $total_tests -gt 0 ]]; then
        test_percent=$(( tests_passed * 100 / total_tests ))
    fi
    echo -e "• Passed: ${GREEN}$tests_passed/$total_tests${NC} ($test_percent%)"
    
    if [[ $tests_passed -eq $total_tests ]]; then
        ok "Local DNS resolution working perfectly"
        return 0
    else
        warn "Some local DNS tests failed"
        return 1
    fi
}

# ===============================
# Interactive Features & User Interface
# ===============================

# Interactive confirmation with enhanced UI
interactive_confirm() {
    local message="$1"
    local default="${2:-n}"
    local timeout="${3:-30}"
    
    echo -e "\n${CYAN}╔══════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC} ${YELLOW}⚠️  Confirmation Required${NC}"
    echo -e "${CYAN}║${NC}"
    echo -e "${CYAN}║${NC} $message"
    echo -e "${CYAN}║${NC}"
    echo -e "${CYAN}║${NC} ${BLUE}Timeout: ${timeout}s (default: $default)${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════════════╝${NC}"
    
    local answer
    read -t "$timeout" -p "Continue? [Y/n]: " answer 2>/dev/null || answer="$default"
    
    [[ "$answer" =~ ^[Yy]$ ]]
}

# Advanced installation summary with interactive options
show_installation_summary() {
    clear
    echo -e "${PURPLE}╔════════════════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${PURPLE}║                           🚀 Phynx Installation Summary                      ║${NC}"
    echo -e "${PURPLE}╚════════════════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    
    # System Information
    echo -e "${CYAN}🖥️  System Information:${NC}"
    echo -e "   • OS: $(lsb_release -d 2>/dev/null | cut -f2 || echo "Unknown")"
    echo -e "   • Kernel: $(uname -r)"
    echo -e "   • Architecture: $(uname -m)"
    echo -e "   • CPU Cores: $(nproc)"
    echo -e "   • RAM: $(( $(grep MemTotal /proc/meminfo | awk '{print $2}') / 1024 / 1024 ))GB"
    echo -e "   • Disk Space: $(df -h / | tail -1 | awk '{print $4}') available"
    echo ""
    
    # Installation Configuration
    echo -e "${CYAN}🔧 Installation Configuration:${NC}"
    echo -e "   • Main Domain: ${GREEN}$MAIN_DOMAIN${NC}"
    echo -e "   • Admin Panel: ${GREEN}$PANEL_SUBDOMAIN${NC}"
    echo -e "   • Database Manager: ${GREEN}$PHYNXADMIN_SUBDOMAIN${NC}"
    echo -e "   • Server IP: ${GREEN}$SERVER_IP${NC}"
    echo -e "   • Web Server: ${GREEN}$WEB_SERVER${NC}"
    echo -e "   • Ports: HTTP(80), HTTPS(443), Secure($SECURE_PORT)"
    echo ""
    
    # Optional Components
    echo -e "${CYAN}📦 Optional Components:${NC}"
    echo -e "   • Custom Phynx Manager: $(if [[ "$INSTALL_PMA" == "yes" ]]; then echo "${GREEN}✓ Yes${NC}"; else echo "${RED}✗ No${NC}"; fi)"
    echo -e "   • BIND9 DNS Server: $(if [[ "$INSTALL_BIND" == "yes" ]]; then echo "${GREEN}✓ Yes${NC}"; else echo "${RED}✗ No${NC}"; fi)"
    echo -e "   • CSF Firewall: $(if [[ "$INSTALL_CSF" == "yes" ]]; then echo "${GREEN}✓ Yes${NC}"; else echo "${RED}✗ No${NC}"; fi)"
    echo -e "   • DNS Zone Auto-Setup: $(if [[ "$SETUP_DNS_ZONES" == "yes" ]]; then echo "${GREEN}✓ Yes${NC}"; else echo "${RED}✗ No${NC}"; fi)"
    echo ""
    
    # Time Estimation
    echo -e "${CYAN}⏱️  Installation Estimate:${NC}"
    estimate_installation_time
    echo ""
    
    # Final confirmation
    if [[ "$SILENT_MODE" != "yes" ]]; then
        if ! interactive_confirm "Proceed with installation using the above configuration?" "n" 60; then
            echo -e "${YELLOW}Installation cancelled by user${NC}"
            exit 0
        fi
    fi
}

# Real-time step display
show_step_header() {
    local step_num="$1"
    local step_desc="$2"
    
    echo -e "\n${BLUE}╔══════════════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║${NC} ${YELLOW}Step $step_num of $TOTAL_STEPS${NC}: $step_desc"
    echo -e "${BLUE}╚══════════════════════════════════════════════════════════════════════════════╝${NC}"
}

# Interactive menu for configuration
interactive_menu() {
    if [[ "$SILENT_MODE" == "yes" ]]; then
        return 0
    fi
    
    while true; do
        clear
        echo -e "${PURPLE}╔════════════════════════════════════════════════════════════════════════════════╗${NC}"
        echo -e "${PURPLE}║                      🎛️  Phynx Installation Configuration                    ║${NC}"
        echo -e "${PURPLE}╚════════════════════════════════════════════════════════════════════════════════╝${NC}"
        echo ""
        echo -e "${CYAN}Current Configuration:${NC}"
        echo -e "  1) Domain: $MAIN_DOMAIN"
        echo -e "  2) Web Server: $WEB_SERVER"
        echo -e "  3) Email: $ADMIN_EMAIL"
        echo -e "  4) Install Phynx Manager: $INSTALL_PMA"
        echo -e "  5) Install BIND9: $INSTALL_BIND"
        echo -e "  6) Install CSF Firewall: $INSTALL_CSF"
        echo -e "  7) Setup DNS Zones: $SETUP_DNS_ZONES"
        echo -e "  8) Secure Port: $SECURE_PORT"
        echo ""
        echo -e "${YELLOW}Options:${NC}"
        echo -e "  ${GREEN}s)${NC} Start Installation"
        echo -e "  ${GREEN}1-8)${NC} Modify Configuration"
        echo -e "  ${GREEN}a)${NC} Advanced Options"
        echo -e "  ${GREEN}q)${NC} Quit"
        echo ""
        
        read -p "Select option [s]: " choice
        
        case "$choice" in
            ""|"s"|"S")
                break
                ;;
            "1")
                read -p "Enter domain name [$MAIN_DOMAIN]: " new_domain
                if [[ -n "$new_domain" ]]; then
                    MAIN_DOMAIN="$new_domain"
                    PANEL_SUBDOMAIN="panel.$MAIN_DOMAIN"
                    PHYNXADMIN_SUBDOMAIN="phynxadmin.$MAIN_DOMAIN"
                    PANEL_DOMAIN="$MAIN_DOMAIN"
                fi
                ;;
            "2")
                echo "Select web server:"
                echo "  1) Apache (recommended)"
                echo "  2) Nginx"
                read -p "Choice [1]: " ws_choice
                case "$ws_choice" in
                    "2") WEB_SERVER="nginx" ;;
                    *) WEB_SERVER="apache" ;;
                esac
                ;;
            "3")
                read -p "Enter admin email [$ADMIN_EMAIL]: " new_email
                [[ -n "$new_email" ]] && ADMIN_EMAIL="$new_email"
                ;;
            "4")
                INSTALL_PMA=$([ "$INSTALL_PMA" == "yes" ] && echo "no" || echo "yes")
                ;;
            "5")
                INSTALL_BIND=$([ "$INSTALL_BIND" == "yes" ] && echo "no" || echo "yes")
                ;;
            "6")
                INSTALL_CSF=$([ "$INSTALL_CSF" == "yes" ] && echo "no" || echo "yes")
                ;;
            "7")
                SETUP_DNS_ZONES=$([ "$SETUP_DNS_ZONES" == "yes" ] && echo "no" || echo "yes")
                ;;
            "8")
                read -p "Enter secure port [$SECURE_PORT]: " new_port
                if [[ "$new_port" =~ ^[0-9]+$ ]] && [[ "$new_port" -ge 1024 ]] && [[ "$new_port" -le 65535 ]]; then
                    SECURE_PORT="$new_port"
                else
                    echo -e "${RED}Invalid port number${NC}"
                    sleep 2
                fi
                ;;
            "a"|"A")
                show_advanced_options
                ;;
            "q"|"Q")
                echo "Installation cancelled"
                exit 0
                ;;
            *)
                echo -e "${RED}Invalid option${NC}"
                sleep 1
                ;;
        esac
    done
}

# Advanced options menu
show_advanced_options() {
    echo -e "\n${CYAN}🔧 Advanced Options:${NC}"
    echo -e "  • Backup system state before installation"
    echo -e "  • Enable resource monitoring during installation"
    echo -e "  • Generate detailed installation report"
    echo -e "  • Enable parallel processing optimizations"
    echo ""
    
    if interactive_confirm "Enable all advanced features?" "y" 30; then
        ENABLE_BACKUP="yes"
        ENABLE_MONITORING="yes"
        ENABLE_REPORTING="yes"
        ENABLE_PARALLEL="yes"
        ok "Advanced features enabled"
    else
        warn "Using standard installation mode"
    fi
    
    sleep 2
}

# Progress indicator for long operations
show_spinner() {
    local pid=$1
    local message="$2"
    local delay=0.1
    local spinstr='|/-\'
    
    while kill -0 "$pid" 2>/dev/null; do
        local temp=${spinstr#?}
        printf "\r${BLUE}[%c]${NC} %s" "$spinstr" "$message"
        local spinstr=$temp${spinstr%"$temp"}
        sleep $delay
    done
    printf "\r${GREEN}[✓]${NC} %s\n" "$message"
}

# Installation completion celebration
show_completion_celebration() {
    clear
    echo -e "${GREEN}"
    echo "    🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉"
    echo "    🎉                                                🎉"
    echo "    🎉         INSTALLATION COMPLETED!               🎉"
    echo "    🎉                                                🎉"
    echo "    🎉   Phynx Hosting Panel is ready to use! 🚀    🎉"
    echo "    🎉                                                🎉"
    echo "    🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉"
    echo -e "${NC}"
    
    local total_time=$(($(date +%s) - INSTALLATION_STATS[start_time]))
    echo -e "${CYAN}Installation completed in: ${GREEN}$(printf "%02d:%02d" $((total_time / 60)) $((total_time % 60)))${NC}"
    echo ""
}

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
    
    # Update package lists with live progress
    run_with_progress "apt-get update -y -qq" "Updating package lists" 120 || die "Failed to update package lists"
    
    # Upgrade existing packages with live progress
    run_with_progress "apt-get upgrade -y -qq" "Upgrading system packages" 300 || warn "Some packages failed to upgrade"
    
    # Install essential tools if not present
    apt-get install -y software-properties-common apt-transport-https ca-certificates curl wget gnupg lsb-release bc

    # ADD repo
    add-apt-repository ppa:ondrej/php -y || warn "Could not install PHP repositories"
    
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
        "php8.3-common"
        "php8.3-cli"
        "php8.3-gmp"
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
        "php8.4-common"
        "php8.4-cli"
        "php8.4-gmp"
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
    
    # Configure custom ports (port 80 is already default in Apache)
    if ! grep -q "Listen $HTTPS_PORT ssl" /etc/apache2/ports.conf; then
        echo "Listen $HTTPS_PORT ssl" >> /etc/apache2/ports.conf
    fi
    if ! grep -q "Listen $SECURE_PORT ssl" /etc/apache2/ports.conf; then
        echo "Listen $SECURE_PORT ssl" >> /etc/apache2/ports.conf
    fi
    
    # Set global ServerName to suppress FQDN warning
    if ! grep -q "^ServerName" /etc/apache2/apache2.conf; then
        echo "" >> /etc/apache2/apache2.conf
        echo "# Global ServerName directive to suppress FQDN warning" >> /etc/apache2/apache2.conf
        if [[ -n "$MAIN_DOMAIN" && "$MAIN_DOMAIN" != "localhost" ]]; then
            echo "ServerName www.$MAIN_DOMAIN" >> /etc/apache2/apache2.conf
            log "Set global ServerName to www.$MAIN_DOMAIN"
        else
            echo "ServerName $SERVER_IP" >> /etc/apache2/apache2.conf
            log "Set global ServerName to $SERVER_IP (fallback)"
        fi
    fi
    
    # Configure Apache for PHP-FPM
    systemctl enable apache2
    systemctl start apache2
    
    # Test Apache configuration and reload if needed
    if apache2ctl configtest >/dev/null 2>&1; then
        systemctl reload apache2
        log "Apache configuration validated and reloaded"
    else
        warn "Apache configuration test failed, but continuing installation"
    fi
    
    ok "Apache2 installed and configured"
}

# Fix Apache ServerName warning
fix_apache_servername() {
    if command -v apache2 >/dev/null 2>&1; then
        if ! grep -q "^ServerName" /etc/apache2/apache2.conf; then
            echo "" >> /etc/apache2/apache2.conf
            echo "# Global ServerName directive to suppress FQDN warning" >> /etc/apache2/apache2.conf
            if [[ -n "$MAIN_DOMAIN" && "$MAIN_DOMAIN" != "localhost" ]]; then
                echo "ServerName www.$MAIN_DOMAIN" >> /etc/apache2/apache2.conf
                log "Fixed Apache ServerName warning using domain: www.$MAIN_DOMAIN"
            else
                echo "ServerName $SERVER_IP" >> /etc/apache2/apache2.conf
                log "Fixed Apache ServerName warning using IP: $SERVER_IP"
            fi
            
            # Test and reload configuration
            if apache2ctl configtest >/dev/null 2>&1; then
                systemctl reload apache2 >/dev/null 2>&1
                log "Apache configuration reloaded successfully"
            fi
        fi
    fi
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
    
    if [[ -d "phynxadmin" ]]; then
        # Copy your custom Phynx files
        cp -r phynxadmin "$PMA_DIR"
        
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

# Create SSL certificate for the domain
create_ssl_certificate() {
    local domain="${1:-$MAIN_DOMAIN}"
    echo -e "${CYAN}Creating SSL certificate for $domain...${NC}"
    
    # Install certbot if not already installed
    if ! command -v certbot >/dev/null 2>&1; then
        echo -e "${YELLOW}Installing Certbot...${NC}"
        apt-get update -qq
        
        # Try snap installation first (preferred method)
        if command -v snap >/dev/null 2>&1; then
            apt-get install -y snapd
            snap install core 2>/dev/null || true
            snap refresh core 2>/dev/null || true
            if snap install --classic certbot 2>/dev/null; then
                ln -sf /snap/bin/certbot /usr/bin/certbot
                echo -e "${GREEN}✓ Certbot installed via snap${NC}"
            else
                # Fallback to apt installation
                apt-get install -y certbot python3-certbot-apache
                echo -e "${GREEN}✓ Certbot installed via apt${NC}"
            fi
        else
            # Direct apt installation if snap is not available
            apt-get install -y certbot python3-certbot-apache
            echo -e "${GREEN}✓ Certbot installed via apt${NC}"
        fi
    fi
    
    # Check if certificate already exists
    if [[ -d "/etc/letsencrypt/live/$domain" ]]; then
        echo -e "${GREEN}SSL certificate already exists for $domain${NC}"
        return 0
    fi
    
    # Try to get Let's Encrypt certificate
    echo -e "${YELLOW}Obtaining Let's Encrypt SSL certificate...${NC}"
    if certbot certonly --apache --non-interactive --agree-tos --email "$ADMIN_EMAIL" -d "$domain" -d "www.$domain" -d "$PANEL_SUBDOMAIN" -d "$PHYNXADMIN_SUBDOMAIN" 2>/dev/null; then
        echo -e "${GREEN}✓ Successfully obtained Let's Encrypt certificate${NC}"
        
        # Set up auto-renewal
        (crontab -l 2>/dev/null; echo "0 12 * * * /usr/bin/certbot renew --quiet") | crontab -
        
        return 0
    else
        echo -e "${YELLOW}Let's Encrypt failed, creating self-signed certificate...${NC}"
        
        # Create self-signed certificate as fallback
        SSL_DIR="/etc/ssl/certs"
        KEY_DIR="/etc/ssl/private"
        
        mkdir -p "$SSL_DIR" "$KEY_DIR"
        
        # Generate private key and certificate
        openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
            -keyout "$KEY_DIR/$domain.key" \
            -out "$SSL_DIR/$domain.crt" \
            -subj "/C=US/ST=State/L=City/O=Phynx Hosting Panel/OU=SSL Certificate/CN=$domain/emailAddress=$ADMIN_EMAIL"
        
        # Set proper permissions
        chmod 600 "$KEY_DIR/$domain.key"
        chmod 644 "$SSL_DIR/$domain.crt"
        
        echo -e "${GREEN}✓ Created self-signed SSL certificate${NC}"
        echo -e "${YELLOW}Note: Self-signed certificates will show security warnings in browsers${NC}"
        echo -e "${YELLOW}Consider getting a proper SSL certificate later${NC}"
        
        return 0
    fi
}

# Create web server configuration
configure_web_server() {
    if [[ "$WEB_SERVER" == "nginx" ]]; then
        configure_nginx_vhost
    else
        configure_apache_vhost
        # Fix Apache ServerName warning if not already fixed
        fix_apache_servername
    fi
}

configure_apache_vhost() {
    log "Creating Apache virtual host configuration for multiple domains and ports..."
    
    cat > "$APACHE_SITE" << EOF
# Main website - phynx.one (HTTP)
<VirtualHost *:80>
    ServerName www.$MAIN_DOMAIN
    ServerAlias $SERVER_IP
    DocumentRoot /var/www/html
    
    # Admin panel aliases
    Alias /panel "$PANEL_DIR"
    Alias /phynxadmin "$PMA_DIR"
    
    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    
    # Main website directory
    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # PHP-FPM configuration
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Admin panel directory
    <Directory "$PANEL_DIR">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # PHP-FPM configuration
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # PhynxAdmin directory
    <Directory "$PMA_DIR">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Security restrictions
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
    ErrorLog \${APACHE_LOG_DIR}/${MAIN_DOMAIN}_error.log
    CustomLog \${APACHE_LOG_DIR}/${MAIN_DOMAIN}_access.log combined
</VirtualHost>

# Admin panel subdomain - panel.phynx.one (HTTP)
<VirtualHost *:80>
    ServerName $PANEL_SUBDOMAIN
    DocumentRoot $PANEL_DIR
    
    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    
    <Directory "$PANEL_DIR">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # PHP-FPM configuration
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Security restrictions
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
    ErrorLog \${APACHE_LOG_DIR}/${PANEL_SUBDOMAIN}_error.log
    CustomLog \${APACHE_LOG_DIR}/${PANEL_SUBDOMAIN}_access.log combined
</VirtualHost>

# Database manager subdomain - phynxadmin.phynx.one (HTTP)
<VirtualHost *:80>
    ServerName $PHYNXADMIN_SUBDOMAIN
    DocumentRoot $PMA_DIR
    
    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    
    <Directory "$PMA_DIR">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Security restrictions
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
    ErrorLog \${APACHE_LOG_DIR}/${PHYNXADMIN_SUBDOMAIN}_error.log
    CustomLog \${APACHE_LOG_DIR}/${PHYNXADMIN_SUBDOMAIN}_access.log combined
</VirtualHost>

# SSL VirtualHosts (443 for standard HTTPS)
<IfModule mod_ssl.c>
# Main website HTTPS - phynx.one:443
<VirtualHost *:443>
    ServerName www.$MAIN_DOMAIN
    ServerAlias $MAIN_DOMAIN
    DocumentRoot /var/www/html
    
    # Admin panel aliases
    Alias /panel "$PANEL_DIR"
    Alias /phynxadmin "$PMA_DIR"
    
    # SSL Configuration (will be managed by Certbot)
    SSLEngine on
    # SSLCertificateFile and SSLCertificateKeyFile will be added by Certbot
    
    # Security headers
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    
    # Main website directory
    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Admin panel directory
    <Directory "$PANEL_DIR">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # PhynxAdmin directory
    <Directory "$PMA_DIR">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Security restrictions
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
    ErrorLog \${APACHE_LOG_DIR}/${MAIN_DOMAIN}_ssl_error.log
    CustomLog \${APACHE_LOG_DIR}/${MAIN_DOMAIN}_ssl_access.log combined
</VirtualHost>

# Admin panel subdomain HTTPS - panel.phynx.one:443
<VirtualHost *:443>
    ServerName $PANEL_SUBDOMAIN
    DocumentRoot $PANEL_DIR
    
    # SSL Configuration (will be managed by Certbot)
    SSLEngine on
    
    # Security headers
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    
    <Directory "$PANEL_DIR">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Security restrictions
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
    ErrorLog \${APACHE_LOG_DIR}/${PANEL_SUBDOMAIN}_ssl_error.log
    CustomLog \${APACHE_LOG_DIR}/${PANEL_SUBDOMAIN}_ssl_access.log combined
</VirtualHost>

# Secure admin panel - phynx.one:2083
<VirtualHost *:$SECURE_PORT>
    ServerName www.$MAIN_DOMAIN
    DocumentRoot $PANEL_DIR
    
    # SSL Configuration (will be managed by Certbot)
    SSLEngine on
    
    # Security headers
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    
    <Directory "$PANEL_DIR">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
        </FilesMatch>
    </Directory>
    
    # Security restrictions
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
    ErrorLog \${APACHE_LOG_DIR}/${MAIN_DOMAIN}_secure_error.log
    CustomLog \${APACHE_LOG_DIR}/${MAIN_DOMAIN}_secure_access.log combined
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
    log "Creating Nginx server blocks for multiple domains and ports..."
    
    cat > "$NGINX_SITE" << EOF
# Main website - phynx.one (HTTP)
server {
    listen 80;
    server_name $MAIN_DOMAIN www.$MAIN_DOMAIN $SERVER_IP;
    root /var/www/html;
    index index.php index.html index.htm;
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    # Admin panel alias
    location /panel {
        alias $PANEL_DIR;
        try_files \$uri \$uri/ /panel/index.php?\$query_string;
        
        location ~ /panel/.*\\.php\$ {
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $PANEL_DIR\$fastcgi_script_name;
            include fastcgi_params;
        }
    }
    
    # PhynxAdmin alias
    location /phynxadmin {
        alias $PMA_DIR;
        try_files \$uri \$uri/ /phynxadmin/index.php?\$query_string;
        
        location ~ /phynxadmin/.*\\.php\$ {
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $PMA_DIR\$fastcgi_script_name;
            include fastcgi_params;
        }
    }
    
    # Main website location
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    
    # PHP processing for main site
    location ~ \\.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\\.php)(/.+)\$;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Security restrictions
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

# Admin panel subdomain - panel.phynx.one
server {
    listen 80;
    server_name $PANEL_SUBDOMAIN;
    root $PANEL_DIR;
    index index.php index.html;
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    
    location ~ \\.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\\.php)(/.+)\$;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Security restrictions
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

# Database manager subdomain - phynxadmin.phynx.one
server {
    listen 80;
    server_name $PHYNXADMIN_SUBDOMAIN;
    root $PMA_DIR;
    index index.php index.html;
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    
    location ~ \\.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\\.php)(/.+)\$;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Security restrictions
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

# HTTPS configurations (will be enhanced by SSL setup)
# Main website HTTPS - phynx.one:443
server {
    listen 443 ssl;
    server_name $MAIN_DOMAIN www.$MAIN_DOMAIN;
    root /var/www/html;
    index index.php index.html;
    
    # SSL configuration will be added by Certbot
    
    # Security headers for HTTPS
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    # Admin panel alias
    location /panel {
        alias $PANEL_DIR;
        try_files \$uri \$uri/ /panel/index.php?\$query_string;
        
        location ~ /panel/.*\\.php\$ {
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $PANEL_DIR\$fastcgi_script_name;
            include fastcgi_params;
        }
    }
    
    # PhynxAdmin alias
    location /phynxadmin {
        alias $PMA_DIR;
        try_files \$uri \$uri/ /phynxadmin/index.php?\$query_string;
        
        location ~ /phynxadmin/.*\\.php\$ {
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $PMA_DIR\$fastcgi_script_name;
            include fastcgi_params;
        }
    }
    
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    
    location ~ \\.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\\.php)(/.+)\$;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Security restrictions
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

# Secure admin panel - phynx.one:2083
server {
    listen $SECURE_PORT ssl;
    server_name $MAIN_DOMAIN;
    root $PANEL_DIR;
    index index.php index.html;
    
    # SSL configuration will be added by Certbot
    
    # Security headers for HTTPS
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    
    location ~ \\.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\\.php)(/.+)\$;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Security restrictions
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
    ufw allow $SECURE_PORT/tcp
    ufw allow http
    ufw allow https
    
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
    sed -i "s/TCP_IN = .*/TCP_IN = \"22,53,80,$HTTPS_PORT,$SECURE_PORT,993,995\"/" /etc/csf/csf.conf
    sed -i "s/TCP_OUT = .*/TCP_OUT = \"22,25,53,80,110,$HTTPS_PORT,$SECURE_PORT,587,993,995\"/" /etc/csf/csf.conf
    
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
port = 80,$HTTPS_PORT,$SECURE_PORT
logpath = %(apache_error_log)s

[apache-badbots]
enabled = true
port = 80,$HTTPS_PORT,$SECURE_PORT
logpath = %(apache_access_log)s
bantime = 86400
maxretry = 1

[apache-noscript]
enabled = true
port = 80,$HTTPS_PORT,$SECURE_PORT
logpath = %(apache_access_log)s

[apache-overflows]
enabled = true
port = 80,$HTTPS_PORT,$SECURE_PORT
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
    echo "1. Point your domain DNS to this server's IP: $SERVER_IP"
    echo "   - A record: $MAIN_DOMAIN -> $SERVER_IP"
    echo "   - A record: *.$MAIN_DOMAIN -> $SERVER_IP"
    echo "2. Run wildcard SSL: certbot --$WEB_SERVER -d $MAIN_DOMAIN -d *.$MAIN_DOMAIN --preferred-challenges dns"
    echo "3. Visit your panel URLs to complete setup:"
    echo "   - Main site: https://$MAIN_DOMAIN"
    echo "   - Admin panel: https://$PANEL_SUBDOMAIN or https://$MAIN_DOMAIN/panel"
    echo "   - Database: https://$PHYNXADMIN_SUBDOMAIN or https://$MAIN_DOMAIN/phynxadmin"
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

To enable HTTPS with wildcard certificate:
certbot --$WEB_SERVER -d $MAIN_DOMAIN -d *.$MAIN_DOMAIN --email $ADMIN_EMAIL --agree-tos --non-interactive --preferred-challenges dns

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
            --setup-dns)
                SETUP_DNS_ZONES="yes"
                shift
                ;;
            --no-dns)
                SETUP_DNS_ZONES="no"
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
    echo "  --domain=example.com        Set main domain (creates *.domain structure)"
    echo "  --email=admin@example.com   Set admin email address"
    echo "  --http-port=PORT            Set custom HTTP port (default: 80)"
    echo "  --https-port=PORT           Set custom HTTPS port (default: 443)"
    echo "  --secure-port=PORT          Set secure admin port (default: 2083)"
    echo "  --no-pma                    Skip custom Phynx deployment"
    echo "  --no-bind                   Skip BIND9 DNS server installation"
    echo "  --csf                       Install CSF/LFD instead of UFW firewall"
    echo "  --setup-dns                 Automatically create DNS zones (default: yes)"
    echo "  --no-dns                    Skip automatic DNS zone creation"
    echo "  --silent                    Skip interactive prompts (use defaults)"
    echo "  --help, -h                  Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0                                    # Interactive installation (uses phynx.one)"
    echo "  $0 --domain=yourdomain.com           # Creates *.yourdomain.com structure"
    echo "  $0 --web-server=nginx --domain=hosting.company.com"
    echo "  $0 --no-pma --csf                   # Skip phpMyAdmin, use CSF firewall"
    echo "  $0 --domain=server.net --secure-port=8443 --email=admin@server.net"
}

# Parse command line arguments
parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --web-server=*)
                WEB_SERVER="${1#*=}"
                ;;
            --domain=*)
                MAIN_DOMAIN="${1#*=}"
                PANEL_SUBDOMAIN="panel.$MAIN_DOMAIN"
                PHYNXADMIN_SUBDOMAIN="phynxadmin.$MAIN_DOMAIN"
                PANEL_DOMAIN="$MAIN_DOMAIN"
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
            --secure-port=*)
                SECURE_PORT="${1#*=}"
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
        echo -e "${YELLOW}🌐 Domain Configuration:${NC}"
        echo "Current main domain: $MAIN_DOMAIN"
        echo "This will create:"
        echo "  • Main site: $MAIN_DOMAIN"
        echo "  • Admin panel: $PANEL_SUBDOMAIN"
        echo "  • Database manager: $PHYNXADMIN_SUBDOMAIN"
        echo "  • Server IP access: $SERVER_IP"
        echo ""
        read -p "Enter your custom main domain (or press Enter to use $MAIN_DOMAIN): " custom_domain
        if [[ -n "$custom_domain" ]]; then
            MAIN_DOMAIN="$custom_domain"
            PANEL_SUBDOMAIN="panel.$MAIN_DOMAIN"
            PHYNXADMIN_SUBDOMAIN="phynxadmin.$MAIN_DOMAIN"
            PANEL_DOMAIN="$MAIN_DOMAIN"
            echo -e "${GREEN}✓${NC} Domain structure updated:"
            echo "  • Main site: $MAIN_DOMAIN"
            echo "  • Admin panel: $PANEL_SUBDOMAIN"
            echo "  • Database manager: $PHYNXADMIN_SUBDOMAIN"
        else
            echo -e "${YELLOW}!${NC} Using default domain structure for $MAIN_DOMAIN"
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
    echo "Current HTTP port: 80 (standard)"
    echo "Current HTTPS port: $HTTPS_PORT"
    echo ""
    read -p "Do you want to use a different HTTPS port? [y/N]: " -n 1 -r change_ports
    echo ""
    if [[ $change_ports =~ ^[Yy]$ ]]; then
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
    echo -e "${GREEN}║              Installation Complete!               ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${CYAN}🎉 Phynx Hosting Panel has been successfully installed!${NC}"
    echo ""
    echo -e "${YELLOW}🌐 Access URLs:${NC}"
    echo -e "${CYAN}Main Website:${NC}"
    echo -e "• ${GREEN}HTTP${NC}:  http://$MAIN_DOMAIN (IP: http://$SERVER_IP)"
    echo -e "• ${GREEN}HTTPS${NC}: https://$MAIN_DOMAIN (after SSL setup)"
    echo ""
    echo -e "${CYAN}Admin Panel Access:${NC}"
    echo -e "• ${GREEN}Subdomain${NC}: http://$PANEL_SUBDOMAIN"
    echo -e "• ${GREEN}Directory${NC}: http://$MAIN_DOMAIN/panel"
    echo -e "• ${GREEN}Secure Port${NC}: https://$MAIN_DOMAIN:$SECURE_PORT (after SSL)"
    echo ""
    echo -e "${CYAN}Database Manager Access:${NC}"
    if [[ -d "$PMA_DIR" ]]; then
        echo -e "• ${GREEN}Subdomain${NC}: http://$PHYNXADMIN_SUBDOMAIN"
        echo -e "• ${GREEN}Directory${NC}: http://$MAIN_DOMAIN/phynxadmin"
    fi
    echo ""
    echo -e "${YELLOW}Default Admin Credentials:${NC}"
    echo -e "• ${GREEN}Username${NC}: admin"
    echo -e "• ${GREEN}Password${NC}: admin123 (please change immediately)"
    echo ""
    echo -e "${YELLOW}Next Steps:${NC}"
    echo -e "1. Set up DNS records for your domain:"
    echo -e "   • Point $MAIN_DOMAIN to $SERVER_IP"
    echo -e "   • Point *.$MAIN_DOMAIN to $SERVER_IP" 
    echo -e "2. Install wildcard SSL certificate:"
    echo -e "   • certbot --$WEB_SERVER -d $MAIN_DOMAIN -d *.$MAIN_DOMAIN --preferred-challenges dns"
    echo -e "3. Change the default admin password"
    echo -e "4. Review firewall settings"
    echo ""
    echo -e "${CYAN}Log file: $LOG_FILE${NC}"
}

# ===============================
# Main Installation Process
# ===============================

main() {
    # Initialize advanced logging
    initialize_logging
    
    # Show enhanced banner
    print_banner
    
    # Parse command line arguments
    parse_arguments "$@"
    
    # Interactive configuration menu (if not in silent mode)
    if [[ "$SILENT_MODE" != "yes" ]]; then
        interactive_menu
    fi
    
    # Pre-installation checks
    log_structured "INFO" "initialization" "Starting Phynx Panel Enhanced Installation with Advanced Features"
    require_root
    check_ubuntu_version
    
    # Initialize installation tracking
    initialize_installation_stats
    
    # Start enhanced installation process
    install_phynx
    
    # Show completion summary
    show_installation_complete_info
    display_installation_summary
    
    log "Installation process completed successfully!"
}

# ===============================
# Helper Functions for Enhanced Installation
# ===============================

# Initialize logging system
initialize_logging() {
    # Create logs directory
    LOGS_DIR="/var/log/phynx-install"
    mkdir -p "$LOGS_DIR"
    
    # Set log file paths
    LOG_FILE="$LOGS_DIR/install-$(date +%Y%m%d-%H%M%S).log"
    ERROR_LOG="$LOGS_DIR/errors.log"
    PERFORMANCE_LOG="$LOGS_DIR/performance.log"
    
    # Initialize log files
    touch "$LOG_FILE" "$ERROR_LOG" "$PERFORMANCE_LOG"
    chmod 644 "$LOG_FILE" "$ERROR_LOG" "$PERFORMANCE_LOG"
    
    # Set global variables for logging
    INSTALLATION_STATS[log_file]="$LOG_FILE"
    INSTALLATION_STATS[error_log]="$ERROR_LOG"
    INSTALLATION_STATS[performance_log]="$PERFORMANCE_LOG"
    
    log_structured "INFO" "system" "Advanced logging system initialized"
}

# Initialize installation statistics
initialize_installation_stats() {
    declare -gA INSTALLATION_STATS
    declare -ga ROLLBACK_OPERATIONS
    INSTALLATION_STATS[start_time]=$(date +%s)
    INSTALLATION_STATS[steps_completed]=0
    INSTALLATION_STATS[total_steps]=10
    INSTALLATION_STATS[operations]=0
    ROLLBACK_OPERATIONS=()
    
    set_total_steps
    log_structured "INFO" "stats" "Installation statistics initialized"
}

# Set total steps for progress calculation
set_total_steps() {
    local steps=10
    
    [[ "$INSTALL_PMA" == "yes" ]] && ((steps++))
    [[ "$INSTALL_BIND" == "yes" ]] && ((steps++))
    [[ "$INSTALL_CSF" == "yes" ]] && ((steps++))
    [[ "$SETUP_DNS_ZONES" == "yes" ]] && ((steps++))
    
    TOTAL_STEPS=$steps
    INSTALLATION_STATS[total_steps]=$steps
}

# Estimate installation time
estimate_installation_time() {
    local base_time=900  # 15 minutes base
    local extra_time=0
    
    [[ "$INSTALL_PMA" == "yes" ]] && ((extra_time += 180))     # 3 minutes
    [[ "$INSTALL_BIND" == "yes" ]] && ((extra_time += 300))    # 5 minutes
    [[ "$INSTALL_CSF" == "yes" ]] && ((extra_time += 240))     # 4 minutes
    [[ "$SETUP_DNS_ZONES" == "yes" ]] && ((extra_time += 180))  # 3 minutes
    [[ "$ENABLE_BACKUP" == "yes" ]] && ((extra_time += 120))   # 2 minutes
    
    local total_time=$((base_time + extra_time))
    local minutes=$((total_time / 60))
    local seconds=$((total_time % 60))
    
    echo -e "   • Estimated time: ${GREEN}~${minutes}m ${seconds}s${NC}"
    echo -e "   • Complexity: $(if [[ $extra_time -gt 300 ]]; then echo "${YELLOW}High${NC}"; elif [[ $extra_time -gt 120 ]]; then echo "${BLUE}Medium${NC}"; else echo "${GREEN}Low${NC}"; fi)"
}

# Enhanced installation process with all advanced features
install_phynx() {
    INSTALLATION_STATS[start_time]=$(date +%s)
    
    # Initialize progress tracking
    show_progress 1 14 "Initializing installation" "Setting up installation environment..."
    
    # System backup if enabled
    if [[ "$ENABLE_BACKUP" == "yes" ]]; then
        create_system_backup
        track_operation "system_backup"
    fi
    
    # System validation
    show_step_header 1 "System Validation"
    show_progress 2 14 "Validating system requirements" "Running system compatibility checks..."
    validate_system
    validate_dependencies
    
    # Show installation summary
    if [[ "$SILENT_MODE" != "yes" ]]; then
        show_installation_summary
    fi
    
    show_progress 3 14 "Preparing installation environment" "Setting up directories and permissions..."
    add_operation "installation_prep"
    
    # Core system setup
    show_step_header 2 "Core System Setup"
    show_progress 4 14 "Updating system and installing core packages" "Installing dependencies and updates..."
    update_system
    install_core_packages
    track_operation "core_setup"
    
    # Web server and database setup
    show_step_header 3 "Web Server and Database Setup"  
    show_progress 5 14 "Installing web server and database" "Setting up Apache, MySQL, PHP and related services..."
    install_mysql_server
    install_web_server
    secure_mysql_installation
    track_operation "server_setup"
    
    # SSL and web server configuration
    show_step_header 4 "SSL and Web Server Configuration"
    show_progress 6 14 "Configuring SSL certificates and virtual hosts" "Setting up SSL and domain configurations..."
    create_ssl_certificate "$MAIN_DOMAIN"
    configure_web_server
    track_operation "web_config"
    
    # Panel installation
    show_step_header 5 "Panel Installation"
    show_progress 7 14 "Installing Phynx panel files and configuration" "Copying panel files and configuring database..."
    install_panel_files
    create_environment_config
    configure_php
    track_operation "panel_install"
    
    # Optional components
    local component_progress=65
    
    if [[ "$INSTALL_PMA" == "yes" ]]; then
        show_step_header 6 "Installing Database Manager"
        show_progress 7 14 "Installing custom PhynxAdmin" "Deploying database manager interface..."
        deploy_custom_pma
        track_operation "phynxadmin_install"
        ((component_progress += 5))
    fi
    
    if [[ "$INSTALL_BIND" == "yes" ]]; then
        show_step_header 7 "Installing DNS Server"
        show_progress 7 14 "Installing BIND9 DNS server" "Setting up DNS service and configuration..."
        install_bind9
        track_operation "bind9_install"
        ((component_progress += 5))
    fi
    
    # DNS Zone Setup
    if [[ "$SETUP_DNS_ZONES" == "yes" ]]; then
        show_step_header 8 "DNS Zone Configuration"
        show_progress 8 14 "Creating DNS zones and records" "Configuring BIND9 and creating zone files..."
        setup_dns_zones
        create_dns_management_tools
        track_operation "dns_setup"
        ((component_progress += 5))
    fi
    
    # Security setup
    show_step_header $(if [[ "$SETUP_DNS_ZONES" == "yes" ]]; then echo "9"; else echo "8"; fi) "Security Configuration"
    show_progress 9 14 "Configuring firewall and security" "Setting up security configurations..."
    configure_firewall
    configure_fail2ban
    
    if [[ "$INSTALL_CSF" == "yes" ]]; then
        show_progress 10 14 "Installing CSF firewall" "Configuring advanced firewall protection..."
        install_csf
        track_operation "csf_install"
    fi
    
    track_operation "security_setup"
    
    # Final configuration
    show_step_header $(if [[ "$SETUP_DNS_ZONES" == "yes" ]]; then echo "10"; else echo "9"; fi) "Final Configuration"
    show_progress 11 14 "Setting up cron jobs and importing schema" "Configuring database schema and scheduled tasks..."
    setup_cron_jobs
    import_database_schema
    track_operation "final_config"
    
    # System optimization and health checks
    show_step_header $(if [[ "$SETUP_DNS_ZONES" == "yes" ]]; then echo "11"; else echo "10"; fi) "System Optimization"
    show_progress 12 14 "Optimizing system and performing health checks" "Running final optimizations and validations..."
    optimize_system
    perform_health_check
    
    show_progress 13 14 "Installation completed successfully" "All components installed and configured!"
    
    # Calculate final statistics
    INSTALLATION_STATS[end_time]=$(date +%s)
    INSTALLATION_STATS[total_time]=$((INSTALLATION_STATS[end_time] - INSTALLATION_STATS[start_time]))
    
    # Generate reports if enabled
    if [[ "$ENABLE_REPORTING" == "yes" ]]; then
        generate_installation_report
    fi
    
    # DNS propagation monitoring and instructions
    if [[ "$SETUP_DNS_ZONES" == "yes" ]]; then
        show_dns_completion_info
        
        # Optional DNS propagation monitoring
        if interactive_confirm "Monitor DNS propagation now?" "y" 30; then
            monitor_dns_propagation
        else
            show_external_dns_instructions
        fi
    fi
    
    # Show completion
    show_completion_celebration
}

# Run main installation
main "$@"