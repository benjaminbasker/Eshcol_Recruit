-- Tamil Nadu minimum wages as Tamil Nadu sets them (15 November 2026): a basic rate per scheduled employment, class of
-- employee and zone (fixed by notification), plus a dearness allowance per scheduled employment revised every 1 April.
-- Minimum wage per month = basic per month (a daily rate x 30, as the notification says) + DA per month. Benjamin's
-- own January 2026 working confirms the method: unskilled, Zone B: 255 x 30 = 7,650 + DA 7,708 = 15,358.
-- Rates are loaded from G.O.(2D) No. 67, Labour and Employment (J1), 13 Nov 2017 (Gazette 3 Jan 2018) and the
-- Commissioner of Labour's statement Z3/3459/2026 of 18 Feb 2026, all UNCONFIRMED: Benjamin compares and confirms each.
ALTER TABLE min_wages ADD COLUMN daily DECIMAL(10,2) NULL AFTER monthly;
;;
CREATE TABLE min_wage_da (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_kind       VARCHAR(20) NOT NULL,
  monthly         DECIMAL(10,2) NOT NULL,
  effective_from  DATE NOT NULL,
  index_points    DECIMAL(8,2) NULL,
  base_points     DECIMAL(8,2) NULL,
  rate_per_point  DECIMAL(8,2) NULL,
  note            VARCHAR(255) NULL,
  confirmed_by    INT UNSIGNED NULL,
  confirmed_at    DATETIME NULL,
  KEY ix_mwda (work_kind, effective_from),
  CONSTRAINT fk_mwda_user FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
-- Nothing staffs yet, so the old kinds of work (factory / warehouse / office) and skill levels are replaced.
DELETE FROM min_wages WHERE work_kind IN ('factory', 'warehouse', 'office');
;;
UPDATE roles SET work_kind = NULL, skill_level = NULL, wage_zone = NULL WHERE work_kind IN ('factory', 'warehouse', 'office');
;;
INSERT INTO min_wages (work_kind, skill_level, zone, monthly, daily, effective_from, note) VALUES
 ('gen_engineering', 'manager',      'A', 7828.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (1)'),
 ('gen_engineering', 'manager',      'B', 7641.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (1)'),
 ('gen_engineering', 'asst_manager', 'A', 7660.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (2)'),
 ('gen_engineering', 'asst_manager', 'B', 7483.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (2)'),
 ('gen_engineering', 'accountant',   'A', 7660.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (3)'),
 ('gen_engineering', 'accountant',   'B', 7483.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (3)'),
 ('gen_engineering', 'cashier',      'A', 7543.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (4)-(6)'),
 ('gen_engineering', 'cashier',      'B', 7375.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (4)-(6)'),
 ('gen_engineering', 'clerk',        'A', 7443.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (7)-(8)'),
 ('gen_engineering', 'clerk',        'B', 7287.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (7)-(8)'),
 ('gen_engineering', 'attender',     'A', 7267.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (9)-(10)'),
 ('gen_engineering', 'attender',     'B', 7120.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (9)-(10)'),
 ('gen_engineering', 'watchman',     'A', 6951.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (11)-(12)'),
 ('gen_engineering', 'watchman',     'B', 6825.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Administration (11)-(12)'),
 ('gen_engineering', 'foreman',      'A', 7828.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Supervisory (1)'),
 ('gen_engineering', 'foreman',      'B', 7641.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Supervisory (1)'),
 ('gen_engineering', 'supervisor',   'A', 7660.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Supervisory (2)-(3)'),
 ('gen_engineering', 'supervisor',   'B', 7483.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Supervisory (2)-(3)'),
 ('gen_engineering', 'tool_keeper',  'A', 7267.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Supervisory (4)'),
 ('gen_engineering', 'tool_keeper',  'B', 7120.00, NULL, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Supervisory (4)'),
 ('gen_engineering', 'skilled',      'A', 8355.00, 278.50, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Skilled, daily x 30'),
 ('gen_engineering', 'skilled',      'B', 8205.00, 273.50, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Skilled, daily x 30'),
 ('gen_engineering', 'semi_1',       'A', 8130.00, 271.00, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Semi-skilled (i) Grade I, daily x 30'),
 ('gen_engineering', 'semi_1',       'B', 7950.00, 265.00, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Semi-skilled (i) Grade I, daily x 30'),
 ('gen_engineering', 'semi_2',       'A', 7980.00, 266.00, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Semi-skilled (ii) Grade II, daily x 30'),
 ('gen_engineering', 'semi_2',       'B', 7860.00, 262.00, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Semi-skilled (ii) Grade II, daily x 30'),
 ('gen_engineering', 'unskilled',    'A', 7755.00, 258.50, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Unskilled, daily x 30'),
 ('gen_engineering', 'unskilled',    'B', 7650.00, 255.00, '2018-01-03', 'G.O.(2D) No. 67, 13 Nov 2017: Unskilled, daily x 30');
;;
INSERT INTO min_wage_da (work_kind, monthly, effective_from, index_points, base_points, rate_per_point, note) VALUES
 ('gen_engineering', 7750.00, '2026-04-01', 344, 161, 42.35, 'Commissioner of Labour Z3/3459/2026, 18 Feb 2026, serial 28: (344 - 161) x 42.35');
