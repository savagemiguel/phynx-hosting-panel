# Web Hosting Panel

A complete web hosting control panel built with PHP and MySQL featuring domain management, DNS zone files, user accounts, and package management.

## Features

### Admin Features
- Dashboard with statistics
- User account management
- Package creation and management
- Domain oversight and approval
- DNS zone management
- User status control

### User Features
- Personal dashboard with package info
- Domain creation and management
- DNS record management
- Profile management
- Password change

### Technical Features
- DNS zone file generation
- Package-based limitations
- Secure authentication
- Responsive dark theme UI
- MySQL database backend

## Installation

1. **Database Setup**
   ```bash
   mysql -u root -p < database.sql
   ```

2. **Configuration**
   - Edit `config.php` with your database credentials
   - Set proper DNS zone path and BIND reload command
   - Configure site URL

3. **Permissions**
   - Ensure web server can write to DNS zone directory
   - Set proper permissions for BIND reload command

4. **Default Login**
   - Username: `admin`
   - Password: `admin123`

## File Structure

```
hosting-panel/
├── config.php              # Database and site configuration
├── database.sql            # Database schema and sample data
├── index.php              # Main redirect page
├── login.php              # Authentication
├── logout.php             # Session cleanup
├── includes/
│   └── functions.php      # Utility functions
├── assets/css/
│   └── style.css          # Dark theme styling
├── admin/                 # Admin panel
│   ├── index.php          # Admin dashboard
│   ├── users.php          # User management
│   ├── packages.php       # Package management
│   ├── domains.php        # Domain oversight
│   └── dns.php            # DNS management
└── user/                  # User panel
    ├── index.php          # User dashboard
    ├── domains.php        # Domain management
    ├── dns.php            # DNS records
    └── profile.php        # Profile settings
```

## Security Notes

- Change default admin password immediately
- Use HTTPS in production
- Set proper file permissions
- Configure firewall rules
- Regular security updates

## DNS Integration

The panel generates BIND-compatible zone files and can reload the DNS server automatically. Ensure proper permissions for:
- Writing to DNS zone directory
- Executing BIND reload command

## Package System

Users are assigned packages that limit:
- Disk space allocation
- Bandwidth limits
- Number of domains
- Subdomains allowed
- Email accounts
- Database limits

## Customization

The UI uses CSS custom properties for easy theme customization. Modify the `:root` variables in `style.css` to change colors and styling."# phynx" 
