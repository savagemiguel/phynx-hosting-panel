# 🚀 Production Hosting Panel - Deployment Guide

## Overview

This is a professional-grade web hosting control panel similar to cPanel, designed for Ubuntu 24.04 LTS servers. It provides comprehensive domain management, DNS control, SSL automation, real-time server monitoring, and user management capabilities.

## ✨ Key Features

### 🎛️ **Admin Panel Features**
- **Real-time Server Monitoring**: Live CPU, memory, disk, and network statistics
- **Service Status Monitoring**: Apache, MySQL, BIND DNS status tracking
- **User Management**: Create, edit, suspend users with package limitations
- **Domain Oversight**: Approve domains, manage DNS zones
- **DNS Zone Templates**: Create reusable DNS configurations
- **SSL Certificate Management**: Automated Let's Encrypt integration
- **Package Management**: Define hosting packages with resource limits
- **System Statistics**: Historical performance data collection

### 👥 **User Panel Features**
- **Domain Management**: Add domains with automatic vhost creation
- **DNS Zone Editor**: Full DNS record management (A, CNAME, MX, TXT, NS)
- **Email Account Management**: Create and manage email accounts
- **Database Management**: MySQL database and user creation
- **FTP Account Management**: Secure FTP access configuration
- **SSL Certificate Requests**: One-click SSL certificate installation
- **File Manager**: (Ready for implementation)
- **Backup Management**: (Ready for implementation)

### 🔒 **Security Features**
- **CSRF Protection**: All forms protected against CSRF attacks
- **Fail2Ban Integration**: Automated intrusion prevention
- **Secure Headers**: X-XSS-Protection, Content-Security-Policy, HSTS
- **Input Sanitization**: All user inputs properly sanitized
- **Session Security**: Secure session handling with httpOnly cookies
- **Firewall Integration**: UFW firewall configuration

### ⚡ **System Integration**
- **Apache Virtual Hosts**: Automatic vhost creation and SSL configuration
- **BIND DNS**: Automatic zone file generation and DNS server reloading
- **PHP-FPM**: Optimized PHP processing with security restrictions
- **MySQL Integration**: Automated database and user creation
- **Systemd Services**: Background statistics collection and maintenance

## 📋 System Requirements

### Minimum Requirements
- **OS**: Ubuntu 24.04 LTS (Fresh installation recommended)
- **RAM**: 2GB minimum, 4GB recommended
- **Storage**: 20GB minimum, SSD recommended
- **Network**: Static IP address with reverse DNS configured
- **Domain**: Dedicated domain for the control panel (e.g., panel.yourdomain.com)

### Required Packages (Installed automatically)
- Apache 2.4+ with SSL support
- MySQL 8.0+ or MariaDB 10.6+
- PHP 8.3+ with extensions (mysqli, curl, gd, xml, mbstring, zip, bcmath)
- BIND 9+ DNS server
- Certbot for SSL certificates
- Fail2Ban for intrusion prevention
- UFW firewall

## 🔧 Installation Process

### Step 1: Prepare Your Server

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Set hostname (replace with your actual hostname)
sudo hostnamectl set-hostname panel.yourdomain.com

# Configure timezone
sudo timedatectl set-timezone UTC
```

### Step 2: Download and Prepare Installation

```bash
# Create temporary directory
mkdir /tmp/hosting-panel
cd /tmp/hosting-panel

# Copy your panel files here
# (Upload via SCP, git clone, or other method)

# Make deployment script executable
chmod +x deploy-ubuntu.sh
```

### Step 3: Run Installation

```bash
# Run the deployment script
./deploy-ubuntu.sh
```

The script will:
1. Install all required packages
2. Configure Apache, MySQL, PHP-FPM, and BIND
3. Set up security (Fail2Ban, UFW firewall)
4. Create systemd services for monitoring
5. Configure SSL-ready virtual hosts
6. Set proper file permissions
7. Generate secure database credentials

### Step 4: Post-Installation Setup

```bash
# Configure SSL certificate for the panel
sudo certbot --apache -d panel.yourdomain.com

