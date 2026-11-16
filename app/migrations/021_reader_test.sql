-- Eshcol Recruit: Settings › Reader test. Runs the free reader, Claude Haiku and Claude Sonnet
-- on the same CVs, side by side, and scores each against checked answers. Changes no candidate.
CREATE TABLE reader_tests (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
;;
CREATE TABLE reader_test_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  test_id       INT UNSIGNED NOT NULL,
  candidate_id  INT UNSIGNED NOT NULL,
  reader        VARCHAR(10) NOT NULL,
  grp           VARCHAR(20) NULL,
  fields        MEDIUMTEXT NULL,
  raw           MEDIUMTEXT NULL,
  model         VARCHAR(60) NULL,
  tokens_in     INT UNSIGNED NULL,
  tokens_out    INT UNSIGNED NULL,
  cost_usd      DECIMAL(10,5) NULL,
  ms            INT UNSIGNED NULL,
  error         VARCHAR(300) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL,
  UNIQUE KEY uq_rti (test_id, candidate_id, reader),
  KEY ix_rti_cand (candidate_id),
  CONSTRAINT fk_rti_test FOREIGN KEY (test_id) REFERENCES reader_tests(id) ON DELETE CASCADE,
  CONSTRAINT fk_rti_cand FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
