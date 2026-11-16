-- 012: version 2 foundations (proposal of 6 Nov 2026).
--
--  * Your signature: title and mobile on each person, used on CVs, emails and call letters.
--  * Clients: website, interview address, Google Maps link, default meeting link.
--  * Documents: a company profile per client and a JD file per role, attached to call letters.
--  * Shortlists in your own email format: drafts, branded CVs per candidate, remarks.
--  * Interviews: rounds, slots offered by the client, candidate self-booking, call letters,
--    a reminder 4 hours before, and the outcome of each round.
-- Reference numbers (EG-10042, SL-0012, IV-0031) are worked out from ids, so need no column.

ALTER TABLE users
  ADD COLUMN title VARCHAR(120) NULL AFTER name,
  ADD COLUMN mobile VARCHAR(30) NULL AFTER title;
;;
ALTER TABLE clients
  ADD COLUMN website VARCHAR(255) NULL AFTER legal_name,
  ADD COLUMN interview_address TEXT NULL AFTER billing_address,
  ADD COLUMN maps_url VARCHAR(1000) NULL AFTER interview_address,
  ADD COLUMN meeting_link VARCHAR(255) NULL AFTER maps_url;
;;
CREATE TABLE documents (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_type     ENUM('client','role') NOT NULL,
  owner_id       INT UNSIGNED NOT NULL,
  kind           ENUM('company_profile','jd','other') NOT NULL,
  original_name  VARCHAR(255) NOT NULL,
  storage_path   VARCHAR(255) NOT NULL,
  mime           VARCHAR(100) NOT NULL,
  size_bytes     INT UNSIGNED NOT NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_doc_owner (owner_type, owner_id, kind)
) ENGINE=InnoDB;
;;
ALTER TABLE shortlists
  MODIFY status ENUM('draft','sent','viewed','answered','closed') NOT NULL DEFAULT 'sent',
  ADD COLUMN mode ENUM('cvs','profiles') NOT NULL DEFAULT 'cvs' AFTER show_salary,
  ADD COLUMN sent_at DATETIME NULL AFTER email_id;
;;
ALTER TABLE shortlist_items
  ADD COLUMN branded_path VARCHAR(255) NULL AFTER profile,
  ADD COLUMN branded_size INT UNSIGNED NULL AFTER branded_path,
  ADD COLUMN branded_at DATETIME NULL AFTER branded_size;
;;
-- Times the client offers for a round; each can be taken by one candidate.
CREATE TABLE interview_slots (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id       INT UNSIGNED NOT NULL,
  round         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  kind          ENUM('virtual','in_person','phone') NOT NULL DEFAULT 'virtual',
  starts_at     DATETIME NOT NULL,
  duration_min  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  source        ENUM('client','you') NOT NULL DEFAULT 'you',
  shortlist_id  INT UNSIGNED NULL,
  interview_id  INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_slot_role (role_id, round, starts_at),
  CONSTRAINT fk_slot_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
CREATE TABLE interviews (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  round          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  kind           ENUM('virtual','in_person','phone') NOT NULL DEFAULT 'virtual',
  status         ENUM('choosing','booked','done','cancelled','no_show') NOT NULL DEFAULT 'choosing',
  starts_at      DATETIME NULL,
  duration_min   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  meeting_link   VARCHAR(255) NULL,
  address        TEXT NULL,
  maps_url       VARCHAR(1000) NULL,
  token_hash     CHAR(64) NULL,
  token_enc      VARCHAR(255) NULL,
  invite_email_id INT UNSIGNED NULL,
  letter_email_id INT UNSIGNED NULL,
  letter_sent_at DATETIME NULL,
  reminded_at    DATETIME NULL,
  outcome        ENUM('next_round','selected','not_selected','on_hold') NULL,
  feedback       TEXT NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_iv_token (token_hash),
  KEY ix_iv_app (application_id),
  KEY ix_iv_due (status, starts_at),
  CONSTRAINT fk_iv_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
INSERT INTO settings (k, v) VALUES
  ('interview_reminder_hours', '4'),
  ('letter_virtual', 'As discussed, your interview with our client, {{client_line}}, is confirmed for {{date}}, at {{time}} HRS. Please join using the {{meeting_tool}} link provided below.\n\n{{meeting_link}}\n\nFor the best interview experience, please:\n- Join from a laptop or desktop computer\n- Ensure you''re in a quiet environment with stable internet connectivity\n- Log in at least 5 minutes early\n\nI''ve attached the job description again for your reference and preparation.\n\nBest of luck with your interview!'),
  ('letter_in_person', 'Following our conversation, I''m pleased to confirm that your personal interview with our client, {{client_name}}, has been scheduled for:\n\nDate & Time: {{date}}, at {{time}} Hrs\nLocation: {{address}}\nMap Link: {{maps_url}}\n\nFor your reference, I''ve attached the company profile and job description.\n\nWishing you all the best for the interview!\n\nPlease confirm your availability at your earliest convenience.')
ON DUPLICATE KEY UPDATE v = v;
