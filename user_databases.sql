-- User Databases Table
CREATE TABLE IF NOT EXISTS user_databases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    database_name VARCHAR(64) NOT NULL,
    database_user VARCHAR(32) NOT NULL,
    database_password VARCHAR(255) NOT NULL,
    size_mb INT DEFAULT 0,
    status ENUM('active', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_db_name (database_name)
);