<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';

$session = require_session();

$body = json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();
$ds  = require_dataset($pdo, $session, $body);

// Revert a soft-delete: restore the matched_transcriptions row to the active
// marker ('1' is the dominant "added" value for live rows). Only flip rows
// currently marked DELETE, and keep the same zOg scoping as the delete path.
if ($ds['code'] === 'lsm') {
    // LSM: strict zOg='lsm' filter to avoid bleeding into NGT
    $stmt = $pdo->prepare(
        "UPDATE matched_transcriptions
         SET added = '1'
         WHERE id = ? AND zOg = 'lsm' AND UPPER(added) = 'DELETE'"
    );
} else {
    // NGT (and default): mirror the delete path's zOg set
    $stmt = $pdo->prepare(
        "UPDATE matched_transcriptions
         SET added = '1'
         WHERE id = ? AND zOg IN ('labels', 'extern', 'Glos') AND UPPER(added) = 'DELETE'"
    );
}
$stmt->execute([$id]);

json_response([
    'ok'       => true,
    'id'       => $id,
    'affected' => $stmt->rowCount(),
]);
