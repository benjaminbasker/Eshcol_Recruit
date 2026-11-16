-- Eshcol Recruit: the client portal (Benjamin, 13 Nov 2026).
-- A client's contact signs in with a link sent to their email (no password to keep), and sees all
-- their positions and every candidate sent to them; marks each Shortlist / Hold / Not suitable with a
-- comment; offers interview times as a window with a chosen interview length (5–6 pm in 20-minute
-- interviews gives candidates three times to pick from); and asks for new positions, which arrive
-- as drafts. Access is given per contact, by the owner, and can be taken away at any time.

ALTER TABLE client_contacts
  ADD COLUMN portal_access TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN portal_invited_at DATETIME NULL,
  ADD COLUMN portal_last_login_at DATETIME NULL,
  ADD KEY ix_contact_email (email);
;;
-- One-time sign-in links (the invitation lasts 7 days, a requested link 30 minutes). Only the hash is kept.
CREATE TABLE client_logins (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id  INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  purpose     ENUM('invite','signin') NOT NULL DEFAULT 'signin',
  ip          VARCHAR(45) NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cl_token (token_hash),
  KEY ix_cl_contact (contact_id, created_at),
  CONSTRAINT fk_cl_contact FOREIGN KEY (contact_id) REFERENCES client_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
-- A signed-in browser: 7 days, then a new link.
CREATE TABLE client_sessions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id    INT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  label         VARCHAR(190) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME NULL,
  expires_at    DATETIME NOT NULL,
  UNIQUE KEY uq_cs_token (token_hash),
  KEY ix_cs_contact (contact_id),
  CONSTRAINT fk_cs_contact FOREIGN KEY (contact_id) REFERENCES client_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
-- A position the client asked for in the portal arrives as a draft, marked with who asked and when.
ALTER TABLE roles
  ADD COLUMN requested_by_contact_id INT UNSIGNED NULL,
  ADD COLUMN requested_at DATETIME NULL,
  ADD COLUMN request_note TEXT NULL;
;;
-- Which contact made a decision in the portal (decisions from a shortlist link have none).
ALTER TABLE shortlist_items
  ADD COLUMN decided_by_contact_id INT UNSIGNED NULL;
