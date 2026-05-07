<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

require_session();

$body = json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();
$stmt = $pdo->prepare(
    "UPDATE matched_transcriptions
     SET added = 'DELETE'
     WHERE id = ? AND zOg IN ('labels', 'extern', 'Glos')"
);
$stmt->execute([$id]);

json_response([
    'ok'       => true,
    'id'       => $id,
    'affected' => $stmt->rowCount(),
]);
