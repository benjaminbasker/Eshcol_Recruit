-- 029: why a candidate was not taken forward on a role (Benjamin, 8 Nov 2026).
-- When an application moves to a closing stage (Not suitable, Withdrew), you record the reason,
-- e.g. "Qualification below the requirement: BBM and MBA; minimum is B.Com". It shows on the role,
-- the candidate and the responses page, and is kept in the stage history for future reference.

ALTER TABLE applications ADD COLUMN stage_reason VARCHAR(255) NULL AFTER stage_id;
;;
ALTER TABLE applications ADD COLUMN stage_reason_at DATETIME NULL AFTER stage_reason;
