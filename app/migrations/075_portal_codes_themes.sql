-- 20 Nov 2026 (Benjamin): clients sign in to the portal with a 6-digit code emailed to them (instead of a link),
-- and stay signed in for 30 days; the owner can look at a client's portal as that client sees it (read-only);
-- and each user picks the app's colours for themselves.
ALTER TABLE client_logins
  MODIFY purpose ENUM('invite','signin','code') NOT NULL DEFAULT 'signin',
  ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;
;;
ALTER TABLE client_sessions ADD COLUMN preview_by INT UNSIGNED NULL;
;;
ALTER TABLE users ADD COLUMN theme VARCHAR(20) NULL;
