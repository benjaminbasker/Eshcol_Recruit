-- "Rejected: insufficient information" (Benjamin, 14 November 2026): a closing stage in the stage drop-down for
-- candidates who can't be judged because the CV or their answers leave too much out. Like "Not suitable", it
-- closes the application (no email is sent to the candidate) and asks for a short note.
INSERT INTO stages (name, sort, kind, is_active)
SELECT 'Rejected: insufficient information', 82, 'fail', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM stages WHERE name = 'Rejected: insufficient information');
