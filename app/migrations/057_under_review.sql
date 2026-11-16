-- Benjamin, 16 Nov 2026: the "Screening" stage is called "Under review". Same stage, same place in the order; anyone at
-- it shows an "Under review" tag on every list.
UPDATE stages SET name = 'Under review' WHERE name = 'Screening' AND kind = 'active';
