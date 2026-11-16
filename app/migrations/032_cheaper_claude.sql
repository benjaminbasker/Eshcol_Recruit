-- Eshcol Recruit: cheaper Claude (8 Nov 2026).
-- 1. Assessments go to Claude in half-price batches (status 'batched' while Claude works on them).
-- 2. One assessment per candidate per client: the best-matching role is assessed, the others are
--    'skipped' (skipped_for = the role assessed instead) until the owner asks for them too.
-- 3. CVs that fit no open role are read by Claude Haiku (ai_read_cheap).
ALTER TABLE fit_assessments MODIFY status ENUM('pending','reading','batched','done','failed','skipped') NOT NULL DEFAULT 'pending';
;;
ALTER TABLE fit_assessments ADD COLUMN batch_id VARCHAR(80) NULL AFTER status, ADD COLUMN skipped_for INT UNSIGNED NULL AFTER batch_id, ADD KEY ix_fit_batch (batch_id);
;;
INSERT IGNORE INTO settings (k, v) VALUES ('fit_batch', '1');
;;
INSERT IGNORE INTO settings (k, v) VALUES ('ai_read_cheap', '1');
