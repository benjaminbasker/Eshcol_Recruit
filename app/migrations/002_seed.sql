-- Eshcol Recruit: default pipeline stages. Renamed and reordered in Settings.
INSERT INTO stages (name, sort, kind) VALUES
  ('Applied', 10, 'active'),
  ('Screening', 20, 'active'),
  ('Shortlisted', 30, 'active'),
  ('Submitted to client', 40, 'active'),
  ('Client interview', 50, 'active'),
  ('Offer made', 60, 'active'),
  ('Joined', 70, 'success'),
  ('Not suitable', 80, 'fail'),
  ('Withdrew', 90, 'fail');
;;
INSERT INTO settings (k, v) VALUES
  ('retention_months', '24'),
  ('reconfirm_after_months', '6'),
  ('default_weights', '{"must":50,"experience":15,"qualification":10,"location":10,"notice":10,"ctc":5}');
