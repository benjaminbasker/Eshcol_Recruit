-- "Trust this device for 7 days": skips the authenticator code (never the password)
-- on a browser where it was ticked. Only a hash of the cookie's token is stored.
CREATE TABLE trusted_devices (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  label         VARCHAR(190) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at  DATETIME NULL,
  expires_at    DATETIME NOT NULL,
  UNIQUE KEY uq_td_token (token_hash),
  KEY ix_td_user (user_id),
  CONSTRAINT fk_td_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
