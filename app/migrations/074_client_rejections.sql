-- "Rejected by client" (Benjamin, 20 Nov 2026): when a client turns a candidate down, it is recorded on the
-- candidate and their CV is never sent to that client again (any of the client's positions, now or later).
-- Two ways a rejection is recorded: you move them to the "Rejected by client" stage, or the client marks them
-- "Not suitable" on a shortlist link. Other clients are not affected.
INSERT INTO stages (name, sort, kind, is_active, code)
SELECT 'Rejected by client', 81, 'fail', 1, 'client_rejected' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM stages WHERE code = 'client_rejected' OR name = 'Rejected by client');
;;
CREATE TABLE IF NOT EXISTS client_rejections (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id      INT UNSIGNED NOT NULL,
  client_id         INT UNSIGNED NOT NULL,
  role_id           INT UNSIGNED NULL,
  application_id    INT UNSIGNED NULL,
  source            ENUM('stage','portal') NOT NULL,
  shortlist_item_id INT UNSIGNED NULL,
  note              VARCHAR(300) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cr_cand_client (candidate_id, client_id),
  KEY ix_cr_app (application_id),
  UNIQUE KEY uq_cr_item (shortlist_item_id)
) ENGINE=InnoDB;
;;
-- The client's earlier "Not suitable" answers on shortlist links count too.
INSERT IGNORE INTO client_rejections (candidate_id, client_id, role_id, application_id, source, shortlist_item_id, note, created_at)
SELECT a.candidate_id, r.client_id, r.id, a.id, 'portal', si.id, LEFT(si.comment, 300), COALESCE(si.decided_at, NOW())
FROM shortlist_items si
JOIN applications a ON a.id = si.application_id
JOIN roles r ON r.id = a.role_id
WHERE si.decision = 'reject';
