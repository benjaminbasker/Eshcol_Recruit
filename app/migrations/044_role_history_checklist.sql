-- Position history and the new-position checklist (B13, B14: 14 November 2026).
-- Every change to a position (budget, experience, notice, location, requirements...) is kept with who and
-- when, so a score from last month can be explained. Two new position details for the checklist: how many
-- interview rounds, and who at the client decides.
CREATE TABLE role_revisions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id     INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  changes     JSON NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rr_role (role_id, id),
  CONSTRAINT fk_rr_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
ALTER TABLE roles
  ADD COLUMN interview_rounds TINYINT UNSIGNED NULL,
  ADD COLUMN decision_maker VARCHAR(160) NULL;
