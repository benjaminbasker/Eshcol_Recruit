-- 007: experience allowance per role, and talent pools by position.
--
-- Allowance: each role takes candidates from (exp_min - allow_below) to (exp_max + allow_above)
-- years. Defaults 1 below and 5 above, changeable per role and as a default in Settings.
--
-- Talent pools: internal "positions" (e.g. HR Executive) that are not client roles. A CV from a
-- different field (an HR CV sent for an accounts role), or one that fits no open role, is filed
-- in the pool named after its own current position. Pools never count as open roles.

ALTER TABLE roles
  ADD COLUMN exp_allow_below DECIMAL(3,1) NOT NULL DEFAULT 1.0 AFTER exp_max,
  ADD COLUMN exp_allow_above DECIMAL(3,1) NOT NULL DEFAULT 5.0 AFTER exp_allow_below,
  ADD COLUMN is_pool TINYINT(1) NOT NULL DEFAULT 0 AFTER accepts_routed,
  ADD KEY ix_roles_pool (is_pool, title);
;;

INSERT INTO settings (k, v) VALUES
  ('exp_allow_below_default', '1'),
  ('exp_allow_above_default', '5'),
  ('talent_pools_enabled', '1')
ON DUPLICATE KEY UPDATE v = v;
