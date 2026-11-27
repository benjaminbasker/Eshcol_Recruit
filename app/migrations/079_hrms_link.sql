-- Eshcol Recruit (25 Nov 2026): the link to Eshcol HRMS (HRMS framework section 6.3). The HRMS fetches joining
-- records over a signed server-to-server link (/hooks/hrms/...) and tells Recruit when it has imported one; Recruit
-- then shows "In the HRMS", with the employee number, and freezes the record. The link key is typed in on Joinings.
ALTER TABLE joinings ADD COLUMN imported_at DATETIME NULL AFTER downloaded_at,
  ADD COLUMN hrms_employee_no VARCHAR(20) NULL AFTER imported_at;
