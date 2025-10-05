# Phynx Hosting Panel Installation Guide

This guide will walk you through installing the **Phynx Hosting Panel** with **advanced features** including automatic DNS zone creation, progress monitoring, error handling, and comprehensive web hosting capabilities.

## 🚀 **New Features Highlight**

✨ **Latest Version Includes:**
- 🌐 **Automatic DNS Zone Creation** - Complete BIND9 DNS setup with nameservers
- 📊 **Advanced Progress Monitoring** - Real-time progress bars with ETA calculations
- 🛡️ **Comprehensive Error Handling** - Automatic rollback and recovery systems
- 🎛️ **Interactive Configuration** - User-friendly menus and setup wizards
- 📈 **Performance Monitoring** - System resource tracking during installation
- 📋 **HTML Report Generation** - Detailed installation analytics and reports
- 🔍 **DNS Propagation Monitoring** - Real-time DNS verification across global servers

## 🔧 System Requirements

### Minimum Requirements
- **Operating System**: Ubuntu 22.04 LTS or higher
- **RAM**: 1GB (2GB+ recommended for DNS and advanced features)
- **Disk Space**: 2GB available (additional space for logs and backups)
- **Network**: Internet connection for package downloads and DNS testing
- **Privileges**: Root access (sudo)
- **Domain**: Registered domain name for DNS zone creation

### Recommended Requirements
- **RAM**: 2GB+ for optimal performance with all features
- **Disk Space**: 5GB+ for logs, backups, and user data
- **CPU**: 2+ cores for parallel processing optimization
- **Network**: Stable connection for DNS propagation monitoring

### Supported Ubuntu Versions
- ✅ Ubuntu 22.04 LTS (Jammy Jellyfish) - **Recommended**
- ✅ Ubuntu 23.04 (Lunar Lobster)
- ✅ Ubuntu 23.10 (Mantic Minotaur) 
- ✅ Ubuntu 24.04 LTS (Noble Numbat)
- ✅ Ubuntu 24.10+ (Latest versions supported)

## 🚀 Quick Installation

### Step 1: Download Panel Files
```bash
# Clone or download the panel to your server
git clone https://github.com/your-repo/phynx-hosting-panel.git
cd phynx-hosting-panel

# Or if you have the files already:
cd /path/to/phynx-hosting-panel
```

### Step 2: Check System Requirements
```bash
# Make scripts executable
chmod +x check-requirements.sh install-enhanced.sh

# Run pre-installation check
sudo ./check-requirements.sh
```

### Step 3: Run Installation
```bash
# Basic installation with default settings
sudo ./install-enhanced.sh

# Or with custom options (see Configuration Options below)
sudo ./install-enhanced.sh --web-server=nginx --domain=panel.yourdomain.com
```

## ⚙️ **Advanced Configuration Options**

The **enhanced installer** supports comprehensive configuration with **interactive menus** and **command-line options**:

### 🎛️ **Interactive Installation**

**Default Mode** - Full interactive experience with progress bars:
```bash
sudo ./install-enhanced.sh
```

Features **interactive menus** for:
- 🌐 Domain configuration
- 🔧 Web server selection  
- 📦 Optional component selection
- 🛡️ Security settings
- 📊 Advanced feature toggles

### 📝 **Command-Line Options**

```bash
sudo ./install-enhanced.sh [OPTIONS]

Core Configuration:
  --web-server=apache|nginx     Choose web server (default: apache)
  --domain=example.com          Set main domain (creates *.domain structure)  
  --email=admin@example.com     Set admin email address
  --http-port=PORT              Set custom HTTP port (default: 80)
  --https-port=PORT             Set custom HTTPS port (default: 443)
  --secure-port=PORT            Set secure admin port (default: 2083)

Component Options:
  --no-pma                      Skip custom Phynx Manager deployment
  --no-bind                     Skip BIND9 DNS server installation
  --csf                         Install CSF/LFD instead of UFW firewall
  --setup-dns                   Automatically create DNS zones (default: yes)
  --no-dns                      Skip automatic DNS zone creation

Advanced Features:
  --silent                      Skip interactive prompts (use defaults)
  --help, -h                    Show comprehensive help message

DNS & Domain Features:
  --setup-dns                   Enable automatic DNS zone creation
  --no-dns                      Disable DNS zone automation
```

### 🎯 **Example Configurations**

**🚀 Full-Featured Installation** (Recommended):
```bash
sudo ./install-enhanced.sh --domain=yourdomain.com --email=admin@yourdomain.com
```

**🌐 Complete DNS + Hosting Setup**:
```bash
sudo ./install-enhanced.sh --web-server=nginx --domain=yourdomain.com --setup-dns
```

**🛡️ High-Security Installation**:
```bash
sudo ./install-enhanced.sh --csf --domain=secure.yourdomain.com --setup-dns
```

**⚡ Minimal Installation** (No DNS, No Phynx Manager):
```bash
sudo ./install-enhanced.sh --no-dns --no-pma --no-bind
```

**🔇 Silent Installation** (No prompts):
```bash
sudo ./install-enhanced.sh --silent --domain=auto.yourdomain.com
```

