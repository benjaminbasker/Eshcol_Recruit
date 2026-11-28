-- Eshcol Recruit (28 Nov 2026): what Recruit reads back from the HRMS (HRMS framework section 6, D7).
-- Once a day (or when Benjamin presses Fetch), Recruit fetches two signed, read-only feeds from the HRMS with the same
-- link key: the statutory rates in force, and the people who are leaving or have left. Recruit never changes its own
-- rates on its own: Settings › Staffing shows the HRMS's figure beside each one, and Benjamin chooses to take it.
-- Leavers show on their candidate page (worked at Eshcol, left on, would rehire) and on Joinings, where a replacement
-- can be noted. Statements are separated by a line with ;;

CREATE TABLE hrms_rates (
  k              VARCHAR(40) NOT NULL PRIMARY KEY,
  label          VARCHAR(200) NOT NULL,
  value          DECIMAL(14,4) NOT NULL,
  unit           VARCHAR(20) NOT NULL,
  effective_from DATE NULL,
  confirmed      TINYINT(1) NOT NULL DEFAULT 0,
  fetched_at     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;

CREATE TABLE hrms_exits (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id      INT UNSIGNED NULL,
  application_id    INT UNSIGNED NULL,
  emp_no            VARCHAR(20) NOT NULL,
  client_id         INT UNSIGNED NULL,
  designation       VARCHAR(160) NULL,
  joined_on         DATE NULL,
  last_day          DATE NOT NULL,
  reason            VARCHAR(80) NULL,
  has_left          TINYINT(1) NOT NULL DEFAULT 0,
  rehire_ok         TINYINT(1) NULL,
  first_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  handled_at        DATETIME NULL,
  handled_note      VARCHAR(255) NULL,
  UNIQUE KEY uq_he_emp (emp_no),
  KEY ix_he_cand (candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;

INSERT INTO settings (k, v) VALUES ('hrms_url', 'https://hrms.eshcolglobal.com') ON DUPLICATE KEY UPDATE v = v;
