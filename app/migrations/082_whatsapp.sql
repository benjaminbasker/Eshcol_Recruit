-- Eshcol Recruit (28 Nov 2026): WhatsApp to candidates through WABridge on +91 81228 12340, alongside the emails:
-- the application thank-you, the interview fixed or moved, and the interview-day reminder. Only Meta-approved
-- templates are sent; nothing goes out until Benjamin enters the WABridge keys and switches sending on (and never
-- from his PC). Anyone marked do not contact, asking to be removed, or who turned WhatsApp off gets nothing.
-- Replies come to the WhatsApp Business app on his phone. Statements are separated by a line with ;;

CREATE TABLE whatsapp_templates (
  name         VARCHAR(80) NOT NULL,
  language     VARCHAR(10) NOT NULL,
  template_id  VARCHAR(40) NOT NULL,
  status       VARCHAR(20) NOT NULL,
  category     VARCHAR(20) NULL,
  body         TEXT NULL,
  synced_at    DATETIME NOT NULL,
  PRIMARY KEY (name, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;

CREATE TABLE whatsapp_queue (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id  INT UNSIGNED NULL,
  to_number     VARCHAR(15) NOT NULL,
  template      VARCHAR(80) NOT NULL,
  language      VARCHAR(10) NOT NULL DEFAULT 'en',
  variables     JSON NULL,
  button_vars   JSON NULL,
  related_type  VARCHAR(30) NULL,
  related_id    INT UNSIGNED NULL,
  status        ENUM('queued','sent','failed','outbox','held','cancelled') NOT NULL DEFAULT 'queued',
  not_before    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  message_id    VARCHAR(80) NULL,
  dlr_status    VARCHAR(20) NULL,
  last_error    VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at       DATETIME NULL,
  dlr_at        DATETIME NULL,
  KEY ix_wq (status, not_before),
  KEY ix_wq_cand (candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;

ALTER TABLE candidates ADD COLUMN whatsapp_off TINYINT(1) NOT NULL DEFAULT 0;
;;

INSERT INTO settings (k, v) VALUES ('wa_live', '0'), ('wa_device_id', '') ON DUPLICATE KEY UPDATE v = v;
