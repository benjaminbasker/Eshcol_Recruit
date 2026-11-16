-- 026: the candidate's LinkedIn profile (Benjamin, 8 Nov 2026).
-- Read from the CV (also from a link behind the word "LinkedIn"), editable by you, and offered
-- to the candidate as an optional field on their details page. Existing CVs are filled in once,
-- for free, by the scheduled job (setting linkedin_backfilled).

ALTER TABLE candidates ADD COLUMN linkedin_url VARCHAR(255) NULL AFTER phone_norm;
