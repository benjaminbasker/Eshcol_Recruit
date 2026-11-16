-- Analyser improvements 6, 5b and 7 (approved by Benjamin, 15 November 2026).
-- 6: up to 3 "examples" per position. A blind profile of each (built by the app, free) goes into the position's
--    cached part of Claude's instructions; Claude says how close each new candidate is to them (shown, not scored).
--    fit_assessments.examples_ver records which set of examples an assessment saw (NULL: none).
CREATE TABLE role_examples (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id         INT UNSIGNED NOT NULL,
  candidate_id    INT UNSIGNED NOT NULL,
  note            VARCHAR(250) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_example (role_id, candidate_id),
  CONSTRAINT fk_ex_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_ex_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
ALTER TABLE fit_assessments ADD COLUMN examples_ver CHAR(32) NULL AFTER role_version;
;;
-- 5b: Claude's suggested changes to a position's requirements, from your reasons where you and Claude differed.
-- Nothing changes until you accept one.
CREATE TABLE rule_suggestions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id         INT UNSIGNED NOT NULL,
  kind            ENUM('add','reword','critical','not_critical','remove','ladder') NOT NULL,
  req_number      TINYINT UNSIGNED NULL,
  req_text        VARCHAR(255) NULL,
  text            VARCHAR(255) NOT NULL,
  why             VARCHAR(500) NOT NULL,
  status          ENUM('open','accepted','ignored') NOT NULL DEFAULT 'open',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at      DATETIME NULL,
  KEY ix_rs_role (role_id, status),
  CONSTRAINT fk_rulesug_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
-- 7: the fair check of the top 10: next-door pairs compared both ways round, in a half-price batch.
CREATE TABLE fair_checks (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id         INT UNSIGNED NOT NULL,
  status          ENUM('pending','batched','done','failed') NOT NULL DEFAULT 'pending',
  batch_id        VARCHAR(80) NULL,
  top             TEXT NOT NULL,
  role_version    CHAR(32) NULL,
  results         MEDIUMTEXT NULL,
  cost_usd        DECIMAL(10,5) NULL,
  error           VARCHAR(300) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL,
  KEY ix_fc_role (role_id, id),
  KEY ix_fc_status (status),
  CONSTRAINT fk_fairchk_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
