#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

# ========= Utilities =========
msg() { printf '%s\n' "$*"; }
warn() { printf 'WARN: %s\n' "$*" >&2; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
need_cmd() { command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"; }

# Cleanup trap
TMPDIR="$(mktemp -d)"
on_exit() { rm -rf "$TMPDIR"; }
trap on_exit EXIT

confirm() {
  local prompt="${1:-Continue?}"
  local reply
  while true; do
    read -r -p "$prompt [y/N]: " reply || reply=""
    case "$reply" in
      [Yy][Ee][Ss]|[Yy]) return 0 ;;
      [Nn][Oo]|[Nn]|"") return 1 ;;
      *) warn "Please answer y or n." ;;
    esac
  done
}

usage() {
  cat <<'EOF'
Usage: ./install.sh [options]

Interactive by default. In CI/non-interactive mode, provide values via flags or environment variables.

Options:
  -y, --yes                 Assume yes to prompts
  --non-interactive         Non-interactive mode (fail if required inputs missing)
  --fqdn <value>            Fully qualified domain name (e.g., panel.example.com)
  --ip <value>              Server IP (auto-detected if omitted)
  --admin-port <port>       Admin port (default: 2087)
  --client-port <port>      Client port (default: 2083)
  --prefix <dir>            Install directory (default: /opt/hosting-panel)
  --db-host <host>          Database host (default: localhost)
  --db-user <user>          Database user (default: hosting)
  --db-pass <pass>          Database password (required)
  --db-backend <backend>    Database backend: mysql, postgres, sqlite (default: mysql)
  --branch <branch>         Git branch to download (default: main)
  --skip-download           Skip downloading, use existing panel.tar
  -h, --help                Show this help

Environment variables (override defaults/flags):
  ASSUME_YES, NONINTERACTIVE, FQDN, SERVER_IP, ADMIN_PORT, CLIENT_PORT, PREFIX, DB_HOST, DB_USER, DB_PASS, DB_BACKEND, GIT_BRANCH, SKIP_DOWNLOAD

Examples:
  Interactive:
    ./install.sh

  Non-interactive:
    NONINTERACTIVE=1 ASSUME_YES=1 FQDN=panel.example.com SERVER_IP=203.0.113.10 PREFIX=/opt/hosting-panel DB_HOST=db DB_USER=hosting DB_PASS=secret DB_BACKEND=mysql ./install.sh
    ./install.sh --non-interactive -y --fqdn panel.example.com --ip 203.0.113.10 --prefix /opt/hosting-panel --db-host db --db-user hosting --db-pass secret --db-backend mysql
EOF
}

# ========= Validation =========
validate_fqdn() {
  # RFC-ish validation: labels 1-63 chars, alnum + hyphen (no start/end hyphen), at least one dot, TLD 2-63
  local fqdn="$1"
  [[ "$fqdn" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])\.)+[A-Za-z]{2,63}$ ]]
}

is_valid_ip() {
  local ip="$1"
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  IFS='.' read -r o1 o2 o3 o4 <<<"$ip"
  for o in "$o1" "$o2" "$o3" "$o4"; do
    # shellcheck disable=SC2317
    { [[ "$o" -ge 0 && "$o" -le 255 ]]; } || return 1
  done
  return 0
}

is_valid_port() {
  local p="$1"
  [[ "$p" =~ ^[0-9]+$ ]] || return 1
  [[ "$p" -ge 1 && "$p" -le 65535 ]]
}

