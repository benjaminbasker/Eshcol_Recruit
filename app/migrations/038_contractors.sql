-- Eshcol Recruit: contractors (B6, 14 Nov 2026). A contractor signs in like anyone else, but sees only
-- the positions the owner assigns them, and the candidates on those positions: never the database,
-- clients, other positions, Settings, emails or messages.
ALTER TABLE users MODIFY role ENUM('owner','recruiter','contractor') NOT NULL DEFAULT 'recruiter';
;;
CREATE TABLE role_access (
  user_id     INT UNSIGNED NOT NULL,
  role_id     INT UNSIGNED NOT NULL,
  granted_by  INT UNSIGNED NULL,
  granted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  KEY ix_ra_role (role_id),
  CONSTRAINT fk_ra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ra_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
