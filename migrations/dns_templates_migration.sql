-- DNS Templates migration
-- Add this to your database schema

CREATE TABLE IF NOT EXISTS `dns_templates` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    records JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default templates
INSERT INTO `dns_templates` (name, description, records) VALUES 
('Basic Website', 'Standard A record and www CNAME for websites', '[
    {"type": "A", "name": "@", "value": "YOUR_SERVER_IP", "ttl": 3600, "priority": 0},
    {"type": "A", "name": "www", "value": "YOUR_SERVER_IP", "ttl": 3600, "priority": 0}
]'),
('Email Server', 'MX records and mail subdomain for email hosting', '[
    {"type": "A", "name": "@", "value": "YOUR_SERVER_IP", "ttl": 3600, "priority": 0},
    {"type": "A", "name": "mail", "value": "YOUR_SERVER_IP", "ttl": 3600, "priority": 0},
    {"type": "MX", "name": "@", "value": "mail.{domain}", "ttl": 3600, "priority": 10},
    {"type": "TXT", "name": "@", "value": "v=spf1 a mx ip4:YOUR_SERVER_IP ~all", "ttl": 3600, "priority": 0}
]'),
('Full Setup', 'Complete DNS setup with web, mail, and subdomains', '[
    {"type": "A", "name": "@", "value": "YOUR_SERVER_IP", "ttl": 3600, "priority": 0},
    {"type": "A", "name": "www", "value": "YOUR_SERVER_IP", "ttl": 3600, "priority": 0},
    {"type": "A", "name": "mail", "value": "YOUR_SERVER_IP", "ttl": 3600, "priority": 0},
    {"type": "A", "name": "ftp", "value": "YOUR_SERVER_IP", "ttl": 3600, "priority": 0},
    {"type": "CNAME", "name": "cpanel", "value": "{domain}", "ttl": 3600, "priority": 0},
    {"type": "MX", "name": "@", "value": "mail.{domain}", "ttl": 3600, "priority": 10},
    {"type": "TXT", "name": "@", "value": "v=spf1 a mx ip4:YOUR_SERVER_IP ~all", "ttl": 3600, "priority": 0}
]');

-- Add indexes for better performance
ALTER TABLE `dns_zones` ADD INDEX `idx_domain_type` (`domain_id`, `record_type`);
ALTER TABLE `dns_zones` ADD INDEX `idx_name` (`name`);

-- Add SSL certificates table if not exists
CREATE TABLE IF NOT EXISTS `ssl_certificates` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    certificate_path VARCHAR(500),
    private_key_path VARCHAR(500),
    status ENUM('active', 'expired', 'pending', 'failed') DEFAULT 'pending',
    issuer VARCHAR(100),
    expires_at TIMESTAMP NULL,
    auto_renew TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

-- Add system monitoring logs table
CREATE TABLE IF NOT EXISTS `system_stats` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    cpu_usage DECIMAL(5,2),
    memory_usage DECIMAL(5,2),
    disk_usage DECIMAL(5,2),
    load_average DECIMAL(4,2),
    network_rx BIGINT,
    network_tx BIGINT,
    active_connections INT
);

-- Add cron jobs table
CREATE TABLE IF NOT EXISTS `cron_jobs` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    command TEXT NOT NULL,
    schedule VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_run TIMESTAMP NULL,
    next_run TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add backup schedules table
CREATE TABLE IF NOT EXISTS `backup_schedules` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    name VARCHAR(100) NOT NULL,
    backup_type ENUM('full', 'files', 'databases') NOT NULL,
    schedule VARCHAR(100) NOT NULL,
    retention_days INT DEFAULT 30,
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_backup TIMESTAMP NULL,
    next_backup TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);