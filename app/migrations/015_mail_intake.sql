-- CVs emailed to resume@: one row per email the app has looked at, so none is
-- imported twice and you can see what happened to each.
CREATE TABLE mail_intake (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_key   CHAR(64) NOT NULL,
  received_at   DATETIME NULL,
  from_email    VARCHAR(190) NULL,
  from_name     VARCHAR(190) NULL,
  subject       VARCHAR(255) NULL,
  role_id       INT UNSIGNED NULL,
  outcome       VARCHAR(20) NOT NULL,
  detail        TEXT NULL,
  candidate_ids VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_intake_key (message_key),
  KEY ix_mail_intake_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
