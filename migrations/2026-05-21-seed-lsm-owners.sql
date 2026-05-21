-- Set the owner (`wie`) on every seeded LSM gloss to gomer (id=1) +
-- inocencio (id=36). The `wie` column stores a JSON array of userId
-- strings (matched in queries via LIKE '%"<id>"%').

UPDATE lsm_data
   SET wie = JSON_ARRAY('1', '36')
 WHERE id BETWEEN 1 AND 170;

SELECT COUNT(*) AS updated_rows FROM lsm_data
 WHERE id BETWEEN 1 AND 170 AND wie = JSON_ARRAY('1', '36');
