-- Eshcol Recruit: Claude (Sonnet) reads every CV. New CVs are queued and read within five
-- minutes; existing CVs can be re-read, with every change shown for approval first.
CREATE TABLE cv_ai_reads (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cv_file_id    INT UNSIGNED NOT NULL,
  candidate_id  INT UNSIGNED NOT NULL,
  mode          ENUM('auto','review') NOT NULL DEFAULT 'auto',
  status        ENUM('pending','reading','done','failed') NOT NULL DEFAULT 'pending',
  model         VARCHAR(60) NULL,
  fields        MEDIUMTEXT NULL,
  raw           MEDIUMTEXT NULL,
  transcribed   TINYINT(1) NOT NULL DEFAULT 0,
  tokens_in     INT UNSIGNED NULL,
  tokens_out    INT UNSIGNED NULL,
  cost_usd      DECIMAL(10,5) NULL,
  ms            INT UNSIGNED NULL,
  error         VARCHAR(300) NULL,
  applied_at    DATETIME NULL,
  applied_fields VARCHAR(400) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL,
  UNIQUE KEY uq_air_file (cv_file_id),
  KEY ix_air_status (status, mode),
  KEY ix_air_cand (candidate_id),
  KEY ix_air_created (created_at),
  CONSTRAINT fk_air_file FOREIGN KEY (cv_file_id) REFERENCES cv_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_air_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
ALTER TABLE cv_files MODIFY text_source ENUM('parser','browser_ocr','manual','ai') NOT NULL DEFAULT 'parser';
;;
INSERT INTO settings (k, v) VALUES ('ai_read_enabled', '1') ON DUPLICATE KEY UPDATE v = v;
;;
INSERT INTO settings (k, v) VALUES ('ai_read_monthly_limit', '600') ON DUPLICATE KEY UPDATE v = v;
