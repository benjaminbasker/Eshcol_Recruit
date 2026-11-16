-- Eshcol Recruit: Claude's fit assessment (hybrid). The free check decides who is relevant to a
-- role; Claude then assesses those candidates against the role's requirements, with evidence,
-- and the app scores the answers with fixed weights (must-haves 40, relevant experience 30,
-- seniority 10, qualification 10, stability and fit 10).
CREATE TABLE role_requirements (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id   INT UNSIGNED NOT NULL,
  text      VARCHAR(250) NOT NULL,
  critical  TINYINT(1) NOT NULL DEFAULT 0,
  sort      SMALLINT NOT NULL DEFAULT 0,
  KEY ix_rreq_role (role_id),
  CONSTRAINT fk_rreq_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
ALTER TABLE roles ADD COLUMN qual_ladder JSON NULL AFTER screening;
;;
-- The CV cannot have been written before the job it lists as current started (from Claude's reading).
ALTER TABLE candidates ADD COLUMN cv_not_before DATE NULL AFTER cv_dated;
;;
CREATE TABLE fit_assessments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id  INT UNSIGNED NOT NULL,
  candidate_id    INT UNSIGNED NOT NULL,
  role_id         INT UNSIGNED NOT NULL,
  status          ENUM('pending','reading','done','failed') NOT NULL DEFAULT 'pending',
  role_version    CHAR(32) NULL,
  result          MEDIUMTEXT NULL,
  score           TINYINT UNSIGNED NULL,
  band            VARCHAR(10) NULL,
  breakdown       TEXT NULL,
  model           VARCHAR(60) NULL,
  tokens_in       INT UNSIGNED NULL,
  tokens_out      INT UNSIGNED NULL,
  cost_usd        DECIMAL(10,5) NULL,
  error           VARCHAR(300) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL,
  UNIQUE KEY uq_fit_app (application_id),
  KEY ix_fit_status (status),
  KEY ix_fit_role (role_id, score),
  CONSTRAINT fk_fit_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
INSERT INTO settings (k, v) VALUES ('fit_enabled', '1') ON DUPLICATE KEY UPDATE v = v;
;;
INSERT INTO settings (k, v) VALUES ('fit_monthly_limit', '600') ON DUPLICATE KEY UPDATE v = v;
