<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$session = require_session();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0)                          json_response(['error' => 'invalid_id'], 400);
if (!isset($_FILES['file']))           json_response(['error' => 'no_file'], 400);
if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_response(['error' => 'upload_failed', 'code' => $_FILES['file']['error']], 400);
}

$tmp  = $_FILES['file']['tmp_name'];
$hash = hash_file('sha256', $tmp);
if (!$hash) json_response(['error' => 'hash_failed'], 500);

$dir = '/web/uploads';
if (!is_dir($dir)) json_response(['error' => 'uploads_dir_missing'], 500);

$filename = $hash . '.webm';
$dest     = $dir . '/' . $filename;

if (!is_file($dest) && !move_uploaded_file($tmp, $dest)) {
    json_response(['error' => 'move_failed'], 500);
}

$pdo = db();
$row = $pdo->prepare("SELECT zelfopname FROM form_data WHERE id = ?");
$row->execute([$id]);
$current = $row->fetch();
if (!$current) json_response(['error' => 'gloss_not_found'], 404);

$arr = parse_json_array($current['zelfopname']);
if (!in_array($filename, $arr, true)) $arr[] = $filename;

$logEntry = "Zelfopname toegevoegd ($filename) op " . date('j/n/Y @ H:i') . " door: " . ($session['username'] ?: $session['userId']);
$upd = $pdo->prepare(
    "UPDATE form_data
     SET zelfopname = ?,
         logboek = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
     WHERE id = ?"
);
$upd->execute([json_encode(array_values($arr)), $logEntry, $id]);

json_response(['filename' => $filename, 'zelfopname' => $arr]);
