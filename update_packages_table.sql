-- Add missing columns to packages table
ALTER TABLE packages 
ADD COLUMN ftp_accounts INT NOT NULL DEFAULT 0 AFTER databases_limit,
ADD COLUMN ssl_certificates INT NOT NULL DEFAULT 0 AFTER ftp_accounts;