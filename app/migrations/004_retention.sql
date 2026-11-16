-- Rolling retention, deletion review, and a privacy-safe "do not contact" list.

ALTER TABLE candidates
  ADD COLUMN last_activity_at DATETIME NULL AFTER last_confirmed_at,
  ADD COLUMN retention_note VARCHAR(255) NULL AFTER purge_after;
;;
UPDATE candidates SET last_activity_at = COALESCE(last_confirmed_at, created_at) WHERE last_activity_at IS NULL;
;;
-- People who asked to be removed. Only one-way hashes of their email and phone are
-- kept, never the details themselves, so a future CV from them can be flagged.
CREATE TABLE suppression (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hash        CHAR(64) NOT NULL,
  kind        ENUM('email','phone') NOT NULL,
  reason      VARCHAR(40) NOT NULL DEFAULT 'requested',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supp (hash)
) ENGINE=InnoDB;
;;
-- Tracks which CV files are already in the off-site backup, so they can be removed
-- from it too when a candidate is deleted.
ALTER TABLE cv_files ADD COLUMN backed_up_at DATETIME NULL;
