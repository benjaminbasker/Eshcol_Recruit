-- Staffing costing by who pays and when (16 November 2026):
-- 1. the wages rule per client: the law (wages at least the minimum wage, the default) or common practice (wages are the
--    share of gross; only the gross must meet the minimum wage);
-- 2. what the fee % is worked out on, per client: gross pay only, or gross pay plus all employer costs;
-- 3. who pays each employer cost (PF, ESI, insurance, bonus, leave, LWF, gratuity): in the monthly bill, reimbursed
--    against Eshcol's proof of payment, or Eshcol bears it; and how soon a reimbursement is paid.
-- Terms saved before this keep working as they did: fee % on the whole cost, everything in the monthly bill.
ALTER TABLE client_terms ADD COLUMN wage_rule ENUM('law','gross') NOT NULL DEFAULT 'law' AFTER gst_pct,
  ADD COLUMN markup_base ENUM('gross','cost') NOT NULL DEFAULT 'gross' AFTER markup_pct,
  ADD COLUMN payers JSON NULL AFTER wage_rule,
  ADD COLUMN reimburse_days TINYINT UNSIGNED NULL AFTER payment_due_days;
;;
UPDATE client_terms SET markup_base = 'cost';
;;
UPDATE statutory_rates SET note = 'Code on Wages: allowances above half of pay count as wages. Where a client''s terms follow the law, wages are never below the minimum wage.' WHERE k = 'wage_share_pct';
;;
UPDATE statutory_rates SET note = 'Tamil Nadu: ₹40 a year (the employee''s ₹20 comes off their pay). Paid by 31 January.' WHERE k = 'lwf_employer_year';
