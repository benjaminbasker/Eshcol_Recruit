-- Eshcol Recruit: core schema (phase 1).
-- MariaDB 11.4, InnoDB, utf8mb4. Statements are separated by a line holding only ";;".

CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(120) NOT NULL,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('owner','recruiter') NOT NULL DEFAULT 'recruiter',
  totp_secret     VARCHAR(64) NULL,
  totp_enabled    TINYINT(1) NOT NULL DEFAULT 0,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  last_login_at   DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;
;;
CREATE TABLE auth_throttle (
  ip            VARCHAR(45) NOT NULL PRIMARY KEY,
  attempts      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_start  DATETIME NOT NULL
) ENGINE=InnoDB;
;;
CREATE TABLE audit_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(60) NOT NULL,
  entity_type  VARCHAR(30) NULL,
  entity_id    INT UNSIGNED NULL,
  detail       JSON NULL,
  ip           VARCHAR(45) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_created (created_at),
  KEY ix_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB;
;;
CREATE TABLE settings (
  k  VARCHAR(80) NOT NULL PRIMARY KEY,
  v  TEXT NULL
) ENGINE=InnoDB;
;;
CREATE TABLE clients (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(160) NOT NULL,
  legal_name          VARCHAR(200) NULL,
  billing_address     TEXT NULL,
  gstin               VARCHAR(20) NULL,
  fee_type            ENUM('percent','fixed') NULL,
  fee_value           DECIMAL(12,2) NULL,
  payment_terms_days  SMALLINT UNSIGNED NULL,
  replacement_days    SMALLINT UNSIGNED NULL,
  notes               TEXT NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_clients_name (name),
  CONSTRAINT fk_clients_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
;;
CREATE TABLE client_contacts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id    INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  email        VARCHAR(190) NULL,
  phone        VARCHAR(30) NULL,
  designation  VARCHAR(120) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contacts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
CREATE TABLE stages (
  id         SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NOT NULL,
  sort       SMALLINT NOT NULL DEFAULT 0,
  kind       ENUM('active','success','fail') NOT NULL DEFAULT 'active',
  is_active  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;
;;
CREATE TABLE roles (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id        INT UNSIGNED NOT NULL,
  code             VARCHAR(20) NULL,
  title            VARCHAR(160) NOT NULL,
  location         VARCHAR(160) NULL,
  positions        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  exp_min          DECIMAL(4,1) NULL,
  exp_max          DECIMAL(4,1) NULL,
  ctc_min_lpa      DECIMAL(6,2) NULL,
  ctc_max_lpa      DECIMAL(6,2) NULL,
  notice_max_days  SMALLINT UNSIGNED NULL,
  status           ENUM('draft','open','on_hold','filled','cancelled') NOT NULL DEFAULT 'open',
  status_reason    VARCHAR(200) NULL,
  owner_user_id    INT UNSIGNED NULL,
  jd_text          MEDIUMTEXT NULL,
  weights          JSON NULL,
  score_threshold  TINYINT UNSIGNED NOT NULL DEFAULT 50,
  opened_at        DATE NULL,
  target_date      DATE NULL,
  closed_at        DATE NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_roles_code (code),
  KEY ix_roles_status (status),
  CONSTRAINT fk_roles_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_roles_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
;;
CREATE TABLE role_criteria (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id  INT UNSIGNED NOT NULL,
  kind     ENUM('must','nice','qualification','location','knockout') NOT NULL,
  value    VARCHAR(190) NOT NULL,
  sort     SMALLINT NOT NULL DEFAULT 0,
  KEY ix_criteria_role (role_id, kind),
  CONSTRAINT fk_criteria_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
CREATE TABLE candidates (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name          VARCHAR(160) NULL,
  first_name         VARCHAR(80) NULL,
  email              VARCHAR(190) NULL,
  email_norm         VARCHAR(190) NULL,
  phone              VARCHAR(30) NULL,
  phone_norm         CHAR(10) NULL,
  current_title      VARCHAR(160) NULL,
  current_company    VARCHAR(160) NULL,
  exp_months         SMALLINT UNSIGNED NULL,
  ctc_current_lpa    DECIMAL(6,2) NULL,
  ctc_expected_lpa   DECIMAL(6,2) NULL,
  notice_days        SMALLINT UNSIGNED NULL,
  location           VARCHAR(120) NULL,
  qualifications     VARCHAR(255) NULL,
  source             VARCHAR(30) NOT NULL DEFAULT 'upload',
  field_sources      JSON NULL,
  do_not_contact     TINYINT(1) NOT NULL DEFAULT 0,
  keep_note          VARCHAR(255) NULL,
  purge_after        DATE NULL,
  last_confirmed_at  DATETIME NULL,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_cand_email (email_norm),
  KEY ix_cand_phone (phone_norm),
  KEY ix_cand_name (full_name),
  KEY ix_cand_purge (purge_after)
) ENGINE=InnoDB;
;;
CREATE TABLE candidate_skills (
  candidate_id  INT UNSIGNED NOT NULL,
  skill         VARCHAR(100) NOT NULL,
  PRIMARY KEY (candidate_id, skill),
  KEY ix_skill (skill),
  CONSTRAINT fk_cskills_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
CREATE TABLE cv_files (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id   INT UNSIGNED NULL,
  sha256         CHAR(64) NOT NULL,
  original_name  VARCHAR(255) NOT NULL,
  ext            VARCHAR(10) NOT NULL,
  mime           VARCHAR(100) NULL,
  size_bytes     INT UNSIGNED NOT NULL,
  storage_path   VARCHAR(255) NOT NULL,
  text           MEDIUMTEXT NULL,
  text_status    ENUM('ok','needs_ocr','failed') NOT NULL DEFAULT 'ok',
  text_source    ENUM('parser','browser_ocr','manual') NOT NULL DEFAULT 'parser',
  uploaded_by    INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cv_sha (sha256),
  KEY ix_cv_cand (candidate_id),
  FULLTEXT KEY ft_cv_text (text),
  CONSTRAINT fk_cv_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
CREATE TABLE applications (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id     INT UNSIGNED NOT NULL,
  role_id          INT UNSIGNED NOT NULL,
  stage_id         SMALLINT UNSIGNED NOT NULL,
  score            TINYINT UNSIGNED NULL,
  score_breakdown  JSON NULL,
  scored_at        DATETIME NULL,
  override_score   TINYINT UNSIGNED NULL,
  override_by      INT UNSIGNED NULL,
  override_reason  VARCHAR(255) NULL,
  source           VARCHAR(30) NOT NULL DEFAULT 'upload',
  added_by         INT UNSIGNED NULL,
  applied_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_app (candidate_id, role_id),
  KEY ix_app_role (role_id, stage_id),
  CONSTRAINT fk_app_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
  CONSTRAINT fk_app_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_app_stage FOREIGN KEY (stage_id) REFERENCES stages(id)
) ENGINE=InnoDB;
;;
CREATE TABLE stage_history (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id  INT UNSIGNED NOT NULL,
  from_stage_id   SMALLINT UNSIGNED NULL,
  to_stage_id     SMALLINT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NULL,
  note            VARCHAR(255) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sh_app (application_id),
  CONSTRAINT fk_sh_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
CREATE TABLE notes (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type  ENUM('candidate','role','client') NOT NULL,
  entity_id    INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NULL,
  body         TEXT NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_notes_entity (entity_type, entity_id)
) ENGINE=InnoDB;
;;
CREATE TABLE skills_dictionary (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  term       VARCHAR(100) NOT NULL,
  canonical  VARCHAR(100) NOT NULL,
  category   VARCHAR(40) NOT NULL DEFAULT 'skill',
  UNIQUE KEY uq_term (term),
  KEY ix_canonical (canonical)
) ENGINE=InnoDB;
;;
CREATE TABLE upload_batches (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  role_id     INT UNSIGNED NULL,
  label       VARCHAR(120) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
;;
CREATE TABLE upload_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id      INT UNSIGNED NOT NULL,
  filename      VARCHAR(255) NOT NULL,
  outcome       ENUM('new','updated','duplicate_file','needs_ocr','failed','skipped') NOT NULL,
  message       VARCHAR(255) NULL,
  candidate_id  INT UNSIGNED NULL,
  cv_file_id    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ui_batch (batch_id),
  CONSTRAINT fk_ui_batch FOREIGN KEY (batch_id) REFERENCES upload_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB;
