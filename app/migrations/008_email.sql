-- 008: outgoing email (a queue with a daily ceiling) and application acknowledgements.
--
-- Every email the app sends goes through email_queue. The scheduled job sends what is due,
-- most important first, without passing the daily ceiling (Brevo's free plan allows 300 a
-- day and the website shares it, so the app keeps to 250 by default).
--
-- status: pending (waiting its turn), held (needs a person, e.g. "check address"),
--         sent, failed (gave up), cancelled.

CREATE TABLE email_queue (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind          VARCHAR(30) NOT NULL,
  candidate_id  INT UNSIGNED NULL,
  to_email      VARCHAR(190) NOT NULL,
  to_name       VARCHAR(160) NULL,
  subject       VARCHAR(255) NOT NULL,
  html          MEDIUMTEXT NULL,
  text          MEDIUMTEXT NULL,
  meta          JSON NULL,
  priority      TINYINT UNSIGNED NOT NULL DEFAULT 50,
  status        ENUM('pending','held','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  hold_reason   VARCHAR(255) NULL,
  not_before    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error    VARCHAR(255) NULL,
  provider_id   VARCHAR(120) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at       DATETIME NULL,
  KEY ix_mail_due (status, priority, not_before),
  KEY ix_mail_cand (candidate_id),
  KEY ix_mail_sent (sent_at),
  CONSTRAINT fk_mail_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
-- One acknowledgement per candidate per role (role_id 0 = a CV sent with no role).
CREATE TABLE acknowledgements (
  candidate_id  INT UNSIGNED NOT NULL,
  role_id       INT UNSIGNED NOT NULL DEFAULT 0,
  email_id      INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (candidate_id, role_id),
  CONSTRAINT fk_ack_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
ALTER TABLE upload_batches ADD COLUMN send_ack TINYINT(1) NOT NULL DEFAULT 0 AFTER label;
;;
INSERT INTO settings (k, v) VALUES
  ('ack_enabled', '1'),
  ('mail_daily_cap', '250'),
  ('mail_from_email', 'resume@eshcolglobal.com'),
  ('mail_from_name', 'Benjamin Basker, Eshcol Global'),
  ('mail_own_addresses', ''),
  ('mail_live', '0')
ON DUPLICATE KEY UPDATE v = v;
