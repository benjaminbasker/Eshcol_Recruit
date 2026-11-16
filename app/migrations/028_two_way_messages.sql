-- 028: two-way email with candidates (Benjamin, 8 Nov 2026).
-- You reply (or write first) from the candidate's page; the email goes from resume@ like a
-- personal email, threaded to their message, and their answer comes back into the same
-- conversation through the resume@ inbox. Outgoing messages are kept here with direction 'out'.

ALTER TABLE candidate_messages ADD COLUMN direction ENUM('in','out') NOT NULL DEFAULT 'in' AFTER candidate_id;
;;
ALTER TABLE candidate_messages ADD COLUMN email_id INT UNSIGNED NULL AFTER mail_intake_id;
;;
ALTER TABLE candidate_messages ADD COLUMN sent_by INT UNSIGNED NULL AFTER email_id;
;;
ALTER TABLE candidate_messages ADD COLUMN message_ref VARCHAR(255) NULL AFTER sent_by;
;;
ALTER TABLE candidate_messages ADD COLUMN reply_to_id INT UNSIGNED NULL AFTER message_ref;
