-- "These are older CVs" (14 November 2026): when an old CV is uploaded for someone already on file with a newer
-- one, the newer CV stays their latest; the old one only fills details we're missing. Plus the evening top-up
-- settings for "share your updated CV" (from 5 pm, use what is left of the daily limit, 25 kept spare).
ALTER TABLE upload_batches ADD COLUMN older TINYINT(1) NOT NULL DEFAULT 0 AFTER send_ack;
;;
ALTER TABLE upload_items MODIFY outcome ENUM('new','updated','duplicate_file','needs_ocr','failed','skipped','older_copy') NOT NULL;
;;
INSERT IGNORE INTO settings (k, v) VALUES ('cvreq_topup_from', '17'), ('cvreq_reserve', '25');
