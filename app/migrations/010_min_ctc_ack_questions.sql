-- 010: the lowest salary a candidate would accept, and screening questions inside the thank-you email.
--
-- Candidates often state a high expected CTC and settle for less. The questionnaire now also asks
-- for the lowest they would accept; the score, the budget flag and the search use that when known.
-- The thank-you email can carry the questionnaire button, and a follow-up goes after 24 hours.

ALTER TABLE candidates ADD COLUMN ctc_min_lpa DECIMAL(6,2) NULL AFTER ctc_expected_lpa;
;;
INSERT INTO settings (k, v) VALUES
  ('ack_with_questions', '1'),
  ('questionnaire_reminder_hours', '24')
ON DUPLICATE KEY UPDATE v = v;