# ========= Detection =========
detect_ip() {
  local ip=""
  if command -v ip >/dev/null 2>&1; then
    ip="$(ip route get 1.1.1.1 2>/dev/null | awk '/src/ { for (i=1; i<=NF; i++) if ($i=="src") { print $(i+1); exit } }')" || true
  fi
  if [[ -z "${ip:-}" ]] && command -v hostname >/dev/null 2>&1; then
    ip="$(hostname -I 2>/dev/null | awk '{print $1}')" || true
  fi
  if [[ -z "${ip:-}" ]] && command -v ifconfig >/dev/null 2>&1; then
    ip="$(ifconfig 2>/dev/null | awk '/inet / && $2!="127.0.0.1" {print $2; exit}')" || true
  fi
  # Windows/Git-Bash fallbacks
  if [[ -z "${ip:-}" ]] && command -v ipconfig >/dev/null 2>&1; then
    ip="$(ipconfig 2>/dev/null | sed -n 's/.*IPv4[^:]*: *\([0-9.]\+\).*/\1/p' | head -n1)" || true
  fi
  if [[ -z "${ip:-}" ]] && command -v netsh >/dev/null 2>&1; then
    ip="$(netsh interface ipv4 show addresses 2>/dev/null | sed -n 's/.*IP Address: *\([0-9.]\+\).*/\1/p' | head -n1)" || true
  fi
  # Public IP fallback
  if [[ -z "${ip:-}" ]] && command -v curl >/dev/null 2>&1; then
    ip="$(curl -fsSL https://api.ipify.org || true)"
  fi
  printf '%s\n' "${ip:-}"
}

# ========= Download functions =========
download_panel() {
  local repo_url="https://github.com/savagemiguel/phynx.git"
  local branch="${GIT_BRANCH:-main}"
  local temp_dir="$TMPDIR/phynx-download"
  
  msg "Downloading panel from GitHub repository..."
  msg "Repository: $repo_url"
  msg "Branch: $branch"
  
  # Try git clone first
  if command -v git >/dev/null 2>&1; then
    msg "Using git to clone repository..."
    if git clone --depth 1 --branch "$branch" "$repo_url" "$temp_dir" 2>/dev/null; then
      create_tar_from_git "$temp_dir"
      return 0
    else
      warn "Git clone failed, trying alternative download methods..."
    fi
  fi
  
  # Fallback to curl/wget for tarball download
  if command -v curl >/dev/null 2>&1; then
    msg "Using curl to download tarball..."
    local tarball_url="https://github.com/savagemiguel/phynx/archive/refs/heads/${branch}.tar.gz"
    if curl -fsSL "$tarball_url" -o "$TMPDIR/phynx.tar.gz"; then
      extract_and_create_tar "$TMPDIR/phynx.tar.gz"
      return 0
    else
      warn "Curl download failed, trying wget..."
    fi
  fi
  
  if command -v wget >/dev/null 2>&1; then
    msg "Using wget to download tarball..."
    local tarball_url="https://github.com/savagemiguel/phynx/archive/refs/heads/${branch}.tar.gz"
    if wget -q "$tarball_url" -O "$TMPDIR/phynx.tar.gz"; then
      extract_and_create_tar "$TMPDIR/phynx.tar.gz"
      return 0
    else
      warn "Wget download failed."
    fi
  fi
  
  die "Failed to download panel. Please ensure you have git, curl, or wget installed and internet connectivity."
}

create_tar_from_git() {
  local git_dir="$1"
  msg "Creating panel.tar from git repository..."
  
  # Remove .git directory and create tar
  rm -rf "$git_dir/.git"
  
  # Create tar archive
  if tar -cf panel.tar -C "$git_dir" .; then
    msg "Successfully created panel.tar from git repository."
  else
    die "Failed to create tar archive from git repository."
  fi
}

extract_and_create_tar() {
  local tarball="$1"
  local extract_dir="$TMPDIR/extracted"
  
  msg "Extracting downloaded tarball..."
  mkdir -p "$extract_dir"
  
  if tar -xzf "$tarball" -C "$extract_dir" --strip-components=1; then
    msg "Creating panel.tar..."
    if tar -cf panel.tar -C "$extract_dir" .; then
      msg "Successfully created panel.tar from downloaded tarball."
    else
      die "Failed to create tar archive from extracted files."
    fi
  else
    die "Failed to extract downloaded tarball."
  fi
}

