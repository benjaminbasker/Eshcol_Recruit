-- Position types (own-hiring and staffing plan v2, phase 1, 15 November 2026).
-- Every position is now one of: client recruitment (the client employs; as before), staffing (Eshcol employs and
-- deputes to the client) or Eshcol Global's own team. Existing positions are all client recruitment.
ALTER TABLE roles ADD COLUMN engagement ENUM('placement','staffing','inhouse') NOT NULL DEFAULT 'placement' AFTER client_id,
  ADD COLUMN hiring_manager_id INT UNSIGNED NULL AFTER owner_user_id,
  ADD COLUMN contract_months SMALLINT UNSIGNED NULL AFTER engagement,
  ADD KEY ix_roles_engagement (engagement),
  ADD CONSTRAINT fk_roles_hm FOREIGN KEY (hiring_manager_id) REFERENCES users(id) ON DELETE SET NULL;
;;

-- Eshcol Global as a "client" of itself, for its own positions. Screens show it as "Eshcol Global (our own team)".
ALTER TABLE clients ADD COLUMN is_self TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
;;
INSERT INTO clients (name, legal_name, is_self, interview_address, notes)
SELECT 'Eshcol Global', 'Eshcol Global Services Private Limited', 1,
       '#714A, Cabin # 3, Spencer Plaza, 7th Floor, Phase-II, #769, Anna Salai, Thousand Lights, Chennai - 600 002, Tamil Nadu, India',
       'Our own team: positions Eshcol Global hires for itself.'
WHERE NOT EXISTS (SELECT 1 FROM clients WHERE is_self = 1);
;;

-- Staffing: is the candidate open to a contract role on Eshcol's payroll? (asked on staffing positions' details form)
ALTER TABLE candidates ADD COLUMN open_to_contract ENUM('yes','no','more_info') NULL,
  ADD COLUMN open_to_contract_at DATETIME NULL;
;;

-- Interview feedback, one form per person per round (any position type).
CREATE TABLE interview_feedback (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id  INT UNSIGNED NOT NULL,
  round           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  user_id         INT UNSIGNED NULL,
  interviewer     VARCHAR(160) NULL,
  rating          TINYINT UNSIGNED NULL,
  strengths       TEXT NULL,
  concerns        TEXT NULL,
  recommend       ENUM('yes','maybe','no') NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_if_app (application_id, round),
  CONSTRAINT fk_if_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_if_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;

-- Reference checks: who was asked, how they know the candidate, what they said.
CREATE TABLE reference_checks (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id  INT UNSIGNED NOT NULL,
  referee         VARCHAR(160) NOT NULL,
  relation        VARCHAR(160) NULL,
  contact         VARCHAR(190) NULL,
  outcome         ENUM('positive','mixed','negative') NULL,
  summary         TEXT NULL,
  checked_on      DATE NULL,
  user_id         INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rc_app (application_id),
  CONSTRAINT fk_refc_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_refc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;

-- Offer approval (own team and staffing): an Eshcol offer is approved by the owner before it goes out.
CREATE TABLE offer_approvals (
  application_id  INT UNSIGNED NOT NULL PRIMARY KEY,
  designation     VARCHAR(160) NULL,
  ctc_lpa         DECIMAL(7,2) NULL,
  joining_date    DATE NULL,
  note            VARCHAR(500) NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_oa_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_oa_user FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
