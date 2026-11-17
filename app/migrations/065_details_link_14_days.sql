-- 17 Nov 2026 (Benjamin): the details link stays open 14 days after the last email (the final reminder).
-- Links already past their final email are moved to that date; ones that closed earlier than that open again.
UPDATE questionnaires
   SET expires_at = GREATEST(expires_at, CONCAT(DATE(final_at) + INTERVAL 14 DAY, ' 23:59:59')),
       status = IF(status = 'expired' AND CONCAT(DATE(final_at) + INTERVAL 14 DAY, ' 23:59:59') > NOW(), IF(opened_at IS NULL, 'sent', 'opened'), status)
 WHERE final_at IS NOT NULL AND status IN ('sent', 'opened', 'expired');
;;