# ========= Prompt helpers =========
ask() {
  local prompt="$1" default="${2:-}" input
  if [[ -n "$default" ]]; then
    read -r -p "$prompt [$default]: " input
    printf '%s\n' "${input:-$default}"
  else
    read -r -p "$prompt: " input
    printf '%s\n' "$input"
  fi
}

ask_required() {
  local prompt="$1" input
  while true; do
    read -r -p "$prompt: " input
    [[ -n "$input" ]] && { printf '%s\n' "$input"; return; }
    warn "This value is required."
  done
}

ask_secret() {
  local prompt="$1" input
  read -r -s -p "$prompt: " input; printf '\n' >&2
  printf '%s\n' "$input"
}

prompt_or_use() {
  # $1 varname, $2 prompt, $3 default, $4 required(0/1), $5 secret(0/1)
  local var="$1" prompt="$2" def="${3:-}" required="${4:-0}" secret="${5:-0}" val
  eval "val=\${$var:-}"
  if [[ -n "$val" ]]; then
    printf -v "$var" '%s' "$val"
    return
  fi
  if [[ "$NONINTERACTIVE" == "1" ]]; then
    if [[ "$required" == "1" && -z "$def" ]]; then
      die "Missing required value for $var in non-interactive mode. Provide via flag or env."
    fi
    printf -v "$var" '%s' "${def}"
    return
  fi
  if [[ "$secret" == "1" ]]; then
    val="$(ask_secret "$prompt")"
    [[ -z "$val" && -n "$def" ]] && val="$def"
    if [[ "$required" == "1" && -z "$val" ]]; then
      warn "Value required."; prompt_or_use "$var" "$prompt" "$def" "$required" "$secret"; return
    fi
    printf -v "$var" '%s' "$val"
    return
  fi
  if [[ "$required" == "1" && -z "$def" ]]; then
    val="$(ask_required "$prompt")"
  else
    val="$(ask "$prompt" "$def")"
  fi
  printf -v "$var" '%s' "$val"
}

# ========= Defaults from env =========
ASSUME_YES="${ASSUME_YES:-0}"
NONINTERACTIVE="${NONINTERACTIVE:-0}"
FQDN="${FQDN:-}"
SERVER_IP="${SERVER_IP:-}"
ADMIN_PORT="${ADMIN_PORT:-2087}"
CLIENT_PORT="${CLIENT_PORT:-2083}"
PREFIX="${PREFIX:-/opt/hosting-panel}"
DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-hosting}"
DB_PASS="${DB_PASS:-}"
DB_BACKEND="${DB_BACKEND:-mysql}"
GIT_BRANCH="${GIT_BRANCH:-main}"
SKIP_DOWNLOAD="${SKIP_DOWNLOAD:-0}"

# ========= Arg parsing =========
while [[ $# -gt 0 ]]; do
  case "$1" in
    -y|--yes) ASSUME_YES=1 ;;
    --non-interactive) NONINTERACTIVE=1 ;;
    --fqdn) FQDN="${2:-}"; shift ;;
    --fqdn=*) FQDN="${1#*=}" ;;
    --ip) SERVER_IP="${2:-}"; shift ;;
    --ip=*) SERVER_IP="${1#*=}" ;;
    --admin-port) ADMIN_PORT="${2:-}"; shift ;;
    --admin-port=*) ADMIN_PORT="${1#*=}" ;;
    --client-port) CLIENT_PORT="${2:-}"; shift ;;
    --client-port=*) CLIENT_PORT="${1#*=}" ;;
    --prefix) PREFIX="${2:-}"; shift ;;
    --prefix=*) PREFIX="${1#*=}" ;;
    --db-host) DB_HOST="${2:-}"; shift ;;
    --db-host=*) DB_HOST="${1#*=}" ;;
    --db-user) DB_USER="${2:-}"; shift ;;
    --db-user=*) DB_USER="${1#*=}" ;;
    --db-pass) DB_PASS="${2:-}"; shift ;;
    --db-pass=*) DB_PASS="${1#*=}" ;;
    --db-backend) DB_BACKEND="${2:-}"; shift ;;
    --db-backend=*) DB_BACKEND="${1#*=}" ;;
    --branch) GIT_BRANCH="${2:-}"; shift ;;
    --branch=*) GIT_BRANCH="${1#*=}" ;;
    --skip-download) SKIP_DOWNLOAD=1 ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown argument: $1. Use --help for usage." ;;
  esac
  shift
