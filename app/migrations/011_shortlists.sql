-- 011: client shortlists (design doc §2.7).
--
-- You choose candidates for a role, add a line about each, and send the client a private link.
-- The client sees a profile for each (contact details withheld), marks them Shortlist, Hold or
-- Not suitable with a comment, and can download the profiles as PDF. No account needed.
-- What the client sees is a snapshot taken when you send, so later edits don't change it.

CREATE TABLE shortlists (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id          INT UNSIGNED NOT NULL,
  token_hash       CHAR(64) NOT NULL,
  token_enc        VARCHAR(255) NOT NULL,
  to_name          VARCHAR(160) NULL,
  to_email         VARCHAR(190) NOT NULL,
  cc               VARCHAR(500) NULL,
  note             TEXT NULL,
  show_salary      TINYINT(1) NOT NULL DEFAULT 1,
  status           ENUM('sent','viewed','answered','closed') NOT NULL DEFAULT 'sent',
  email_id         INT UNSIGNED NULL,
  expires_at       DATETIME NOT NULL,
  first_viewed_at  DATETIME NULL,
  answered_at      DATETIME NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sl_token (token_hash),
  KEY ix_sl_role (role_id),
  CONSTRAINT fk_sl_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
CREATE TABLE shortlist_items (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shortlist_id   INT UNSIGNED NOT NULL,
  application_id INT UNSIGNED NOT NULL,
  sort           SMALLINT NOT NULL DEFAULT 0,
  summary        TEXT NULL,
  profile        JSON NOT NULL,
  decision       ENUM('shortlist','hold','reject') NULL,
  comment        TEXT NULL,
  decided_at     DATETIME NULL,
  UNIQUE KEY uq_sli (shortlist_id, application_id),
  KEY ix_sli_app (application_id),
  CONSTRAINT fk_sli_sl FOREIGN KEY (shortlist_id) REFERENCES shortlists(id) ON DELETE CASCADE,
  CONSTRAINT fk_sli_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
INSERT INTO settings (k, v) VALUES
  ('shortlist_days', '30')
ON DUPLICATE KEY UPDATE v = v;
