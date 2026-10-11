-- Donation & Offering: each church's payment details, and the donations people submit.
-- For databases imported before these existed (run ONCE):
--   mysql -u root church_management_system < database/donations.sql
-- schema.sql already includes them for fresh installs.
USE church_management_system;

-- Where donors send money. Filled in by the church's own admin on the Donation management page.
ALTER TABLE church_info
    ADD COLUMN donation_bank_name      VARCHAR(100) NULL,
    ADD COLUMN donation_account_name   VARCHAR(100) NULL,
    ADD COLUMN donation_account_number VARCHAR(40)  NULL,
    ADD COLUMN donation_qr_image       VARCHAR(100) NULL;   -- file name inside uploads/donation_qr/

-- One row per submitted donation. The receipt is a private file in uploads/receipts/ that is only
-- served to the church's admins (receipt.php). Financial records are never deleted with a church:
-- a church that has donations cannot be deleted.
CREATE TABLE IF NOT EXISTS donations (
    donation_id   INT AUTO_INCREMENT PRIMARY KEY,
    church_id     INT NOT NULL,
    donor_user_id INT NULL,                                   -- set when the donor was signed in
    donor_name    VARCHAR(100) NOT NULL,
    donor_phone   VARCHAR(30)  NOT NULL,
    donor_email   VARCHAR(100) NOT NULL,
    purpose       VARCHAR(100) NOT NULL,                      -- e.g. Weekly Offering, Tithe, Building Fund
    message       VARCHAR(500) NULL,
    amount        DECIMAL(12,2) NOT NULL,
    method        ENUM('bank','qr') NOT NULL,
    receipt_file  VARCHAR(150) NOT NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reject_reason VARCHAR(255) NULL,
    reviewed_by   INT NULL,
    reviewed_at   DATETIME NULL,
    email_sent_at DATETIME NULL,                              -- when the donor was told the result
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_don_church_status (church_id, status, created_at),
    INDEX idx_don_email_time (donor_email, created_at),
    CONSTRAINT fk_don_church FOREIGN KEY (church_id)     REFERENCES church_info(church_info_id) ON DELETE RESTRICT,
    CONSTRAINT fk_don_user   FOREIGN KEY (donor_user_id) REFERENCES user(user_id)               ON DELETE SET NULL,
    CONSTRAINT fk_don_admin  FOREIGN KEY (reviewed_by)   REFERENCES admin(admin_id)             ON DELETE SET NULL
);

-- Sacred Heart Cathedral's bank account, as given in the design.
UPDATE church_info SET
    donation_bank_name      = 'Alliance Bank Malaysia Berhad',
    donation_account_name   = 'RTDKK - Sacred Heart Caritas',
    donation_account_number = '101960010200370'
WHERE church_name = 'Sacred Heart Cathedral' AND donation_bank_name IS NULL;
