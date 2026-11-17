-- 17 Nov 2026 (Benjamin): a position can take candidates who live within a set distance of the client's office, not
-- only the job's city ("freshers from Tamil Nadu districts will move to Chennai for engineering roles").
ALTER TABLE roles MODIFY location_scope ENUM('local','open','remote','radius') NULL,
  ADD COLUMN location_radius_km SMALLINT UNSIGNED NULL AFTER location_scope;
;;
