-- Add SSH Keys table for Advanced Tools
-- This migration adds the ssh_keys table that is required by the SSH Key Manager

CREATE TABLE IF NOT EXISTS `ssh_keys` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    public_key TEXT NOT NULL,
    key_type VARCHAR(50) NOT NULL,
    fingerprint VARCHAR(100),
    status ENUM('active', 'revoked') DEFAULT 'active',
    last_used TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_key_name (user_id, key_name)
);

-- Add index for better performance on common queries
CREATE INDEX idx_ssh_keys_user_id ON ssh_keys(user_id);
CREATE INDEX idx_ssh_keys_status ON ssh_keys(status);
CREATE INDEX idx_ssh_keys_created_at ON ssh_keys(created_at);