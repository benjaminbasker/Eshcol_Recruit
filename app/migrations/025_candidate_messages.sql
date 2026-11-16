-- 025: messages from candidates (Benjamin, 7 Nov 2026).
--
-- A reply to one of our emails (it reaches resume@) from a known candidate is kept as a message
-- on their record, instead of only "no CV" in the inbox log. So is anything they write in the
-- new "Anything else you would like to share?" box on the details page.
-- "Please remove me" (and the like) is flagged for Benjamin to confirm; never acted on by itself.

CREATE TABLE candidate_messages (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id    INT UNSIGNED NOT NULL,
  source          ENUM('email','form') NOT NULL DEFAULT 'email',
  from_email      VARCHAR(190) NULL,
  subject         VARCHAR(255) NULL,
  body            TEXT NOT NULL,
  received_at     DATETIME NOT NULL,
  removal         ENUM('none','asked','confirmed','dismissed') NOT NULL DEFAULT 'none',
  read_at         DATETIME NULL,
  mail_intake_id  INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cm_cand (candidate_id, received_at),
  KEY ix_cm_unread (read_at),
  KEY ix_cm_removal (removal),
  CONSTRAINT fk_cm_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
