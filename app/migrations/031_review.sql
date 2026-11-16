-- 031: reviewing completed applications (Benjamin, 8 Nov 2026).
-- An answered details request waits on "My day" until you act on it (approve, future requirement,
-- not suitable, or any stage change), instead of disappearing after three days whatever you did.

ALTER TABLE questionnaires ADD COLUMN reviewed_at DATETIME NULL AFTER declined_at;
;;
ALTER TABLE questionnaires ADD COLUMN reviewed_by INT UNSIGNED NULL AFTER reviewed_at;
;;
-- Already acted on: anyone answered whose application is no longer in play counts as reviewed.
UPDATE questionnaires q
  JOIN applications a ON a.id = q.application_id
  JOIN stages s ON s.id = a.stage_id
SET q.reviewed_at = NOW()
WHERE q.status = 'answered' AND q.reviewed_at IS NULL AND s.kind IN ('fail', 'hold', 'success');
