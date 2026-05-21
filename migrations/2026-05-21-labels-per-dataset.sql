-- Scope labels per dataset. Existing 127 rows were all created for NGT
-- (the only dataset that existed before LSM), so backfill to 'ngt'.
ALTER TABLE labels
  ADD COLUMN dataset VARCHAR(16) NOT NULL DEFAULT 'ngt';

UPDATE labels SET dataset = 'ngt' WHERE dataset IS NULL OR dataset = '';

SELECT dataset, COUNT(*) AS c FROM labels GROUP BY dataset;
