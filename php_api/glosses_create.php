<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/signbank_ecv.php';

$session = require_session();

$body  = json_body();
$glos  = trim((string)($body['glos'] ?? ''));
if ($glos === '') json_response(['error' => 'glos_required'], 400);

$glos_engels   = (string)($body['glos_engels']  ?? '');
$thema         = (string)($body['thema']        ?? '');
$labels        = is_array($body['labels'] ?? null)       ? array_values($body['labels'])       : [];
$senses        = is_array($body['senses'] ?? null)       ? array_values($body['senses'])       : [];
$sensesEngels  = is_array($body['sensesEngels'] ?? null) ? array_values($body['sensesEngels']) : [];
require_once __DIR__ . '/datasets.php';
$pdo   = db();
$context       = require_context($pdo, $session, $body);   // 403 if the user may not use it
$externValue   = $context === 'signbank' ? null : '1';
$ds    = require_dataset($pdo, $session, $body);
$table = $ds['table'];

$columns = [
    'glos'          => $glos,
    'glos_engels'   => $glos_engels,
    'thema'         => $thema,
    'labels'        => json_encode($labels,       JSON_UNESCAPED_UNICODE),
    'senses'        => json_encode($senses,       JSON_UNESCAPED_UNICODE),
    'sensesEngels'  => json_encode($sensesEngels, JSON_UNESCAPED_UNICODE),
    'wie'           => json_encode([(string)$session['userId']]),
    'control_nodig' => json_encode([]),
    'zelfopname'    => json_encode([]),
    'glosZichtbaar' => 0,
    'extern'        => $externValue,
];

// Optional Signbank provenance. The Glos Wizard sends these when the new
// gloss is being adopted from an ECV entry: the sign is already described in
// Signbank, so its id and phonology come along and both fonologie phases are
// already done. A hand-typed gloss sends none of it and inserts as before.
$signbank = trim((string)($body['signbank'] ?? ''));
if ($signbank !== '') $columns['signbank'] = $signbank;

$phonology = is_array($body['phonology'] ?? null) ? $body['phonology'] : [];
foreach (signbank_ecv_phonology_map() as $column) {
    if (!array_key_exists($column, $phonology)) continue;
    $v = $phonology[$column];
    if (is_array($v) || is_object($v)) continue;
    $columns[$column] = (string)$v;
}

foreach (['fonologie_fase1', 'fonologie_fase2'] as $fase) {
    if (isset($body[$fase])) $columns[$fase] = (string)(int)(bool)$body[$fase];
}

$columns['logboek'] = logboek_entry(
    ($signbank !== '' ? 'Glos aangemaakt uit Signbank ' . $signbank . ' door: ' : 'Glos aangemaakt door: ')
    . ($session['username'] ?: $session['userId'])
);

$names        = array_keys($columns);
$placeholders = implode(', ', array_fill(0, count($names), '?'));
$columnList   = implode(', ', array_map(fn($c) => "`$c`", $names));

$stmt = $pdo->prepare("INSERT INTO `$table` ($columnList) VALUES ($placeholders)");
$stmt->execute(array_values($columns));

$id = (int)$pdo->lastInsertId();
json_response(['id' => $id]);
