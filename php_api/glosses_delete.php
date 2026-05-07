<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$session = require_session();

$body = json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();
$logEntry = "Verborgen op " . date('j/n/Y @ H:i') . " door: " . ($session['username'] ?: $session['userId']);

$stmt = $pdo->prepare(
    "UPDATE form_data
     SET glosZichtbaar = 1,
         logboek = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
     WHERE id = ?"
);
$stmt->execute([$logEntry, $id]);

json_response(['ok' => true, 'id' => $id]);
