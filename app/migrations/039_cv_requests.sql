-- "Share your updated CV" (14 November 2026): one email to people whose CV we hold from earlier applications,
-- sent in Benjamin's order (likely fits first, then other cities, then other fields), at most 175 a day, with
-- reminders on day 3 (everyone) and day 7 (group 1). Each request has a personal page with three answers.
CREATE TABLE cv_requests (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id  INT UNSIGNED NOT NULL,
  grp           TINYINT UNSIGNED NOT NULL,
  rank_key      INT UNSIGNED NOT NULL DEFAULT 0,
  role_id       INT UNSIGNED NULL,
  application_id INT UNSIGNED NULL,
  token_hash    CHAR(64) NOT NULL,
  token_enc     VARCHAR(255) NOT NULL,
  status        ENUM('queued','sent','no_answer','answered','skipped','cancelled') NOT NULL DEFAULT 'queued',
  answer        ENUM('share','keep','remove') NULL,
  reminders     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  email_id      INT UNSIGNED NULL,
  skip_reason   VARCHAR(160) NULL,
  sent_at       DATETIME NULL,
  next_at       DATETIME NULL,
  opened_at     DATETIME NULL,
  answered_at   DATETIME NULL,
  cv_at         DATETIME NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cvr_token (token_hash),
  KEY ix_cvr_cand (candidate_id),
  KEY ix_cvr_queue (status, grp, rank_key),
  KEY ix_cvr_next (status, next_at),
  CONSTRAINT fk_cvr_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
INSERT IGNORE INTO settings (k, v) VALUES ('cvreq_daily_cap', '175'), ('cvreq_reminders_g1', '3,7'), ('cvreq_reminders_other', '3'), ('cvreq_paused', '0');
