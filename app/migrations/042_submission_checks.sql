-- Checks before a shortlist goes to a client (B11, 14 November 2026).
-- Permission to put the candidate forward (from the details form, or recorded after a call), the exact CV
-- version each shortlist sent, the reason you gave when sending despite a warning, and when you chased the client.
ALTER TABLE applications
  ADD COLUMN permission ENUM('yes','not_yet') NULL,
  ADD COLUMN permission_at DATETIME NULL,
  ADD COLUMN permission_via VARCHAR(20) NULL;
;;
ALTER TABLE shortlist_items
  ADD COLUMN cv_file_id INT UNSIGNED NULL,
  ADD COLUMN check_note VARCHAR(600) NULL;
;;
ALTER TABLE shortlists ADD COLUMN chased_at DATETIME NULL;
