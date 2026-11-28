-- 27 Nov 2026 (Benjamin: "read the whole CV against the whole job description, not keywords; and don't evaluate
-- people whose work is miles apart from the position"): Claude reads the entire CV and the entire job description
-- and says whether the candidate's background is the same KIND of work as the position (yes / maybe / no, one
-- plain reason). It starts in TRIAL mode: the answers are recorded and shown on a report page, and nothing is
-- moved or hidden until Benjamin presses a button (or switches the mode to "on").
CREATE TABLE suitability_checks (
  application_id  INT UNSIGNED NOT NULL PRIMARY KEY,
  candidate_id    INT UNSIGNED NOT NULL,
  role_id         INT UNSIGNED NOT NULL,
  status          ENUM('pending','working','done','failed','skipped') NOT NULL DEFAULT 'pending',
  verdict         ENUM('yes','maybe','no') NULL,
  confidence      ENUM('high','medium','low') NULL,
  reason          VARCHAR(500) NULL,
  cv_work         VARCHAR(200) NULL,
  position_work   VARCHAR(200) NULL,
  evidence        TEXT NULL,
  cv_file_id      INT UNSIGNED NULL,
  input_hash      CHAR(64) NULL,
  model           VARCHAR(60) NULL,
  tokens_in       INT UNSIGNED NULL,
  tokens_out      INT UNSIGNED NULL,
  cost_usd        DECIMAL(10,5) NULL,
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error           VARCHAR(300) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL,
  checked_at      DATETIME NULL,
  KEY ix_sc_status (status, created_at),
  KEY ix_sc_role (role_id, verdict),
  CONSTRAINT fk_sc_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
INSERT IGNORE INTO settings (k, v) VALUES ('suitability_mode', 'trial'), ('suitability_reader', 'haiku'), ('suitability_monthly_limit', '3000');
;;
-- Everyone already on an open client position is checked once (Claude Haiku: a few paise each).
INSERT IGNORE INTO suitability_checks (application_id, candidate_id, role_id, status, created_at, updated_at)
SELECT a.id, a.candidate_id, a.role_id, 'pending', NOW(), NOW() FROM applications a JOIN roles r ON r.id = a.role_id
WHERE r.is_pool = 0 AND r.status IN ('open', 'on_hold');
;;
