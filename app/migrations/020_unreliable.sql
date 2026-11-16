-- Eshcol Recruit: candidates marked "Unreliable" (did not turn up, stopped responding,
-- backed out). Kept out of database searches, shortlists, screening and interview invitations.
ALTER TABLE candidates
  ADD COLUMN unreliable_at     DATETIME NULL AFTER removal_requested_at,
  ADD COLUMN unreliable_reason VARCHAR(20) NULL AFTER unreliable_at,
  ADD COLUMN unreliable_note   VARCHAR(300) NULL AFTER unreliable_reason,
  ADD COLUMN unreliable_by     INT UNSIGNED NULL AFTER unreliable_note,
  ADD KEY ix_cand_unreliable (unreliable_at);
;;
ALTER TABLE interviews MODIFY outcome ENUM('next_round','selected','not_selected','on_hold','no_show') NULL;