## 🗂️ **Advanced Installation Features**

### 🚀 **Core System Components**

- **🌐 Web Server**: Apache 2.4 or Nginx with optimized configurations
- **🗄️ Database**: MySQL 8.0+ with performance tuning and security hardening
- **🐘 PHP**: Multi-version support (PHP 8.1, 8.2, 8.3, 8.4) with FPM
- **📁 Panel Files**: Complete hosting panel installed to `/var/www/phynx`
- **📊 Monitoring**: Real-time performance and resource monitoring

### 🌐 **DNS Zone Automation** *(New!)*

- **🔧 BIND9 DNS Server**: Fully configured with your domain
- **📋 DNS Records**: Automatic A, CNAME, MX, TXT, SRV, CAA record creation
- **🏷️ Nameservers**: `ns1.yourdomain.com` and `ns2.yourdomain.com` 
- **🔍 Propagation Monitoring**: Real-time DNS propagation checking
- **🛠️ Management Tools**: `phynx-dns-update` and `phynx-dns-check` commands
- **📡 Multi-Domain Support**: `yourdomain.com`, `panel.yourdomain.com`, `phynxadmin.yourdomain.com`

### 🎛️ **Custom Phynx Manager Integration**

- **📍 Location**: `/var/www/phynx/phynx` (accessible at `yourdomain.com/phynxadmin`)
- **🔗 Access Points**: `phynxadmin.yourdomain.com` and `yourdomain.com/phynxadmin`
- **🔒 Security**: Pre-configured with secure defaults and panel integration
- **⚡ Performance**: Optimized for hosting panel database management

### 🛡️ **Advanced Security Features**

- **🔥 Firewall**: UFW (default) or CSF/LFD (advanced intrusion detection)
- **🚫 Fail2Ban**: Automatic IP blocking for suspicious activity
- **🔐 SSL Ready**: Let's Encrypt Certbot with auto-renewal
- **🛡️ Security Headers**: HSTS, CSP, X-Frame-Options configured
- **📁 File Permissions**: Properly secured with least-privilege principle
- **🔒 Database Security**: Secured MySQL installation with strong passwords

### 📊 **Installation Monitoring & Analytics** *(New!)*

- **📈 Progress Bars**: Real-time installation progress with ETA calculations
- **🔄 Error Handling**: Comprehensive rollback system with automatic recovery
- **📋 HTML Reports**: Detailed installation analytics and system reports
- **💾 System Backup**: Automatic pre-installation system state backup
- **🖥️ Resource Monitoring**: CPU, RAM, disk usage tracking during installation
- **📝 Advanced Logging**: Structured logging with categorized entries

### 🔧 **Optional Advanced Components**

- **🌐 BIND9 DNS Server**: Complete DNS infrastructure for domain management
- **⏰ Cron Jobs**: Automated maintenance, backups, and monitoring tasks  
- **📊 System Monitoring**: Real-time resource usage and performance metrics
- **💾 Backup System**: Automated database and file backup capabilities
- **🔐 Let's Encrypt**: Automatic SSL certificate generation and renewal

## 🌐 **DNS Configuration Guide** *(New Feature!)*

### 🎯 **Automatic DNS Setup**

If you enabled DNS zone creation (default), the installer automatically:

1. **✅ Creates DNS Zones**: Primary and reverse DNS zones for your domain
2. **✅ Configures Records**: A, CNAME, MX, TXT, SRV, CAA records
3. **✅ Sets Up Nameservers**: `ns1.yourdomain.com` and `ns2.yourdomain.com`
4. **✅ Tests Configuration**: Local DNS resolution verification
5. **✅ Monitors Propagation**: Real-time DNS propagation checking

### 📋 **Domain Registrar Configuration**

After installation, configure your **domain registrar** (GoDaddy, Namecheap, etc.):

**Option 1: Use Phynx Nameservers** *(Recommended)*
```
Primary Nameserver:   ns1.yourdomain.com
Secondary Nameserver: ns2.yourdomain.com
```

**Option 2: Add Records to Existing DNS Provider**
```
A Record:    yourdomain.com           → Your Server IP
A Record:    www.yourdomain.com      → Your Server IP  
A Record:    panel.yourdomain.com    → Your Server IP
A Record:    phynxadmin.yourdomain.com → Your Server IP
MX Record:   yourdomain.com           → mail.yourdomain.com (Priority: 10)
```

### 🔍 **DNS Verification Tools**

**Check DNS Propagation:**
```bash
# Check your domain resolution
phynx-dns-check yourdomain.com

# Add new DNS records
phynx-dns-update yourdomain.com A subdomain 192.168.1.100

# Check installation logs
tail -f /var/log/phynx-install/install-*.log
```

