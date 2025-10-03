-- Seed default admin user (idempotent)

INSERT INTO users (username, email, password, role, status)
SELECT 'admin', 'admin@example.com', 'admin123', 'admin', 'active'
WHERE NOT EXISTS (
  SELECT 1 FROM users WHERE username = 'admin' OR email = 'admin@example.com'
);
