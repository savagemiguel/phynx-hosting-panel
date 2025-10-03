-- This script is run by the MySQL container on first startup.
-- It creates the table needed for the "System Updates" feature.

USE version_check;

CREATE TABLE IF NOT EXISTS versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL,
    product_key VARCHAR(255),
    current_version VARCHAR(20) NOT NULL,
    latest_version VARCHAR(20) NOT NULL,
    download_url TEXT,
    filename VARCHAR(255),
    release_notes TEXT,
    release_date DATE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    update_available BOOLEAN DEFAULT FALSE,
    is_latest BOOLEAN DEFAULT FALSE,
    INDEX idx_product (product_name)
);

-- Insert initial data to prevent errors on first load
INSERT INTO versions (product_name, current_version, latest_version)
VALUES ('PHYNX Admin', '1.0.0', '1.0.0');