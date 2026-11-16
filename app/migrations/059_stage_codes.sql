-- Audit Q1 (16 Nov 2026): a fixed hidden code for the stages the app's rules depend on, so renaming a stage in
-- Settings never switches off the offer approval, the HRMS joining record or the interview results.
ALTER TABLE stages ADD COLUMN code VARCHAR(30) NULL AFTER name, ADD UNIQUE KEY uq_stage_code (code);
;;
UPDATE stages SET code = CASE name
  WHEN 'Shortlisted' THEN 'shortlisted'
  WHEN 'Submitted to client' THEN 'submitted'
  WHEN 'Client interview' THEN 'interview'
  WHEN 'Offer made' THEN 'offer'
  WHEN 'Joined' THEN 'joined'
  WHEN 'Not suitable' THEN 'not_suitable'
  WHEN 'Withdrew' THEN 'withdrew'
  WHEN 'Future requirement' THEN 'future'
  END
WHERE code IS NULL AND name IN ('Shortlisted', 'Submitted to client', 'Client interview', 'Offer made', 'Joined', 'Not suitable', 'Withdrew', 'Future requirement');
;;
