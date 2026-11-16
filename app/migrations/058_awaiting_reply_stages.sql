-- Benjamin, 16 Nov 2026: two stages for candidates we're chasing.
--  "Awaiting candidate's reply": between Under review and Shortlisted (still in play).
--  "No response from candidate": a closing stage next to Withdrew (their doing, like Withdrew; not counted as your
--  turning them down).
INSERT INTO stages (name, sort, kind, is_active)
SELECT 'Awaiting candidate''s reply', 25, 'active', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM stages WHERE name = 'Awaiting candidate''s reply');
;;
INSERT INTO stages (name, sort, kind, is_active)
SELECT 'No response from candidate', 92, 'fail', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM stages WHERE name = 'No response from candidate');
