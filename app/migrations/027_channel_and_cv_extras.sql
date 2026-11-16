-- 027 (Benjamin, 8 Nov 2026):
-- 1. Where each candidate came from (Naukri, LinkedIn, Indeed, the website, a referral...):
--    on the candidate (first known source) and on each application (how they came to that role).
--    Set by the upload page, worked out from resume@ emails and Naukri file names, editable by you.
-- 2. More from the CV, read by Claude in the same reading: education (kept in the reading itself),
--    software, languages, serving notice and last working day, home PIN code, type of employer.

ALTER TABLE candidates ADD COLUMN channel VARCHAR(20) NULL AFTER source;
;;
ALTER TABLE applications ADD COLUMN channel VARCHAR(20) NULL AFTER source;
;;
ALTER TABLE upload_batches ADD COLUMN channel VARCHAR(20) NULL AFTER role_id;
;;
ALTER TABLE candidates ADD KEY ix_cand_channel (channel);
;;
ALTER TABLE candidates ADD COLUMN software VARCHAR(500) NULL AFTER qualifications;
;;
ALTER TABLE candidates ADD COLUMN languages VARCHAR(255) NULL AFTER software;
;;
ALTER TABLE candidates ADD COLUMN employer_type VARCHAR(40) NULL AFTER languages;
;;
ALTER TABLE candidates ADD COLUMN pin_code CHAR(6) NULL AFTER locality;
;;
ALTER TABLE candidates ADD COLUMN serving_notice TINYINT(1) NULL AFTER notice_days;
;;
ALTER TABLE candidates ADD COLUMN last_working_day DATE NULL AFTER serving_notice;
