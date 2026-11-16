-- Keep candidate data for 60 months from last contact (Benjamin's decision, 4 Nov 2026),
-- and let experience grow with time from the date it was last known.

INSERT INTO settings (k, v) VALUES ('retention_months', '60') ON DUPLICATE KEY UPDATE v = '60';
;;
UPDATE candidates
   SET purge_after = DATE_ADD(DATE(COALESCE(last_activity_at, created_at)), INTERVAL 60 MONTH)
 WHERE retention_note IS NULL;
;;
-- The date the experience figure was true. Today's experience = that figure plus
-- the time since, on the assumption the candidate is still working.
ALTER TABLE candidates ADD COLUMN exp_as_of DATE NULL AFTER exp_months;
;;
UPDATE candidates SET exp_as_of = DATE(COALESCE(last_confirmed_at, created_at)) WHERE exp_months IS NOT NULL;
