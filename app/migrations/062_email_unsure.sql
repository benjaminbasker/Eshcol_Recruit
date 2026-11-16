-- Audit R5 (16 Nov 2026): "unsure" = handed to Brevo but no clear answer came back (a timeout, or the server stopped
-- half-way). Never resent automatically; listed for Benjamin to resend or cancel.
ALTER TABLE email_queue MODIFY status ENUM('pending','held','sent','failed','cancelled','unsure') NOT NULL DEFAULT 'pending';
;;
