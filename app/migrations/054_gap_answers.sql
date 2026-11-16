-- Audit B7 (approved by Benjamin, 15 November 2026): when the assessment finds a requirement "not mentioned" in a CV,
-- the "Complete your application" form asks the candidate about it (at most 3 per position, critical first).
-- Their answer: "Yes" (current or earlier job) counts as "related, check on the call" (half credit), "Some exposure"
-- a quarter, "No" stays not mentioned. Kept by the requirement's wording, so a reworded requirement asks again.
CREATE TABLE gap_answers (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id    INT UNSIGNED NOT NULL,
  candidate_id      INT UNSIGNED NOT NULL,
  role_id           INT UNSIGNED NOT NULL,
  req_hash          CHAR(32) NOT NULL,
  requirement       VARCHAR(255) NOT NULL,
  answer            ENUM('recent','earlier','some','no') NOT NULL,
  detail            VARCHAR(500) NULL,
  questionnaire_id  INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gap (application_id, req_hash),
  KEY ix_gap_cand (candidate_id),
  CONSTRAINT fk_gap_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_gap_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
  CONSTRAINT fk_gap_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
