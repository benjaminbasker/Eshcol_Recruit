-- Which CV goes to clients when someone has sent us several (audit B13, 15 November 2026). Empty: the newest one,
-- as before. A shortlist keeps the exact CV it was made with (shortlist_items.cv_file_id).
ALTER TABLE candidates ADD COLUMN preferred_cv_id INT UNSIGNED NULL,
  ADD CONSTRAINT fk_cand_pref_cv FOREIGN KEY (preferred_cv_id) REFERENCES cv_files(id) ON DELETE SET NULL;
