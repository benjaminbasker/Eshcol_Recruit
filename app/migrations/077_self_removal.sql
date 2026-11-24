-- 24 Nov 2026 (Benjamin: "Yes, build it"). A candidate can delete their own details from the "Remove my details"
-- link. Once their record is gone, the link must still say so kindly rather than "this link isn't valid": this keeps
-- only a one-way hash of the link (no name, email or phone) and the day it was used.
CREATE TABLE IF NOT EXISTS removed_links (
  token_hash  CHAR(64) NOT NULL PRIMARY KEY,
  removed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
