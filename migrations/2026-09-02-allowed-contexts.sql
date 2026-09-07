-- 2026-09-02 — per-user Signio / Signbank context access

-- 1. New column: JSON array of allowed context codes ('signio', 'signbank').
--    NULL is treated as "both" by the backend so an unmigrated row keeps working.
ALTER TABLE users
  ADD COLUMN allowed_contexts JSON NULL;

-- 2. Backfill: every existing user keeps the access they have today (both).
UPDATE users
  SET allowed_contexts = JSON_ARRAY('signio', 'signbank')
  WHERE allowed_contexts IS NULL;

-- 3. Users without an explicit dataset list get the NGT default made explicit.
UPDATE users
  SET allowed_datasets = JSON_ARRAY('ngt')
  WHERE allowed_datasets IS NULL;

-- 4. linde → Signbank only.
UPDATE users
  SET allowed_contexts = JSON_ARRAY('signbank'),
      default_context  = 'signbank',
      allowed_datasets = JSON_ARRAY('ngt'),
      default_dataset  = 'ngt'
  WHERE user = 'linde';
