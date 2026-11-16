-- Placements (B9, 14 November 2026): offer details, joining, fee, invoice tracking (invoices themselves are
-- raised in Zoho Books; the app only records the number and dates), and the replacement guarantee.
-- Benjamin's rules: the % fee is on the total annual CTC including variable pay; money is seen by the owner
-- only; kept (with the name only) if the candidate is later deleted; a candidate who leaves within the guarantee reopens the same position as a replacement, no new fee.
CREATE TABLE placements (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id   INT UNSIGNED NULL,
  candidate_id     INT UNSIGNED NULL,
  candidate_name   VARCHAR(160) NULL,
  role_id          INT UNSIGNED NOT NULL,
  client_id        INT UNSIGNED NULL,
  status           ENUM('offer','joined','left','withdrew') NOT NULL DEFAULT 'offer',
  offer_date       DATE NULL,
  offer_ctc_lpa    DECIMAL(7,2) NULL,
  expected_join    DATE NULL,
  joined_on        DATE NULL,
  fee_type         ENUM('percent','fixed') NULL,
  fee_value        DECIMAL(12,2) NULL,
  fee_amount       DECIMAL(12,2) NULL,
  fee_overridden   TINYINT(1) NOT NULL DEFAULT 0,
  replacement_of   INT UNSIGNED NULL,
  invoice_no       VARCHAR(60) NULL,
  invoiced_on      DATE NULL,
  payment_terms_days SMALLINT UNSIGNED NULL,
  paid_on          DATE NULL,
  guarantee_until  DATE NULL,
  checkin30_at     DATETIME NULL,
  checkin60_at     DATETIME NULL,
  left_on          DATE NULL,
  left_note        VARCHAR(255) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pl_app (application_id),
  KEY ix_pl_status (status, joined_on),
  KEY ix_pl_role (role_id),
  CONSTRAINT fk_pl_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
  CONSTRAINT fk_pl_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
ALTER TABLE roles ADD COLUMN replacement_for INT UNSIGNED NULL;
;;
-- People already at a "success" stage (Joined) get a placement to complete.
INSERT IGNORE INTO placements (application_id, candidate_id, candidate_name, role_id, client_id, status, fee_type, fee_value, payment_terms_days)
SELECT a.id, a.candidate_id, cd.full_name, a.role_id, r.client_id, 'joined', c.fee_type, c.fee_value, c.payment_terms_days
FROM applications a JOIN stages s ON s.id = a.stage_id JOIN roles r ON r.id = a.role_id LEFT JOIN clients c ON c.id = r.client_id JOIN candidates cd ON cd.id = a.candidate_id
WHERE s.kind = 'success' AND r.is_pool = 0;
