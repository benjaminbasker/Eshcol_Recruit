-- The free relevance check, remembered (audit B12, 15 November 2026). A position page used to re-run it for
-- every candidate Claude hadn't assessed, on every visit. The answer is kept with a fingerprint of everything it
-- depends on (the CV, the candidate, their field, the position, its must-haves, the dictionary, the rules in use);
-- when any of those change the fingerprint changes and the check runs again. Also re-checked after a day.
CREATE TABLE relevance_cache (
  application_id  INT UNSIGNED NOT NULL PRIMARY KEY,
  fingerprint     CHAR(32) NOT NULL,
  relevant        TINYINT(1) NOT NULL,
  why             VARCHAR(255) NOT NULL DEFAULT '',
  checked_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rc_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