# Check service status
sudo systemctl status apache2 mysql named php8.3-fpm

# Verify firewall status
sudo ufw status

# Check fail2ban status
sudo fail2ban-client status
```

## 🌐 DNS Configuration

Point your domain's DNS to your server:

```
panel.yourdomain.com    A    YOUR_SERVER_IP
*.yourdomain.com        A    YOUR_SERVER_IP  (for hosted domains)
```

## 📊 System Monitoring

The panel includes comprehensive monitoring:

### Real-time Statistics
- CPU usage and load averages
- Memory usage (RAM)
- Disk space usage
- Network traffic (RX/TX)
- Active processes count
- Service status (Apache, MySQL, BIND)

### Automated Data Collection
- Statistics collected every 5 minutes
- Data retained for 90 days
- Automatic cleanup of old records
- Background systemd services

## 🔑 Initial Access

After installation, access your panel at:
- **URL**: `https://panel.yourdomain.com`
- **Username**: `admin`
- **Password**: [Shown during installation]

**⚠️ IMPORTANT**: Change the default admin password immediately after first login!

## 🛠️ Configuration

### Environment Configuration
Edit `/etc/hosting-panel/.env` for system-wide settings:

```bash
# Database Configuration
DB_HOST=localhost
DB_USER=panel_user
DB_PASS=your_secure_password
DB_NAME=hosting_panel

# Application Configuration
SITE_URL=https://panel.yourdomain.com
ADMIN_EMAIL=admin@yourdomain.com

# System Paths
APACHE_VHOST_PATH=/etc/apache2/sites-available
DNS_ZONE_PATH=/etc/bind/zones
WEB_ROOT=/var/www/sites

# SSL Configuration
CERTBOT_BIN=/usr/bin/certbot
APACHE_RELOAD_CMD=systemctl reload apache2
BIND_RELOAD_CMD=systemctl reload named
```

### DNS Templates
The system includes pre-configured DNS templates:
- **Basic Website**: Standard A record and www CNAME
- **Email Server**: MX records and SPF configuration
- **Full Setup**: Complete DNS setup for web and email hosting

Create custom templates in Admin > DNS Zone Templates.

## 📦 Package Management

Create hosting packages with limits:
- Disk space allocation
- Bandwidth limits
- Number of domains allowed
- Subdomains limit
- Email accounts limit
- Database limits
- FTP accounts limit
- SSL certificates

## 🔐 Security Best Practices

### Server Hardening
1. **Keep system updated**: `sudo apt update && sudo apt upgrade`
2. **Monitor logs**: Check `/var/log/hosting-panel/`
3. **Review fail2ban**: `sudo fail2ban-client status`
4. **Firewall rules**: Only open necessary ports
5. **SSL certificates**: Keep certificates updated
6. **Database security**: Use strong passwords

### Panel Security
1. **Change default passwords** immediately
2. **Use strong admin passwords**
3. **Enable 2FA** (can be implemented)
4. **Regular backups** of panel and databases
5. **Monitor user activity**
6. **Review user permissions**

## 📁 Directory Structure

```
/var/www/hosting-panel/          # Panel installation
├── admin/                       # Admin interface
├── user/                        # User interface
├── includes/                    # Core functions
├── assets/                      # CSS, JS, images
├── cli/                         # Command-line scripts
└── migrations/                  # Database migrations

/var/www/sites/                  # User websites
├── username1/
│   ├── domain1.com/
│   │   ├── public_html/         # Web files
│   │   ├── logs/               # Apache logs
│   │   └── ssl/                # SSL certificates
│   └── domain2.com/
└── username2/

/etc/bind/zones/                 # DNS zone files
├── db.domain1.com
├── db.domain2.com
└── zones.conf

/var/log/hosting-panel/          # Panel logs
├── domain-creation.log
├── ssl-automation.log
├── php-error.log
└── system-stats.log
```

## 🔄 Maintenance