done

# ========= Validate defaults =========
if ! is_valid_port "$ADMIN_PORT"; then
  die "Invalid admin port: $ADMIN_PORT"
fi
if ! is_valid_port "$CLIENT_PORT"; then
  die "Invalid client port: $CLIENT_PORT"
fi

# ========= Interactive prompts =========
if [[ "$NONINTERACTIVE" != "1" ]]; then
  msg "=== Phynx Hosting Panel Installer ==="
  msg ""
  
  # FQDN prompt with validation
  local_fqdn="${FQDN:-}"
  while true; do
    if [[ -z "$local_fqdn" ]]; then
      read -r -p "Enter the FQDN for this panel (e.g., panel.example.com): " local_fqdn || true
    fi
    if [[ -n "$local_fqdn" && "$(tr -d '[:space:]' <<<"$local_fqdn")" == "$local_fqdn" ]] && validate_fqdn "$local_fqdn"; then
      FQDN="$local_fqdn"
      break
    fi
    warn "Invalid FQDN. Use a valid hostname like 'panel.example.com'."
    local_fqdn=""
  done

  # IP detection + editable prompt
  detected_ip="$(detect_ip)"
  default_ip="${SERVER_IP:-$detected_ip}"
  if [[ -n "$detected_ip" ]]; then
    msg "Detected server IP: $detected_ip"
  else
    warn "Could not auto-detect server IP."
  fi
  while true; do
    read -r -p "Confirm or enter the server IP [${default_ip:-required}]: " ip_input || true
    ip_input="${ip_input:-$default_ip}"
    if [[ -n "$ip_input" ]] && is_valid_ip "$ip_input"; then
      SERVER_IP="$ip_input"
      break
    fi
    warn "Please enter a valid IPv4 address (e.g., 203.0.113.10)."
    default_ip="" # Force explicit entry on repeated failures
  done

  msg ""
  msg "Ports will be set as follows:"
  msg "  Admin (HTTPS): $ADMIN_PORT"
  msg "  Client (HTTPS): $CLIENT_PORT"
  msg ""

  # Database and other prompts
  prompt_or_use PREFIX "Install directory" "/opt/hosting-panel" 1 0
  prompt_or_use DB_HOST "Database host" "localhost" 1 0
  prompt_or_use DB_USER "Database user" "hosting" 1 0
  prompt_or_use DB_PASS "Database password" "" 1 1

  # Optional: database backend selection
  if [[ -z "${DB_BACKEND:-}" ]]; then
    msg "Available database backends:"
    msg "  1) mysql (default)"
    msg "  2) postgres"
    msg "  3) sqlite"
    while true; do
      read -r -p "Select database backend [1]: " choice
      case "${choice:-1}" in
        1|mysql) DB_BACKEND="mysql"; break ;;
        2|postgres) DB_BACKEND="postgres"; break ;;
        3|sqlite) DB_BACKEND="sqlite"; break ;;
        *) warn "Invalid choice. Please select 1, 2, or 3." ;;
      esac
    done
  fi

  if [[ "$ASSUME_YES" != "1" ]]; then
    msg ""
    msg "Installation Summary:"
    msg "====================="
    msg "  Install dir:   $PREFIX"
    msg "  FQDN:          $FQDN"
    msg "  Server IP:     $SERVER_IP"
    msg "  Admin port:    $ADMIN_PORT"
    msg "  Client port:   $CLIENT_PORT"
    msg "  DB host:       $DB_HOST"
    msg "  DB user:       $DB_USER"
    msg "  DB backend:    $DB_BACKEND"
    msg "  Git branch:    $GIT_BRANCH"
    msg ""
    msg "Admin URLs:"
    msg "  - https://$FQDN:$ADMIN_PORT"
    msg "  - https://$SERVER_IP:$ADMIN_PORT"
    msg "Client URLs:"
    msg "  - https://$FQDN:$CLIENT_PORT"
    msg "  - https://$SERVER_IP:$CLIENT_PORT"
    msg ""
    if ! confirm "Proceed with installation?"; then
      die "Aborted by user."
    fi
  fi
