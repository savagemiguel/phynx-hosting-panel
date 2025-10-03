-- Cron job scheduler schema (Ubuntu-friendly)

CREATE TABLE IF NOT EXISTS `cron_jobs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `schedule` VARCHAR(64) NOT NULL,          -- standard cron string: "*/5 * * * *"
  `command` VARCHAR(1000) NOT NULL,         -- command to run (panel-validated)
  `last_run` DATETIME DEFAULT NULL,
  `next_run` DATETIME DEFAULT NULL,         -- optional precomputed
  `status` ENUM('enabled','disabled') DEFAULT 'enabled',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_cron_jobs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `ix_cron_jobs_user` (`user_id`),
  INDEX `ix_cron_jobs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
