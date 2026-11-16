-- 024: asking every candidate for their details straight away (Benjamin, 7 Nov 2026).
--
-- - Every thank-you email carries "Complete your application": one personal link, even when
--   the CV came with no role (a general request, no application) or for several roles at once
--   (one lead row with the link; the other roles hang off it through parent_id).
-- - A gentle reminder 48 hours after the email, a final "Are you still interested?" 48 hours
--   after that, and a one-click "I'm no longer interested" (status 'declined').
-- - A new email or mobile given by the candidate waits for Benjamin's approval (pending_contact).
-- - Work mode on every role (On-site, Hybrid, Remote), shown next to the title to candidates.

ALTER TABLE questionnaires MODIFY application_id INT UNSIGNED NULL;
;;
ALTER TABLE questionnaires MODIFY role_id INT UNSIGNED NULL;
;;
ALTER TABLE questionnaires MODIFY status ENUM('sent','opened','answered','expired','cancelled','declined') NOT NULL DEFAULT 'sent';
;;
ALTER TABLE questionnaires ADD COLUMN parent_id INT UNSIGNED NULL AFTER role_id;
;;
ALTER TABLE questionnaires ADD COLUMN final_at DATETIME NULL AFTER reminded_at;
;;
ALTER TABLE questionnaires ADD COLUMN declined_at DATETIME NULL AFTER answered_at;
;;
ALTER TABLE questionnaires ADD KEY ix_q_parent (parent_id);
;;
ALTER TABLE questionnaires ADD KEY ix_q_cand (candidate_id, status);
;;
ALTER TABLE candidates ADD COLUMN pending_contact JSON NULL AFTER last_confirmed_at;
;;
ALTER TABLE roles ADD COLUMN work_mode ENUM('onsite','hybrid','remote') NULL AFTER location;
;;
UPDATE settings SET v = '48' WHERE k = 'questionnaire_reminder_hours' AND v = '24';
;;
INSERT INTO settings (k, v) VALUES
  ('questionnaire_reminder_hours', '48'),
  ('questionnaire_final_hours', '48'),
  ('ack_with_questions', '1')
ON DUPLICATE KEY UPDATE v = v;
