-- Eshcol Recruit (28 Nov 2026): what WABridge sends back to us on the webhook (replies, button taps, delivery
-- reports). Every call is kept as it came, matched to a candidate by mobile number where possible, and shown on
-- Settings › WhatsApp to candidates. A reply of STOP turns WhatsApp off for that candidate. Kept for a year.
-- Statements are separated by a line with ;;

CREATE TABLE whatsapp_inbox (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  received_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kind          VARCHAR(20) NOT NULL DEFAULT 'unknown',
  from_number   VARCHAR(15) NULL,
  candidate_id  INT UNSIGNED NULL,
  body          VARCHAR(1000) NULL,
  message_id    VARCHAR(120) NULL,
  payload       MEDIUMTEXT NULL,
  KEY ix_wi_cand (candidate_id),
  KEY ix_wi_when (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
