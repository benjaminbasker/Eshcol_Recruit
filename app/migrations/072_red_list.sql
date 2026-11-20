-- The red list (Benjamin, 18 Nov 2026): a hard rejection ("Rejected: insufficient information" - profile
-- won't help us at all, insufficient details, or attitude) is a verdict on the person, not the role. It
-- closes every other open application they have, with any client, and keeps them off every future one too.
ALTER TABLE candidates
  ADD COLUMN red_listed_at     DATETIME NULL AFTER unreliable_by,
  ADD COLUMN red_listed_reason VARCHAR(300) NULL AFTER red_listed_at,
  ADD COLUMN red_listed_by     INT UNSIGNED NULL AFTER red_listed_reason,
  ADD KEY ix_cand_red_listed (red_listed_at);
;;
UPDATE stages SET code = 'rejected' WHERE code IS NULL AND name = 'Rejected: insufficient information';
