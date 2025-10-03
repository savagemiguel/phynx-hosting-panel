-- Add ssl_enabled column to domains (idempotent for MySQL 8.0+)
ALTER TABLE `domains`
  ADD COLUMN IF NOT EXISTS `ssl_enabled` TINYINT DEFAULT 0 AFTER `status`;
