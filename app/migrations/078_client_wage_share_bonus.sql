-- Per-client staffing terms (24 Nov 2026, Benjamin, for HINE):
-- 1. wage_share_pct: this client's own share of gross that counts as wages (basic) for PF, bonus and gratuity.
--    Empty = the share in Settings › Staffing (50 %). HINE pays a 70 % basic, better than the prescribed share.
-- 2. bonus_in_gross: the statutory bonus is paid monthly within the gross (HINE: 3 % of gross), so no bonus
--    provision is added on top of it.
ALTER TABLE client_terms ADD COLUMN wage_share_pct DECIMAL(5,2) NULL AFTER wage_rule,
  ADD COLUMN bonus_in_gross TINYINT(1) NOT NULL DEFAULT 0 AFTER wage_share_pct;
;;
