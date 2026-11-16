-- Audit R4 (16 Nov 2026): off-site CV copies still to delete. Written before a candidate is deleted, removed only once the
-- off-site store confirms; the off-site job retries until then. Holds only the file's storage key, no personal data.
CREATE TABLE remote_deletions (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  remote_key   VARCHAR(255) NOT NULL,
  tries        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error   VARCHAR(255) NULL,
  last_try_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_remote_key (remote_key)
) ENGINE=InnoDB;
;;
