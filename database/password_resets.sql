-- Password reset tokens (Forgot Password flow).
-- For databases that were imported before this table existed:
--   mysql -u root church_management_system < database/password_resets.sql
-- It is also appended to schema.sql for fresh installs.
USE church_management_system;

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id     INT AUTO_INCREMENT PRIMARY KEY,
    account_type ENUM('admin','user') NOT NULL,   -- which table account_id points into
    account_id   INT NOT NULL,
    token_hash   CHAR(64) NOT NULL UNIQUE,        -- SHA-256 of the emailed token; the raw token is never stored
    expires_at   DATETIME NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_account (account_type, account_id)
);
