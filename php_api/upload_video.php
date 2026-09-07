<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$session = require_session();

require_once __DIR__ . '/datasets.php';
$pdo   = db();
$ds    = require_dataset($pdo, $session, $_POST);
$table = $ds['table'];

require_once __DIR__ . '/../signbank_sync/sync_helpers.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0)                          json_response(['error' => 'invalid_id'], 400);
if (!isset($_FILES['file']))           json_response(['error' => 'no_file'], 400);
if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_response(['error' => 'upload_failed', 'code' => $_FILES['file']['error']], 400);
}

$tmp  = $_FILES['file']['tmp_name'];
$hash = hash_file('sha256', $tmp);
if (!$hash) json_response(['error' => 'hash_failed'], 500);

// Self-recordings are served straight back out of the document root as
// /uploads/<name>, so this path is fixed by the URL the page plays, not a
// preference. It is a directory the application owns but never created:
// production has had it forever (a symlink onto the media volume), so
// nothing noticed that a host deployed from scratch has no /web/uploads at
// all - and there every recording died on a 500 before a byte was written.
// Create it instead of refusing, and when even that is impossible say which
// path could not be made, so the answer is "chown the docroot" and not a
// bare "uploads_dir_missing".
$dir = '/web/uploads';
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_response(['error' => 'uploads_dir_unwritable', 'path' => $dir], 500);
}

$filename = $hash . '.webm';
$dest     = $dir . '/' . $filename;

if (!is_file($dest) && !move_uploaded_file($tmp, $dest)) {
    json_response(['error' => 'move_failed'], 500);
}

$row = $pdo->prepare("SELECT zelfopname FROM `$table` WHERE id = ?");
$row->execute([$id]);
$current = $row->fetch();
if (!$current) json_response(['error' => 'gloss_not_found'], 404);

$arr = parse_json_array($current['zelfopname']);
if (!in_array($filename, $arr, true)) $arr[] = $filename;

$logEntry = logboek_entry("Zelfopname toegevoegd ($filename) door: " . ($session['username'] ?: $session['userId']));
$upd = $pdo->prepare(
    "UPDATE `$table`
     SET zelfopname = ?,
         logboek = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
     WHERE id = ?"
);
$upd->execute([json_encode(array_values($arr)), $logEntry, $id]);

// Auto-push the new self-recorded video to Signbank when this row is connected.
$signbankPush = null;
try {
    $push = signbank_upload_video_for($pdo, $id, $dest, $ds['code']);
    if ($push !== null) {
        $signbankPush = [
            'ok'      => $push['ok'],
            'status'  => $push['status'] ?? null,
            'glossid' => $push['glossid'] ?? null,
            'body'    => $push['body'] ?? null,
        ];
    }
} catch (Throwable $e) {
    $signbankPush = ['ok' => false, 'error' => $e->getMessage()];
}

json_response([
    'filename'      => $filename,
    'zelfopname'    => $arr,
    'signbank_push' => $signbankPush,
]);
