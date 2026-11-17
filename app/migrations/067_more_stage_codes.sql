-- Audit Q1 follow-up (17 Nov 2026): fixed codes for the stages the remaining rules use, so renaming them in Settings
-- changes nothing: "Under review" (was "Screening"), "No response from candidate", "Awaiting candidate's reply".
-- Only stages without a code are touched; the names are the ones the app created (057, 058) or the original one.
UPDATE stages SET code = 'review' WHERE code IS NULL AND kind = 'active' AND name IN ('Under review', 'Screening')
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM stages WHERE code = 'review') x);
;;
UPDATE stages SET code = 'no_response' WHERE code IS NULL AND kind = 'fail' AND name = 'No response from candidate'
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM stages WHERE code = 'no_response') x);
;;
UPDATE stages SET code = 'awaiting_reply' WHERE code IS NULL AND kind = 'active' AND name = 'Awaiting candidate''s reply'
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM stages WHERE code = 'awaiting_reply') x);
;;
