-- Saved views on the candidate list (B15, 14 November 2026): a set of filters saved under a name
-- ("Accounts, Pune, 2-5 yrs"), per person, one click to open again.
CREATE TABLE saved_views (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(80) NOT NULL,
  query       VARCHAR(1000) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sv_user (user_id, name),
  CONSTRAINT fk_sv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
