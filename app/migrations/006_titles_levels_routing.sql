-- Job titles, experience levels, and routing CVs to the role their experience fits
-- (Benjamin's request, 4 Nov 2026).

-- The same word can be both a skill and a job title ("recruiter", "document controller"),
-- so a term is unique within its category, not across the whole dictionary.
ALTER TABLE skills_dictionary DROP INDEX uq_term, ADD UNIQUE KEY uq_term_cat (term, category);
;;
INSERT IGNORE INTO skills_dictionary (term, canonical, category) VALUES
-- Accounts and finance
('accounts executive','Accounts Executive','designation'),('executive accounts','Accounts Executive','designation'),('executive - accounts','Accounts Executive','designation'),('accounts assistant','Accounts Executive','designation'),('junior accountant','Accounts Executive','designation'),
('accounts officer','Accounts Officer','designation'),
('accountant','Accountant','designation'),
('senior accountant','Senior Accountant','designation'),('sr. accountant','Senior Accountant','designation'),('sr accountant','Senior Accountant','designation'),
('senior accounts executive','Senior Accounts Executive','designation'),('sr. accounts executive','Senior Accounts Executive','designation'),
('accounts manager','Accounts Manager','designation'),('manager - accounts','Accounts Manager','designation'),('manager accounts','Accounts Manager','designation'),('accounting manager','Accounts Manager','designation'),
('finance manager','Finance Manager','designation'),('manager - finance','Finance Manager','designation'),('finance & accounts manager','Finance Manager','designation'),
('financial controller','Financial Controller','designation'),('finance controller','Financial Controller','designation'),
('chief financial officer','CFO','designation'),('cfo','CFO','designation'),
('article assistant','Article Assistant','designation'),('articled assistant','Article Assistant','designation'),('audit assistant','Article Assistant','designation'),
('payroll executive','Payroll Executive','designation'),('payroll officer','Payroll Executive','designation'),
-- HR and recruitment
('hr executive','HR Executive','designation'),('human resources executive','HR Executive','designation'),('hr assistant','HR Executive','designation'),
('hr generalist','HR Generalist','designation'),
('hr manager','HR Manager','designation'),('human resources manager','HR Manager','designation'),('manager - hr','HR Manager','designation'),
('hr business partner','HR Business Partner','designation'),('hrbp','HR Business Partner','designation'),
('recruiter','Recruiter','designation'),('recruitment executive','Recruiter','designation'),('recruitment consultant','Recruiter','designation'),('talent acquisition executive','Recruiter','designation'),('talent acquisition specialist','Recruiter','designation'),('hr recruiter','Recruiter','designation'),
('talent acquisition manager','Talent Acquisition Manager','designation'),('recruitment manager','Talent Acquisition Manager','designation'),
-- Engineering, EPC, oil and gas
('piping designer','Piping Designer','designation'),('piping design engineer','Piping Designer','designation'),
('piping draftsman','Draftsman','designation'),('autocad draftsman','Draftsman','designation'),('cad draftsman','Draftsman','designation'),('draftsman','Draftsman','designation'),('draughtsman','Draftsman','designation'),
('instrumentation engineer','Instrumentation Engineer','designation'),('instrument engineer','Instrumentation Engineer','designation'),
('document controller','Document Controller','designation'),('document control engineer','Document Controller','designation'),
('procurement executive','Procurement Executive','designation'),('purchase executive','Procurement Executive','designation'),('purchase officer','Procurement Executive','designation'),('buyer','Procurement Executive','designation'),
('procurement manager','Procurement Manager','designation'),('purchase manager','Procurement Manager','designation'),
('project engineer','Project Engineer','designation'),('site engineer','Site Engineer','designation'),('planning engineer','Planning Engineer','designation'),
('mechanical engineer','Mechanical Engineer','designation'),('electrical engineer','Electrical Engineer','designation'),('civil engineer','Civil Engineer','designation'),
('qa/qc engineer','QA/QC Engineer','designation'),('qc engineer','QA/QC Engineer','designation'),('quality engineer','QA/QC Engineer','designation'),
('project manager','Project Manager','designation'),
-- General business
('admin executive','Admin Executive','designation'),('administration executive','Admin Executive','designation'),('office administrator','Admin Executive','designation'),
('office manager','Office Manager','designation'),
('sales executive','Sales Executive','designation'),('sales manager','Sales Manager','designation'),
('business development executive','Business Development Executive','designation'),('bde','Business Development Executive','designation'),
('business development manager','Business Development Manager','designation'),('bdm','Business Development Manager','designation'),
('customer service executive','Customer Service Executive','designation'),('customer support executive','Customer Service Executive','designation'),
('data entry operator','Data Entry Operator','designation');
;;
-- Every job title found in a candidate's CV, for searching by position.
CREATE TABLE candidate_titles (
  candidate_id  INT UNSIGNED NOT NULL,
  title         VARCHAR(100) NOT NULL,
  PRIMARY KEY (candidate_id, title),
  KEY ix_title (title),
  CONSTRAINT fk_ctitles_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB;
;;
-- Routing: a role can accept CVs sent for other roles when the experience fits.
ALTER TABLE roles ADD COLUMN accepts_routed TINYINT(1) NOT NULL DEFAULT 1 AFTER score_threshold;
;;
ALTER TABLE applications
  ADD COLUMN routed_from_role_id INT UNSIGNED NULL AFTER source,
  ADD COLUMN fit_note VARCHAR(255) NULL AFTER routed_from_role_id;
;;
INSERT INTO settings (k, v) VALUES
  ('routing_enabled', '1'),
  ('auto_sort_unassigned', '1'),
  ('level_bands', '[["Fresher",0,12],["Junior",12,36],["Mid-level",36,84],["Senior",84,144],["Leadership",144,9999]]')
ON DUPLICATE KEY UPDATE v = v;
