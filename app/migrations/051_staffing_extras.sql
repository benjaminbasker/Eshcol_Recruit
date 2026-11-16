-- The last items of the own-hiring and staffing plan v2 (15 November 2026):
-- 1. a staffing position may override its client's fee (a different % or fixed fee for this job);
-- 2. the national floor wage, checked alongside the Tamil Nadu minimum wage;
-- 3. Tamil Nadu minimum wages by zone (a row with no zone applies everywhere).
ALTER TABLE roles ADD COLUMN terms_model ENUM('markup','fixed_fee','rate') NULL AFTER bill_rate_month,
  ADD COLUMN terms_markup_pct DECIMAL(6,2) NULL AFTER terms_model,
  ADD COLUMN terms_fixed_fee DECIMAL(10,2) NULL AFTER terms_markup_pct,
  ADD COLUMN wage_zone VARCHAR(40) NULL AFTER skill_level;
;;
ALTER TABLE min_wages ADD COLUMN zone VARCHAR(40) NULL AFTER skill_level;
;;
INSERT INTO statutory_rates (k, label, value, unit, sort, note) VALUES
 ('floor_wage_month', 'National floor wage per month (Code on Wages)', 0, 'inr_month', 15,
  'No state minimum wage may be below it. Enter the notified monthly amount; if none is notified yet, confirm 0.')
ON DUPLICATE KEY UPDATE k = k;
;;
ALTER TABLE offer_approvals ADD COLUMN terms_used JSON NULL AFTER client_terms_id;
