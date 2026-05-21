-- 2026-05-21 — add multi-dataset support

-- 1. Per-user dataset settings on the existing users table.
-- ALTER TABLE … ADD COLUMN IF NOT EXISTS is MySQL 8.0+. If on 5.7,
-- run the ALTERs without IF NOT EXISTS and accept the error on re-run.
ALTER TABLE users
  ADD COLUMN default_dataset  VARCHAR(16) NOT NULL DEFAULT 'ngt',
  ADD COLUMN allowed_datasets JSON         NULL;

-- 2. Backfill: every existing user gets NGT-only access.
UPDATE users
  SET allowed_datasets = JSON_ARRAY('ngt')
  WHERE allowed_datasets IS NULL;

-- 3. inocencio → LSM only.
UPDATE users
  SET default_dataset  = 'lsm',
      allowed_datasets = JSON_ARRAY('lsm')
  WHERE username = 'inocencio';

-- 4. New LSM glosses table — exact same shape as form_data.
--    LIKE clones columns, types, indexes, AUTO_INCREMENT — but not
--    triggers or foreign keys (form_data has none of either).
CREATE TABLE IF NOT EXISTS lsm_data LIKE form_data;