else
  # Non-interactive validation
  [[ -n "${FQDN:-}" ]] || die "FQDN is required in non-interactive mode. Provide --fqdn or FQDN=..."
  validate_fqdn "$FQDN" || die "Invalid FQDN provided: $FQDN"
  if [[ -z "${SERVER_IP:-}" ]]; then
    SERVER_IP="$(detect_ip || true)"
  fi
  [[ -n "${SERVER_IP:-}" ]] && is_valid_ip "$SERVER_IP" || die "Server IP is required/invalid in non-interactive mode. Provide --ip or SERVER_IP=..."
  [[ -n "${DB_PASS:-}" ]] || die "Database password is required in non-interactive mode. Provide --db-pass or DB_PASS=..."
fi

# ========= Prerequisites =========
msg ""
msg "Checking prerequisites..."
need_cmd mkdir
need_cmd cp
need_cmd tar

# ========= Download panel =========
if [[ "$SKIP_DOWNLOAD" != "1" ]]; then
  # Check if panel.tar already exists
  if [[ -f "panel.tar" ]]; then
    if [[ "$NONINTERACTIVE" != "1" && "$ASSUME_YES" != "1" ]]; then
      if confirm "panel.tar already exists. Download fresh copy?"; then
        rm -f panel.tar
        download_panel
      else
        msg "Using existing panel.tar"
      fi
    else
      msg "Using existing panel.tar (use --skip-download to avoid this check)"
    fi
  else
    download_panel
  fi
else
  msg "Skipping download (--skip-download specified)"
fi

# Verify panel.tar exists
if [[ ! -f "panel.tar" ]]; then
  die "panel.tar not found. Download failed or --skip-download used without existing archive."
fi

# ========= Install steps =========
msg "Creating install directory: $PREFIX"
[[ -d "$PREFIX" ]] || mkdir -p "$PREFIX"

# Check if directory is not empty and prompt for confirmation
if [[ -n "$(ls -A "$PREFIX" 2>/dev/null || true)" && "$ASSUME_YES" != "1" && "$NONINTERACTIVE" != "1" ]]; then
  if ! confirm "Target directory $PREFIX is not empty. Continue?"; then
    die "Aborted."
  fi
fi

# Extract panel.tar
msg "Extracting panel.tar to $PREFIX..."
if ! tar -xf panel.tar -C "$PREFIX"; then
  die "Failed to extract panel.tar. Please check the archive integrity."
fi
msg "Panel files extracted successfully."

# Set permissions
msg "Setting file permissions..."
find "$PREFIX" -type f -name "*.sh" -exec chmod +x {} \; 2>/dev/null || true
find "$PREFIX" -type f -name "*.py" -exec chmod +x {} \; 2>/dev/null || true

# ========= Configuration =========
msg "Writing configuration to $PREFIX/.env"
timestamp="$(date +%Y%m%d-%H%M%S 2>/dev/null || echo "now")"
if [[ -f "$PREFIX/.env" ]]; then
  cp -f "$PREFIX/.env" "$PREFIX/.env.bak-$timestamp"
  msg "Backed up existing .env to $PREFIX/.env.bak-$timestamp"
