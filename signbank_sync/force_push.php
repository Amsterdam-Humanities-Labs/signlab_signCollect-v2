<?php
/**
 * POST { id }  →  push *every* mappable field from form_data to Signbank.
 * Useful from the Compare modal when you want signCollect to win and
 * overwrite Signbank.
 */

require_once __DIR__ . '/../php_api/db.php';
require_once __DIR__ . '/../php_api/session.php';
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_helpers.php';

require_session();

$body = json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT * FROM form_data WHERE id = ?"
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) json_response(['error' => 'not_found'], 404);
if (empty($row['signbank'])) json_response(['ok' => false, 'error' => 'not_connected'], 400);

$res = signbank_auto_sync_fields($pdo, $id, $row);
if ($res === null) {
    json_response(['ok' => false, 'error' => 'nothing_to_push'], 400);
}

json_response([
    'ok'          => $res['ok'],
    'status'      => $res['status'],
    'glossid'     => $res['glossid'],
    'fields_sent' => $res['fields_sent'],
    'response'    => $res['body'],
]);
