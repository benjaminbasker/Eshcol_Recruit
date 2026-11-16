-- The joining record (own-hiring and staffing plan v2, phase 3, 15 November 2026): when someone joins Eshcol's payroll
-- (our own team, or staffing), Recruit writes down everything the HRMS needs on their first day, so nothing is retyped.
-- Until the HRMS exists it is downloaded; later the HRMS imports it. payload follows the "eshcol.joining" format
-- described in Eshcol_HRMS_Joining_Handoff_Spec_15Nov2026.md.
CREATE TABLE joinings (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id   INT UNSIGNED NULL,
  candidate_id     INT UNSIGNED NULL,
  full_name        VARCHAR(160) NOT NULL,
  engagement       ENUM('inhouse','staffing') NOT NULL,
  joining_date     DATE NULL,
  status           ENUM('ready','downloaded','imported','withdrawn') NOT NULL DEFAULT 'ready',
  format_version   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  payload          JSON NOT NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  downloaded_at    DATETIME NULL,
  UNIQUE KEY uq_joining_app (application_id),
  KEY ix_joining_status (status, joining_date),
  CONSTRAINT fk_joining_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
  CONSTRAINT fk_joining_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE SET NULL,
  CONSTRAINT fk_joining_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
