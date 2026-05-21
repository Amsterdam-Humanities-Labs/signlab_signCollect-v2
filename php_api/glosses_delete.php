<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$session = require_session();

$body = json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

require_once __DIR__ . '/datasets.php';
$pdo   = db();
$ds    = require_dataset($pdo, $session, $body);
$table = $ds['table'];

$logEntry = logboek_entry('Glos verborgen door: ' . ($session['username'] ?: $session['userId']));

$stmt = $pdo->prepare(
    "UPDATE `$table`
     SET glosZichtbaar = 1,
         logboek = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
     WHERE id = ?"
);
$stmt->execute([$logEntry, $id]);

json_response(['ok' => true, 'id' => $id]);
