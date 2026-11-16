-- Eshcol Recruit: every CV categorised for future searches, and positions that say who they consider
-- (Benjamin, 13 Nov 2026).
--  - Every candidate gets a category: their field of work (function), specialisation, seniority, and
--    where they would work. Taken from their own work history, not from the job they applied for.
--  - Each position says which fields it takes and whether it takes local candidates only
--    (junior posts: the city matters), people from other cities (senior posts), or remote.
--  - Routing then works by field + experience + location instead of matching words in the CV.
--  - Trial first: in "trial" the old rules still decide and the new ones are only recorded beside them.

-- The fields of work. Built on the dictionary's own job families, so a job title it knows gives a
-- free first guess; Claude decides from the work history.
CREATE TABLE functions (
  code              CHAR(3) NOT NULL PRIMARY KEY,
  name              VARCHAR(80) NOT NULL,
  sort              SMALLINT NOT NULL DEFAULT 0,
  dictionary_areas  VARCHAR(255) NULL,
  hint              VARCHAR(255) NULL,
  is_active         TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
INSERT INTO functions (code, name, sort, dictionary_areas, hint) VALUES
('ACC', 'Accounts, Tax & Audit', 10, 'Accounts & Finance', 'bookkeeping, GST, TDS, income tax, audit, finance, controllership, CA articleship'),
('PAY', 'Payroll & Statutory Compliance', 20, 'HR & Payroll', 'payroll processing, PF, ESI, professional tax, labour law compliance'),
('PRO', 'Procurement & Purchasing', 30, NULL, 'purchase, buying, vendor development, sourcing of materials'),
('SCM', 'Supply Chain, Logistics & Stores', 40, 'Supply Chain & Logistics', 'logistics, dispatch, warehouse, stores, freight, import and export'),
('SAL', 'Sales & Business Development', 50, 'Sales & Business Development', 'field sales, key accounts, business development, pre-sales'),
('MKT', 'Marketing & Communications', 60, 'Marketing & Communications', 'marketing, brand, digital marketing, PR, content'),
('CSB', 'Customer Service & BPO Operations', 70, 'Customer Service', 'customer support, call centre, back office, process associate'),
('HRR', 'HR & Recruitment', 80, 'HR & Recruitment', 'recruitment, HR generalist, HR business partner, training'),
('ADM', 'Admin & Office', 90, 'Admin & Office', 'office administration, front office, executive assistant, data entry'),
('LEG', 'Legal & Secretarial', 100, 'Legal & Secretarial', 'legal, company secretary, contracts, compliance'),
('ITS', 'IT, Software & Data', 110, 'IT & Software', 'software development, IT support, testing, data analysis'),
('EDS', 'Engineering Design & Drafting', 120, NULL, 'piping, mechanical, civil, electrical design; draftsman; CAD, PDMS, E3D'),
('EPC', 'Engineering Projects, Site & Document Control', 130, 'Engineering, Projects & EPC', 'project engineering, site execution, planning, document control'),
('MFG', 'Manufacturing, Plant & Maintenance', 140, NULL, 'production, plant operations, maintenance, plant manager'),
('QHS', 'Quality, Inspection & HSE', 150, NULL, 'quality assurance and control, inspection, welding inspection, safety'),
('BFI', 'Banking, Insurance & Investments', 160, NULL, 'branch banking, loans, KYC, insurance operations, wealth management'),
('MGT', 'General Management & Operations', 170, 'Management & Operations', 'general managers, operations heads, business heads'),
('REF', 'Real Estate & Facilities', 180, 'Real Estate & Facilities', 'property sales, leasing, facility management'),
('HHE', 'Healthcare, Hospitality & Education', 190, 'Healthcare, Hospitality & Education', 'nursing, hotels, teaching, training institutes'),
('OTH', 'Other', 200, NULL, 'anything that fits none of the above');
;;
-- One row per categorisation; the current one is_current = 1. A recruiter's correction is a row of its
-- own (source 'recruiter') and wins until a new CV arrives. Nothing is overwritten, so history stays.
CREATE TABLE candidate_categories (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id       INT UNSIGNED NOT NULL,
  cv_file_id         INT UNSIGNED NULL,
  function_code      CHAR(3) NOT NULL,
  secondary_code     CHAR(3) NULL,
  specialisation     VARCHAR(160) NULL,
  original_title     VARCHAR(160) NULL,
  normalised_title   VARCHAR(160) NULL,
  seniority          ENUM('trainee','junior','mid','senior','lead','manager','head') NULL,
  current_city       VARCHAR(120) NULL,
  preferred_cities   VARCHAR(255) NULL,
  relocation         ENUM('yes','no','unknown') NOT NULL DEFAULT 'unknown',
  remote_ok          ENUM('yes','no','unknown') NOT NULL DEFAULT 'unknown',
  employment_status  VARCHAR(40) NULL,
  latest_role_from   VARCHAR(7) NULL,
  latest_role_to     VARCHAR(7) NULL,
  evidence           JSON NULL,
  confidence         ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  needs_review       TINYINT(1) NOT NULL DEFAULT 0,
  reviewed_at        DATETIME NULL,
  reviewed_by        INT UNSIGNED NULL,
  review_changed     TINYINT(1) NULL,
  source             ENUM('ai','recruiter','free') NOT NULL,
  model              VARCHAR(60) NULL,
  prompt_version     VARCHAR(20) NULL,
  tokens_in          INT UNSIGNED NULL,
  tokens_out         INT UNSIGNED NULL,
  cost_usd           DECIMAL(10,5) NULL,
  is_current         TINYINT(1) NOT NULL DEFAULT 1,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cc_cand (candidate_id, is_current),
  KEY ix_cc_func (is_current, function_code),
  KEY ix_cc_sec (is_current, secondary_code),
  KEY ix_cc_review (is_current, needs_review, reviewed_at),
  CONSTRAINT fk_cc_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
-- CVs waiting to be categorised (each new CV file, and the ones on file when asked).
CREATE TABLE categorise_queue (
  candidate_id  INT UNSIGNED NOT NULL PRIMARY KEY,
  cv_file_id    INT UNSIGNED NULL,
  status        ENUM('pending','working','done','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error         VARCHAR(300) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL,
  KEY ix_cq_status (status, created_at),
  CONSTRAINT fk_cq_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
-- Who a position considers. Junior posts take local candidates only; senior posts people from other cities.
ALTER TABLE roles
  ADD COLUMN function_codes VARCHAR(60) NULL AFTER work_mode,
  ADD COLUMN location_scope ENUM('local','open','remote') NULL AFTER function_codes;
;;
UPDATE roles SET location_scope = CASE WHEN work_mode = 'remote' THEN 'remote' WHEN exp_min IS NOT NULL AND exp_min >= 8 THEN 'open' ELSE 'local' END
WHERE is_pool = 0;
;;
-- "Found in your database": the ones you added from it, or said "not this one" to.
CREATE TABLE role_suggestions (
  role_id       INT UNSIGNED NOT NULL,
  candidate_id  INT UNSIGNED NOT NULL,
  status        ENUM('added','dismissed') NOT NULL,
  decided_by    INT UNSIGNED NULL,
  decided_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, candidate_id),
  CONSTRAINT fk_rs_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rs_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
-- The trial: what the old rules did and what the new ones would do, for each person and open position.
CREATE TABLE routing_trial (
  candidate_id  INT UNSIGNED NOT NULL,
  role_id       INT UNSIGNED NOT NULL,
  old_result    VARCHAR(30) NOT NULL,
  old_reason    VARCHAR(255) NULL,
  new_result    VARCHAR(30) NOT NULL,
  new_reason    VARCHAR(255) NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (candidate_id, role_id),
  KEY ix_rt_role (role_id),
  CONSTRAINT fk_rt_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
  CONSTRAINT fk_rt_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
-- The category test (benchmark): made-up CVs with known answers, read by the free guess, Haiku and Sonnet.
CREATE TABLE category_tests (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
CREATE TABLE category_test_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  test_id     INT UNSIGNED NOT NULL,
  case_key    VARCHAR(40) NOT NULL,
  reader      ENUM('free','haiku','sonnet') NOT NULL,
  status      ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
  result      JSON NULL,
  tokens_in   INT UNSIGNED NULL,
  tokens_out  INT UNSIGNED NULL,
  cost_usd    DECIMAL(10,5) NULL,
  ms          INT UNSIGNED NULL,
  error       VARCHAR(300) NULL,
  updated_at  DATETIME NULL,
  UNIQUE KEY uq_cti (test_id, case_key, reader),
  CONSTRAINT fk_cti_test FOREIGN KEY (test_id) REFERENCES category_tests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
INSERT IGNORE INTO settings (k, v) VALUES ('category_mode', 'trial'), ('category_monthly_limit', '3000');
