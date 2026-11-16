-- 009: screening questionnaire (design doc §2.6).
--
-- Each role has standard questions (salary, notice, location, relocation; switched on or off in
-- roles.screening) plus up to 8 of your own. A candidate gets a personal link by email, answers
-- on any phone with no login, and the answers update their record and re-score them. Answers that
-- matter (won't relocate, notice too long, salary above budget, or a "flag if" answer to your own
-- question) are flagged for you. Nobody is moved or rejected automatically.

CREATE TABLE role_questions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id       INT UNSIGNED NOT NULL,
  sort          SMALLINT NOT NULL DEFAULT 0,
  kind          ENUM('yesno','choice','text') NOT NULL DEFAULT 'yesno',
  prompt        VARCHAR(255) NOT NULL,
  options       JSON NULL,
  flag_answers  JSON NULL,
  KEY ix_rq_role (role_id, sort),
  CONSTRAINT fk_rq_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
ALTER TABLE roles ADD COLUMN screening JSON NULL AFTER weights;
;;
-- One row per invitation. The link's token is stored only as a hash (for looking it up) and
-- encrypted (so a reminder can carry the same link).
CREATE TABLE questionnaires (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash      CHAR(64) NOT NULL,
  token_enc       VARCHAR(255) NOT NULL,
  application_id  INT UNSIGNED NOT NULL,
  candidate_id    INT UNSIGNED NOT NULL,
  role_id         INT UNSIGNED NOT NULL,
  status          ENUM('sent','opened','answered','expired','cancelled') NOT NULL DEFAULT 'sent',
  email_id        INT UNSIGNED NULL,
  flags           JSON NULL,
  expires_at      DATETIME NOT NULL,
  opened_at       DATETIME NULL,
  answered_at     DATETIME NULL,
  reminded_at     DATETIME NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_q_token (token_hash),
  KEY ix_q_app (application_id),
  KEY ix_q_due (status, expires_at),
  CONSTRAINT fk_q_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_q_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
-- The question text is kept with each answer, so later edits to the questions don't change history.
CREATE TABLE questionnaire_answers (
  questionnaire_id  INT UNSIGNED NOT NULL,
  qkey              VARCHAR(40) NOT NULL,
  sort              SMALLINT NOT NULL DEFAULT 0,
  prompt            VARCHAR(255) NOT NULL,
  answer            TEXT NULL,
  flagged           TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (questionnaire_id, qkey),
  CONSTRAINT fk_qa_q FOREIGN KEY (questionnaire_id) REFERENCES questionnaires(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
-- "Please don't keep my details": flagged for you to confirm, never acted on automatically.
ALTER TABLE candidates ADD COLUMN removal_requested_at DATETIME NULL AFTER do_not_contact;
;;
INSERT INTO settings (k, v) VALUES
  ('questionnaire_days', '14'),
  ('questionnaire_reminder_days', '3')
ON DUPLICATE KEY UPDATE v = v;
