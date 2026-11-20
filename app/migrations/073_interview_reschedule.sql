-- Rescheduling an interview (Benjamin, 20 Nov 2026: Deepa Rani was late, the interview moved to the next day, and the
-- new booking went to the client as "round 2"). A booking now moves in place: same interview, same round. The count
-- makes calendar apps replace the old entry (SEQUENCE in the invite) instead of adding a second one.
ALTER TABLE interviews
  ADD COLUMN reschedules   TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER reminded_at,
  ADD COLUMN rescheduled_at DATETIME NULL AFTER reschedules;
