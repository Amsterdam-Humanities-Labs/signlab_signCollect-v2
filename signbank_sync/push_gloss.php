<?php
require_once __DIR__ . '/../php_api/db.php';
require_once __DIR__ . '/../php_api/session.php';
require_once __DIR__ . '/client.php';

require_session();

$body = json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, glos, glos_engels, senses, sensesEngels, extern, logboek
     FROM form_data WHERE id = ?"
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) json_response(['error' => 'not_found'], 404);

if (trim((string)$row['glos']) === '') json_response(['error' => 'glos_required'], 400);

try {
    $cfg = signbank_config();
} catch (Throwable $e) {
    json_response(['error' => 'config_error', 'message' => $e->getMessage()], 500);
}

$path    = '/dictionary/api_create_gloss/' . rawurlencode($cfg['dataset_id']) . '/';
$payload = signbank_build_create_payload($row, $cfg);

$res = signbank_post_json($path, $payload);

// Append a logboek note describing the push outcome (success or failure).
$session = current_session() ?? ['userId' => 0, 'username' => 'unknown'];
$tag     = $res['ok'] ? 'OK' : ('FAIL ' . ($res['status'] ?: 'curl'));
$logEntry = sprintf(
    "Signbank push %s op %s door: %s",
    $tag,
    date('j/n/Y @ H:i'),
    $session['username'] ?: $session['userId']
);
$upd = $pdo->prepare(
    "UPDATE form_data
     SET logboek = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
     WHERE id = ?"
);
$upd->execute([$logEntry, $id]);

json_response([
    'ok'         => $res['ok'],
    'status'     => $res['status'],
    'response'   => $res['body'],
    'http_error' => $res['error'],
    'sent'       => [
        'endpoint' => $path,
        'payload'  => $payload,
    ],
]);
