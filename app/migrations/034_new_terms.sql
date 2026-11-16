-- Eshcol Recruit: the dictionary learns (Benjamin, 10 Nov 2026).
-- Job titles and software that Claude reads in CVs but the dictionary does not know yet are
-- collected here for Benjamin to add or ignore (Settings › New words). Claude's suggestions of other
-- wordings for a role's must-haves land here too, with the must-have as their canonical.
CREATE TABLE dictionary_suggestions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  term          VARCHAR(100) NOT NULL,
  label         VARCHAR(100) NOT NULL,
  category      VARCHAR(40) NOT NULL,
  canonical     VARCHAR(100) NULL,
  origin        ENUM('cv','role') NOT NULL DEFAULT 'cv',
  role_id       INT UNSIGNED NULL,
  seen          INT UNSIGNED NOT NULL DEFAULT 1,
  candidate_id  INT UNSIGNED NULL,
  status        ENUM('new','added','ignored') NOT NULL DEFAULT 'new',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL,
  UNIQUE KEY uq_sugg (term, category),
  KEY ix_sugg_status (status, origin, seen),
  KEY ix_sugg_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
-- Indian wordings O*NET does not have (it says "Drafter"), and SmartPlant tools.
INSERT IGNORE INTO skills_dictionary (term, canonical, category, area, source) VALUES
('mechanical draftsman', 'Draftsman', 'designation', 'Engineering, Projects & EPC', 'eshcol'),
('civil draftsman', 'Draftsman', 'designation', 'Engineering, Projects & EPC', 'eshcol'),
('electrical draftsman', 'Draftsman', 'designation', 'Engineering, Projects & EPC', 'eshcol'),
('piping draftsman', 'Draftsman', 'designation', 'Engineering, Projects & EPC', 'eshcol'),
('structural draftsman', 'Draftsman', 'designation', 'Engineering, Projects & EPC', 'eshcol'),
('autocad draftsman', 'Draftsman', 'designation', 'Engineering, Projects & EPC', 'eshcol'),
('mechanical draughtsman', 'Draftsman', 'designation', 'Engineering, Projects & EPC', 'eshcol'),
('civil draughtsman', 'Draftsman', 'designation', 'Engineering, Projects & EPC', 'eshcol'),
('senior draftsman', 'Draftsman', 'designation', 'Engineering, Projects & EPC', 'eshcol'),
('smartplant 3d', 'SP3D', 'skill', 'Oil & Gas / EPC Engineering', 'eshcol'),
('smart plant 3d', 'SP3D', 'skill', 'Oil & Gas / EPC Engineering', 'eshcol'),
('smartplant', 'SmartPlant', 'skill', 'Oil & Gas / EPC Engineering', 'eshcol'),
('smart plant', 'SmartPlant', 'skill', 'Oil & Gas / EPC Engineering', 'eshcol'),
('smartplant p&id', 'SmartPlant P&ID', 'skill', 'Oil & Gas / EPC Engineering', 'eshcol'),
('spp&id', 'SmartPlant P&ID', 'skill', 'Oil & Gas / EPC Engineering', 'eshcol'),
('smartplant instrumentation', 'SPI / INtools', 'skill', 'Oil & Gas / EPC Engineering', 'eshcol'),
('smartplant review', 'SmartPlant Review', 'skill', 'Oil & Gas / EPC Engineering', 'eshcol'),
('spr', 'SmartPlant Review', 'skill', 'Oil & Gas / EPC Engineering', 'eshcol')
