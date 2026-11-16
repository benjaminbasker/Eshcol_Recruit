-- Audit C2 (16 Nov 2026): which CV an assessment batch was sent with, so a result that comes back after a newer CV
-- (or changed requirements) is queued again instead of being scored against the wrong inputs.
ALTER TABLE fit_assessments ADD COLUMN sent_cv_id INT UNSIGNED NULL;
;;
