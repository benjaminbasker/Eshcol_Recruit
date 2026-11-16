-- Staffing, phase 2 of the own-hiring and staffing plan v2 (15 November 2026). Decisions: short payment terms (invoice
-- at month end, due in 5 days, so wages can be paid by the 7th); factory, warehouse and office work in Tiruvallur;
-- minimum margin 8 % of the bill; conversion fee 8.33 % / 4 % / nil; Benjamin confirms every statutory rate himself.

-- A staffing client's commercial terms. A change adds a new version; the old one is kept (is_current = 0).
CREATE TABLE client_terms (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id         INT UNSIGNED NOT NULL,
  is_current        TINYINT(1) NOT NULL DEFAULT 1,
  billing_model     ENUM('markup','fixed_fee','rate') NOT NULL DEFAULT 'markup',
  markup_pct        DECIMAL(6,2) NULL,
  fixed_fee         DECIMAL(10,2) NULL,
  gst_pct           DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  invoice_timing    ENUM('month_end','advance') NOT NULL DEFAULT 'month_end',
  payment_due_days  TINYINT UNSIGNED NOT NULL DEFAULT 5,
  deposit_months    DECIMAL(4,2) NOT NULL DEFAULT 0,
  replacement_days  SMALLINT UNSIGNED NULL,
  notice_days       SMALLINT UNSIGNED NULL,
  conv_pct_6m       DECIMAL(5,2) NOT NULL DEFAULT 8.33,
  conv_pct_12m      DECIMAL(5,2) NOT NULL DEFAULT 4.00,
  conv_pct_after    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  rate_revision     VARCHAR(255) NULL,
  note              VARCHAR(500) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ct_client (client_id, is_current),
  CONSTRAINT fk_ct_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_ct_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;

-- A staffing position: the kind of work and skill level (for the minimum wage), the monthly pay band, and a bill rate
-- when the client pays a fixed monthly rate per person.
ALTER TABLE roles ADD COLUMN work_kind VARCHAR(20) NULL AFTER contract_months,
  ADD COLUMN skill_level VARCHAR(20) NULL AFTER work_kind,
  ADD COLUMN pay_min_month DECIMAL(10,2) NULL AFTER skill_level,
  ADD COLUMN pay_max_month DECIMAL(10,2) NULL AFTER pay_min_month,
  ADD COLUMN bill_rate_month DECIMAL(10,2) NULL AFTER pay_max_month;
;;

-- Statutory rates used for the loaded cost. Suggested values are entered unconfirmed; Benjamin confirms each one
-- (his name and the date are kept). A staffing offer can't be approved while any rate is unconfirmed.
CREATE TABLE statutory_rates (
  k             VARCHAR(40) NOT NULL PRIMARY KEY,
  label         VARCHAR(160) NOT NULL,
  value         DECIMAL(12,4) NOT NULL DEFAULT 0,
  unit          ENUM('pct','inr_month','inr_year','inr') NOT NULL DEFAULT 'pct',
  sort          SMALLINT NOT NULL DEFAULT 0,
  note          VARCHAR(500) NULL,
  confirmed_by  INT UNSIGNED NULL,
  confirmed_at  DATETIME NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sr_user FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
INSERT INTO statutory_rates (k, label, value, unit, sort, note) VALUES
 ('wage_share_pct', 'Wages for PF, gratuity and bonus, as a share of gross pay', 50, 'pct', 10, 'Code on Wages: allowances above half of pay count as wages. Use the share your salary structure gives.'),
 ('pf_employer_pct', 'PF: employer contribution (EPF + EPS)', 12, 'pct', 20, 'On PF wages up to the ceiling below.'),
 ('pf_admin_pct', 'PF: admin charges and EDLI, employer', 1, 'pct', 30, 'Usually 0.50 % admin + 0.50 % EDLI.'),
 ('pf_ceiling', 'PF: wage ceiling per month', 15000, 'inr_month', 40, 'Contributions are worked out on PF wages up to this amount.'),
 ('esi_employer_pct', 'ESI: employer contribution', 3.25, 'pct', 50, 'On gross pay, only for people earning up to the ESI ceiling.'),
 ('esi_ceiling', 'ESI: gross pay ceiling per month', 21000, 'inr_month', 60, NULL),
 ('bonus_pct', 'Statutory bonus provision (minimum)', 8.33, 'pct', 70, NULL),
 ('bonus_eligibility', 'Bonus: eligible up to wages per month', 21000, 'inr_month', 80, 'Re-notified 25 Aug 2026 under the Code on Wages.'),
 ('bonus_calc_ceiling', 'Bonus: worked out on wages up to (or the minimum wage, if higher)', 7000, 'inr_month', 90, NULL),
 ('gratuity_pct', 'Gratuity provision', 4.81, 'pct', 100, 'On wages (15/26 of a month per year). Fixed-term staff earn it pro rata after one year.'),
 ('leave_pct', 'Leave provision', 0, 'pct', 110, 'Share of gross pay set aside for paid leave or encashment, if you budget it.'),
 ('lwf_employer_year', 'Tamil Nadu Labour Welfare Fund: employer, per person per year', 0, 'inr_year', 120, 'Enter the current Tamil Nadu amount.'),
 ('insurance_month', 'Insurance per person per month (if offered)', 0, 'inr_month', 130, NULL),
 ('overhead_pct', 'Eshcol overhead on gross pay', 0, 'pct', 140, 'Your own cost of running the account (recruiting, payroll, supervision).'),
 ('gst_pct', 'GST on the gross bill', 18, 'pct', 150, 'Manpower supply: 18 % on the full bill.');
;;

-- Tamil Nadu minimum wages, entered by Benjamin from the current notification: per kind of work and skill level.
CREATE TABLE min_wages (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_kind       VARCHAR(20) NOT NULL,
  skill_level     VARCHAR(20) NOT NULL,
  monthly         DECIMAL(10,2) NOT NULL,
  effective_from  DATE NOT NULL,
  note            VARCHAR(255) NULL,
  confirmed_by    INT UNSIGNED NULL,
  confirmed_at    DATETIME NULL,
  KEY ix_mw (work_kind, skill_level, effective_from),
  CONSTRAINT fk_mw_user FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;

-- The staffing offer's figures, as approved.
ALTER TABLE offer_approvals ADD COLUMN gross_month DECIMAL(10,2) NULL AFTER ctc_lpa,
  ADD COLUMN bill_month DECIMAL(10,2) NULL AFTER gross_month,
  ADD COLUMN cost_month DECIMAL(10,2) NULL AFTER bill_month,
  ADD COLUMN margin_month DECIMAL(10,2) NULL AFTER cost_month,
  ADD COLUMN margin_reason VARCHAR(255) NULL AFTER margin_month,
  ADD COLUMN calc JSON NULL AFTER margin_reason,
  ADD COLUMN client_terms_id INT UNSIGNED NULL AFTER calc;
;;

-- Joining checklist (own team and staffing): only that a document was received, and when. Never the numbers.
CREATE TABLE joining_items (
  application_id  INT UNSIGNED NOT NULL,
  item            VARCHAR(30) NOT NULL,
  received_on     DATE NOT NULL,
  user_id         INT UNSIGNED NULL,
  PRIMARY KEY (application_id, item),
  CONSTRAINT fk_ji_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_ji_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;

-- Consent for a second purpose: employment with Eshcol (DPDP), recorded when they accept an Eshcol offer.
ALTER TABLE consents MODIFY purpose ENUM('future_roles','employment') NOT NULL DEFAULT 'future_roles';
;;

INSERT INTO settings (k, v) VALUES ('staffing_min_margin_pct', '8') ON DUPLICATE KEY UPDATE v = v;
