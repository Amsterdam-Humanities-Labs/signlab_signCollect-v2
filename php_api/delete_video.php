<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$session = require_session();

$body     = json_body();
$id       = (int)($body['id'] ?? 0);
$filename = (string)($body['filename'] ?? '');
if ($id <= 0 || $filename === '') json_response(['error' => 'invalid_input'], 400);

$pdo = db();
$row = $pdo->prepare("SELECT zelfopname FROM form_data WHERE id = ?");
$row->execute([$id]);
$current = $row->fetch();
if (!$current) json_response(['error' => 'gloss_not_found'], 404);

$arr = array_values(array_filter(parse_json_array($current['zelfopname']), fn($f) => $f !== $filename));

$logEntry = "Zelfopname verwijderd ($filename) op " . date('j/n/Y @ H:i') . " door: " . ($session['username'] ?: $session['userId']);
$upd = $pdo->prepare(
    "UPDATE form_data
     SET zelfopname = ?,
         logboek = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
     WHERE id = ?"
);
$upd->execute([json_encode($arr), $logEntry, $id]);

json_response(['zelfopname' => $arr]);
