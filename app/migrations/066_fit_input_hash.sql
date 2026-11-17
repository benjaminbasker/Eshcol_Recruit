-- Audit C2 follow-up (17 Nov 2026): a fingerprint of exactly what an assessment sent to Claude (instructions, the role
-- block, the CV text as sent, the job list, the answer format). A batch result is accepted only when today's inputs
-- give the same fingerprint, so an edited CV text or role (even with the same CV file) sends it back to the queue.
ALTER TABLE fit_assessments ADD COLUMN input_hash CHAR(64) NULL AFTER sent_cv_id;
;;
