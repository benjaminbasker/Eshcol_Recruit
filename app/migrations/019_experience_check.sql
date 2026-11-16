-- Experience, cross-checked: what the CV states, what its job dates add up to, and when
-- the CV was written (so "3 years" in a 2023 CV grows from 2023, not from the upload date).
ALTER TABLE candidates
  ADD COLUMN exp_stated_months SMALLINT UNSIGNED NULL AFTER exp_as_of,
  ADD COLUMN exp_dates_months SMALLINT UNSIGNED NULL AFTER exp_stated_months,
  ADD COLUMN cv_dated DATE NULL AFTER exp_dates_months,
  ADD COLUMN cv_has_current_job TINYINT(1) NULL AFTER cv_dated;
