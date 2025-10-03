-- Seed sample packages (idempotent)

INSERT INTO packages (name, disk_space, bandwidth, domains_limit, subdomains_limit, email_accounts, databases_limit, ftp_accounts, ssl_certificates, price)
SELECT 'Starter', 1024, 10240, 1, 5, 5, 1, 2, 1, 9.99
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Starter');

INSERT INTO packages (name, disk_space, bandwidth, domains_limit, subdomains_limit, email_accounts, databases_limit, ftp_accounts, ssl_certificates, price)
SELECT 'Professional', 5120, 51200, 5, 25, 25, 5, 10, 5, 19.99
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Professional');

INSERT INTO packages (name, disk_space, bandwidth, domains_limit, subdomains_limit, email_accounts, databases_limit, ftp_accounts, ssl_certificates, price)
SELECT 'Business', 10240, 102400, 10, 50, 50, 10, 20, 10, 39.99
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Business');

INSERT INTO packages (name, disk_space, bandwidth, domains_limit, subdomains_limit, email_accounts, databases_limit, ftp_accounts, ssl_certificates, price)
SELECT 'Enterprise', 20480, 204800, 25, 100, 100, 25, 50, 25, 79.99
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Enterprise');
