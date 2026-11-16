-- "Analyse with AI": Claude's written analysis of a CV, kept so it is paid for once.
-- Deleted with the candidate.
CREATE TABLE cv_ai_reports (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  candidate_id   INT UNSIGNED NOT NULL,
  cv_file_id     INT UNSIGNED NULL,
  model          VARCHAR(60) NOT NULL,
  report         JSON NOT NULL,
  input_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
  cost_usd       DECIMAL(8,4) NOT NULL DEFAULT 0,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ai_cand (candidate_id),
  KEY ix_ai_created (created_at),
  CONSTRAINT fk_ai_cand FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
INSERT INTO settings (k, v) VALUES ('ai_monthly_limit', '150') ON DUPLICATE KEY UPDATE v = v;
