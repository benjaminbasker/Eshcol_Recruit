-- 17 Nov 2026 (Palani K): the job a website applicant asked for, when it isn't a position in the app.
-- Read from the email subject ("Job application — Document Controller, EPC Projects (Chennai) — Name");
-- used to file them in that job's talent pool and to name the job in the thank-you email.
ALTER TABLE candidates ADD COLUMN applied_for VARCHAR(160) NULL AFTER current_title;
;;
