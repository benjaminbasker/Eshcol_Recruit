-- Eshcol Recruit (14 Nov 2026), from the audit's backlog:
--  B1 Bounces: Brevo tells the app when an address bounces, is blocked, complains or unsubscribes; the
--     app records it and stops emailing that address.
--  B2 Consent: what each person agreed to, how and when, so re-contacting old applicants can be shown
--     to follow India's DPDP Act (notice, purpose, withdrawal).
--  B8 Jobs health: every run of each scheduled task, so a failure shows on screen.

ALTER TABLE candidates
  ADD COLUMN email_status ENUM('ok','soft_bounce','hard_bounce','blocked','complained','unsubscribed') NOT NULL DEFAULT 'ok',
  ADD COLUMN email_status_at DATETIME NULL,
  ADD COLUMN email_status_note VARCHAR(255) NULL,
  ADD KEY ix_cand_email_status (email_status);
;;
ALTER TABLE email_queue
  ADD COLUMN delivery VARCHAR(20) NULL,
  ADD COLUMN delivery_at DATETIME NULL,
  ADD KEY ix_eq_provider (provider_id);
;;
CREATE TABLE email_events (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email_id     INT UNSIGNED NULL,
  candidate_id INT UNSIGNED NULL,
  to_email     VARCHAR(190) NOT NULL,
  event        VARCHAR(30) NOT NULL,
  reason       VARCHAR(255) NULL,
  provider_id  VARCHAR(190) NULL,
  event_at     DATETIME NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ee_to (to_email, event_at),
  KEY ix_ee_email (email_id),
  KEY ix_ee_cand (candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
CREATE TABLE consents (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id   INT UNSIGNED NOT NULL,
  purpose        ENUM('future_roles') NOT NULL DEFAULT 'future_roles',
  status         ENUM('notice_sent','given','withdrawn') NOT NULL,
  via            VARCHAR(40) NOT NULL,
  notice_version VARCHAR(20) NULL,
  email_id       INT UNSIGNED NULL,
  note           VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_consent_cand (candidate_id, created_at),
  CONSTRAINT fk_consent_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
CREATE TABLE job_runs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task        VARCHAR(40) NOT NULL,
  started_at  DATETIME NOT NULL,
  seconds     DECIMAL(7,2) NOT NULL DEFAULT 0,
  ok          TINYINT(1) NOT NULL DEFAULT 1,
  message     VARCHAR(500) NULL,
  KEY ix_jr_task (task, started_at),
  KEY ix_jr_when (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
