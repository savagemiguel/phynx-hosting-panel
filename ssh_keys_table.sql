-- SSH Keys table
CREATE TABLE IF NOT EXISTS `ssh_keys` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    public_key TEXT NOT NULL,
    key_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add indexes
ALTER TABLE `ssh_keys` ADD INDEX `idx_user_id` (`user_id`);
ALTER TABLE `ssh_keys` ADD INDEX `idx_key_name` (`key_name`);