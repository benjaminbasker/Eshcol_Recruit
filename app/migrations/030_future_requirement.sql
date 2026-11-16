-- 030: a "Future requirement" stage (Benjamin, 8 Nov 2026).
-- A new kind of stage, 'hold' ("kept for later"): the candidate is good but not for this role now
-- (over budget, timing, a role that may come). Out of the active pipeline and not assessed again for
-- this role, but not a rejection: shown in gold, with a short remark, and findable later.

ALTER TABLE stages MODIFY kind ENUM('active','success','fail','hold') NOT NULL DEFAULT 'active';
;;
INSERT INTO stages (name, sort, kind, is_active) VALUES ('Future requirement', 85, 'hold', 1);