**Online DNS Checkers:**
- [DNSChecker.org](https://dnschecker.org) - Global DNS propagation
- [WhatsmyDNS.net](https://whatsmydns.net) - Worldwide DNS lookup
- [DNSstuff.com](https://dnsstuff.com) - Comprehensive DNS tools

### 🌐 **Your Website Access Points**

After DNS propagation (4-48 hours), access your sites:

- **🏠 Main Website**: `https://yourdomain.com`
- **🎛️ Admin Panel**: `https://panel.yourdomain.com` or `https://yourdomain.com/panel`
- **🗄️ Database Manager**: `https://phynxadmin.yourdomain.com` or `https://yourdomain.com/phynxadmin`
- **🔐 Secure Access**: `https://yourdomain.com:2083` (Direct IP also works)

## 🔐 Post-Installation Security

### 1. Enable HTTPS
```bash
# For Apache
sudo certbot --apache -d your-panel-domain.com

# For Nginx  
sudo certbot --nginx -d your-panel-domain.com
```

### 2. Change Default Passwords
The installer generates secure passwords, but you should:
- Change the MySQL root password
- Set up your admin panel account
- Review phpMyAdmin access credentials

### 3. Firewall Configuration
```bash
# Check UFW status
sudo ufw status

# Or for CSF users
sudo csf -l
```

### 4. Review Configuration
Important files to review:
- `/var/www/phynx/.env` - Panel configuration
- `/root/.phynx_credentials` - Database passwords
- `/var/log/phynx-install.log` - Installation log

## 📁 File Structure After Installation

```
/var/www/phynx/           # Main panel directory
├── admin/                # Admin panel files
├── user/                 # User panel files
├── pma/                  # Custom phpMyAdmin
├── assets/               # CSS, JS, images
├── includes/             # PHP includes
├── uploads/              # User uploads (writable)
├── logs/                 # System logs (writable)
├── backups/              # Backup storage (writable)
├── .env                  # Environment configuration
└── config.php            # Database configuration

/root/
├── .phynx_credentials    # Database passwords (secure)
└── phynx-installation-summary.txt  # Installation summary
```

## 🔧 Troubleshooting

### Installation Issues

**Permission Errors:**
```bash
# Ensure you're running as root
sudo ./install-enhanced.sh
```

**Package Installation Failures:**
```bash
# Update package lists first
sudo apt update
sudo apt upgrade -y
```

**MySQL Connection Issues:**
```bash
# Check MySQL service
sudo systemctl status mysql

# Reset MySQL root password if needed
sudo mysql_secure_installation
```

### Post-Installation Issues

**Web Server Not Accessible:**
```bash
# Check web server status
sudo systemctl status apache2  # or nginx

# Check firewall
sudo ufw status
```

**Database Connection Errors:**
```bash
# Verify credentials in
cat /root/.phynx_credentials

# Test MySQL connection
mysql -u root -p
```

**phpMyAdmin Access Issues:**
```bash
# Check PMA directory permissions
ls -la /var/www/phynx/pma/

# Verify web server configuration
# Apache: /etc/apache2/sites-available/phynx.conf
# Nginx: /etc/nginx/sites-available/phynx
```

## 🔄 Updating the Panel

### Manual Update
```bash
# Backup current installation
sudo cp -r /var/www/phynx /var/www/phynx-backup

# Update files (preserve config and data)
# ... update process ...

# Restore permissions
sudo chown -R www-data:www-data /var/www/phynx
```

### Automated Maintenance
The installer sets up cron jobs for:
- Daily system cleanup
- Log rotation
- Backup maintenance
- Security updates

## 🆘 Getting Help

### Log Files
- Installation log: `/var/log/phynx-install.log`
- Web server logs: `/var/log/apache2/` or `/var/log/nginx/`
- PHP logs: `/var/log/php8.1-fpm.log`
- Panel logs: `/var/www/phynx/logs/`

### System Information
```bash
# Check installation summary
cat /root/phynx-installation-summary.txt

# Check service status
sudo systemctl status apache2 mysql php8.1-fpm

# Check disk usage
df -h

# Check memory usage
free -h
```

### Support Resources
- Documentation: [Panel Documentation URL]
- Community Forum: [Forum URL]
- Bug Reports: [GitHub Issues URL]
- Email Support: support@phynx.one

## 📋 Installation Checklist

- [ ] System meets minimum requirements (Ubuntu 22.04+, 512MB RAM, 1GB disk)
- [ ] Run `./check-requirements.sh` successfully
- [ ] Choose web server (Apache/Nginx)
- [ ] Set domain name and admin email
- [ ] Run installation script
- [ ] Configure DNS to point to server
- [ ] Set up SSL certificates with Certbot
- [ ] Complete web-based panel setup
- [ ] Change all default passwords
- [ ] Test phpMyAdmin access
- [ ] Configure firewall rules as needed
- [ ] Set up regular backups
- [ ] Review security settings

## ⚡ Performance Tips

### For VPS/Cloud Servers (1-2GB RAM)
- Use the default UFW firewall
- Enable PHP OPcache (done automatically)
- Set up swap file if needed
- Use Apache for easier management

### For Dedicated Servers (4GB+ RAM)
- Consider Nginx for better performance
- Install CSF firewall for advanced security
- Enable all optional components
- Set up external backup storage

---

**Last Updated**: October 2025  
**Version**: 2.0  
**Compatibility**: Ubuntu 22.04+