### Regular Tasks
```bash
# Check system status
sudo systemctl status hosting-panel-stats.timer
sudo systemctl status hosting-panel-backup.timer

# View recent logs
sudo tail -f /var/log/hosting-panel/domain-creation.log

# Database maintenance
mysql -u root -p hosting_panel -e "OPTIMIZE TABLE system_stats;"

# Clean old statistics (automated, but can be run manually)
mysql -u root -p hosting_panel -e "DELETE FROM system_stats WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY);"
```

### Backup Recommendations
1. **Database**: Daily automated backups
2. **User files**: Regular rsync or backup solution
3. **Panel configuration**: Backup `/etc/hosting-panel/`
4. **SSL certificates**: Included in automated backups

## 📈 Scaling and Performance

### Performance Optimization
1. **PHP-FPM tuning**: Adjust process managers per traffic
2. **MySQL optimization**: Configure for your RAM size
3. **Apache tuning**: Optimize worker/event MPM
4. **Disk I/O**: Use SSD storage for databases
5. **Monitoring**: Set up external monitoring (Prometheus, etc.)

### Scaling Options
1. **Vertical scaling**: Increase server resources
2. **Load balancing**: Multiple web servers with shared storage
3. **Database clustering**: MySQL master-slave replication
4. **CDN integration**: For static content delivery
5. **Caching**: Redis/Memcached for session storage

## 🆘 Troubleshooting

### Common Issues

#### DNS not working
```bash
# Check BIND configuration
sudo named-checkconf
sudo named-checkzone domain.com /etc/bind/zones/db.domain.com

# Restart DNS service
sudo systemctl restart named
```

#### SSL certificate issues
```bash
# Check certificate status
sudo certbot certificates

# Renew certificates
sudo certbot renew --dry-run

# Manual certificate installation
sudo certbot --apache -d domain.com -d www.domain.com
```

#### Apache virtual host issues
```bash
# Test Apache configuration
sudo apache2ctl configtest

# Check enabled sites
sudo a2ensite domain.com.conf
sudo systemctl reload apache2
```

#### Panel access issues
```bash
# Check panel logs
sudo tail -f /var/log/apache2/hosting-panel-error.log

# Verify database connection
mysql -u panel_user -p hosting_panel

# Check file permissions
sudo chown -R hosting-panel:www-data /var/www/hosting-panel
```

## 🔮 Advanced Features (Ready for Implementation)

The panel architecture supports easy addition of:
- **File Manager**: Web-based file management
- **Backup Automation**: Scheduled backups with retention
- **Email Management**: Full email server integration
- **Resource Monitoring**: Per-user resource tracking
- **API Access**: RESTful API for automation
- **Plugin System**: Third-party integrations
- **Multi-server Management**: Manage multiple servers
- **Billing Integration**: WHMCS, Blesta integration

## 📞 Support and Documentation

### Log Locations
- **Panel logs**: `/var/log/hosting-panel/`
- **Apache logs**: `/var/log/apache2/`
- **MySQL logs**: `/var/log/mysql/`
- **System logs**: `/var/log/syslog`

### Configuration Files
- **Panel config**: `/etc/hosting-panel/.env`
- **Apache config**: `/etc/apache2/sites-available/`
- **DNS zones**: `/etc/bind/zones/`
- **PHP config**: `/etc/php/8.3/fpm/pool.d/hosting-panel.conf`

---

## 🎯 Summary

This hosting panel provides a complete cPanel-like experience with:

✅ **Complete Domain Management** - Automatic vhost creation, DNS management, SSL automation  
✅ **Real-time Server Monitoring** - Live statistics and service monitoring  
✅ **Advanced DNS Management** - Zone templates, bulk operations, BIND integration  
✅ **Production-ready Security** - Fail2Ban, UFW, secure headers, input validation  
✅ **Professional UI/UX** - Dark theme, responsive design, intuitive interface  
✅ **Ubuntu 24 Optimized** - Systemd services, PHP 8.3, modern stack  

The system is designed for production use and can handle multiple users, domains, and high traffic loads while maintaining security and performance standards expected from professional hosting environments.

**Ready for deployment on Ubuntu 24.04 LTS servers! 🚀**