fi

cat > "$PREFIX/.env" <<EOF
# Generated by install.sh on $(date)
FQDN=$FQDN
SERVER_IP=$SERVER_IP
ADMIN_PORT=$ADMIN_PORT
CLIENT_PORT=$CLIENT_PORT
DB_HOST=$DB_HOST
DB_USER=$DB_USER
DB_PASS=$DB_PASS
DB_BACKEND=$DB_BACKEND

# Convenience URLs
ADMIN_URL_FQDN=https://$FQDN:$ADMIN_PORT
CLIENT_URL_FQDN=https://$FQDN:$CLIENT_PORT
ADMIN_URL_IP=https://$SERVER_IP:$ADMIN_PORT
CLIENT_URL_IP=https://$SERVER_IP:$CLIENT_PORT
EOF

# Create additional config files if needed
if [[ -f "$PREFIX/config/config.template.php" ]]; then
  msg "Generating PHP configuration..."
  sed -e "s/{{FQDN}}/$FQDN/g" \
      -e "s/{{SERVER_IP}}/$SERVER_IP/g" \
      -e "s/{{ADMIN_PORT}}/$ADMIN_PORT/g" \
      -e "s/{{CLIENT_PORT}}/$CLIENT_PORT/g" \
      -e "s/{{DB_HOST}}/$DB_HOST/g" \
      -e "s/{{DB_USER}}/$DB_USER/g" \
      -e "s/{{DB_PASS}}/$DB_PASS/g" \
      -e "s/{{DB_BACKEND}}/$DB_BACKEND/g" \
      "$PREFIX/config/config.template.php" > "$PREFIX/config/config.php"
fi

# Initialize database if script exists
if [[ -f "$PREFIX/scripts/init-db.sh" ]]; then
  msg "Initializing database..."
  cd "$PREFIX" && ./scripts/init-db.sh || warn "Database initialization failed. You may need to run it manually."
fi

# Clean up downloaded tar if it was created by this script
if [[ "$SKIP_DOWNLOAD" != "1" && -f "panel.tar" ]]; then
  rm -f panel.tar
  msg "Cleaned up downloaded panel.tar"
fi

# ========= Final output =========
msg ""
msg "============================================"
msg "   Phynx Hosting Panel Installation Complete!"
msg "============================================"
msg ""
msg "Configuration Details:"
msg "  Install dir:   $PREFIX"
msg "  FQDN:          $FQDN"
msg "  Server IP:     $SERVER_IP"
msg "  Admin port:    $ADMIN_PORT"
msg "  Client port:   $CLIENT_PORT"
msg "  DB backend:    $DB_BACKEND"
msg ""
msg "Access URLs:"
msg "  Admin Panel:"
msg "    - https://$FQDN:$ADMIN_PORT"
msg "    - https://$SERVER_IP:$ADMIN_PORT"
msg "  Client Portal:"
msg "    - https://$FQDN:$CLIENT_PORT"
msg "    - https://$SERVER_IP:$CLIENT_PORT"
msg ""
msg "Configuration files:"
msg "  - Environment: $PREFIX/.env"
if [[ -f "$PREFIX/config/config.php" ]]; then
  msg "  - PHP Config:  $PREFIX/config/config.php"
fi
msg ""
msg "To start the panel:"
msg "  cd $PREFIX"
if [[ -f "$PREFIX/start.sh" ]]; then
  msg "  ./start.sh"
elif [[ -f "$PREFIX/run.sh" ]]; then
  msg "  ./run.sh"
else
  msg "  # Follow the instructions in the panel documentation"
fi
msg ""
msg "Installation complete! 🎉"
msg ""
msg "Repository: https://github.com/savagemiguel/phynx.git"
msg "Branch: $GIT_BRANCH"