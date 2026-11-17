-- 17 Nov 2026 (Benjamin): the client gets one email at 6 pm with the interviews booked that day, not one email per
-- booking. client_notified_at = when the client was told about this interview (straight away or in the 6 pm email);
-- NULL = still to go in the next 6 pm email. Interviews booked before this update were told already.
ALTER TABLE interviews ADD COLUMN client_notified_at DATETIME NULL AFTER letter_sent_at;
;;
UPDATE interviews SET client_notified_at = COALESCE(letter_sent_at, NOW()) WHERE status IN ('booked','done');
;;
