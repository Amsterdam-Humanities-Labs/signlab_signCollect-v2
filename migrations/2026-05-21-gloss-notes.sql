-- Chat-style notes per gloss. One table covers all datasets; the
-- `dataset` column is required so colliding ids across form_data /
-- lsm_data don't bleed into each other.

CREATE TABLE IF NOT EXISTS gloss_notes (
  id          INT NOT NULL AUTO_INCREMENT,
  dataset     VARCHAR(16) NOT NULL,
  gloss_id    INT NOT NULL,
  user_id     INT NOT NULL,
  note_text   TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dataset_gloss (dataset, gloss_id, created_at),
  KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'gloss_notes created' AS status,
       (SELECT COUNT(*) FROM gloss_notes) AS rows_now;
