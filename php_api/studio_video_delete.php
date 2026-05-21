<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';

require_session();

$body = json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();
$ds  = require_dataset($pdo, $session, $body);

// Branch UPDATE by dataset to control zOg filtering
if ($ds['code'] === 'lsm') {
    // LSM: strict zOg='lsm' filter to avoid bleeding into NGT
    $stmt = $pdo->prepare(
        "UPDATE matched_transcriptions
         SET added = 'DELETE'
         WHERE id = ? AND zOg = 'lsm'"
    );
} else {
    // NGT (and default): preserve legacy behavior — delete all
    // matched_transcriptions for this id regardless of zOg
    // because legacy NGT rows use many zOg values ('labels', 'extern', 'Glos')
    $stmt = $pdo->prepare(
        "UPDATE matched_transcriptions
         SET added = 'DELETE'
         WHERE id = ? AND zOg IN ('labels', 'extern', 'Glos')"
    );
}
$stmt->execute([$id]);

json_response([
    'ok'       => true,
    'id'       => $id,
    'affected' => $stmt->rowCount(),
]);
