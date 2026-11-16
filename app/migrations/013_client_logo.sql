-- A client's logo, shown in the app and on the pages the client and candidates open.
ALTER TABLE clients
  ADD COLUMN logo_path VARCHAR(255) NULL AFTER website,
  ADD COLUMN logo_updated_at DATETIME NULL AFTER logo_path;
