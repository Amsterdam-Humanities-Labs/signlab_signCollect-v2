<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$session = require_session();

$body  = json_body();
$glos  = trim((string)($body['glos'] ?? ''));
if ($glos === '') json_response(['error' => 'glos_required'], 400);

$glos_engels   = (string)($body['glos_engels']  ?? '');
$thema         = (string)($body['thema']        ?? '');
$labels        = is_array($body['labels'] ?? null)       ? array_values($body['labels'])       : [];
$senses        = is_array($body['senses'] ?? null)       ? array_values($body['senses'])       : [];
$sensesEngels  = is_array($body['sensesEngels'] ?? null) ? array_values($body['sensesEngels']) : [];

$pdo = db();
$stmt = $pdo->prepare(
    "INSERT INTO form_data
       (glos, glos_engels, thema, labels, senses, sensesEngels,
        wie, control_nodig, zelfopname, glosZichtbaar, logboek)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
);
$stmt->execute([
    $glos,
    $glos_engels,
    $thema,
    json_encode($labels,       JSON_UNESCAPED_UNICODE),
    json_encode($senses,       JSON_UNESCAPED_UNICODE),
    json_encode($sensesEngels, JSON_UNESCAPED_UNICODE),
    json_encode([(string)$session['userId']]),
    json_encode([]),
    json_encode([]),
    "Aangemaakt op " . date('j/n/Y @ H:i') . " door: " . ($session['username'] ?: $session['userId']),
]);

$id = (int)$pdo->lastInsertId();
json_response(['id' => $id]);
