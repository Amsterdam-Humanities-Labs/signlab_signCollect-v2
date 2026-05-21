<?php
/**
 * GET ?id=<gloss_id>  →  fetch the logbook for a gloss.
 *
 * Returns the existing `logboek` LONGTEXT column split into individual
 * entries. The column is populated incrementally by glosses_save,
 * upload_video, broadcast_gloss, etc. via CONCAT_WS, so each line is a
 * separate event.
 *
 *   200: { entries: [{ text, ts? }, ...] }
 *   400: { error: 'invalid_id' } / 'forbidden_dataset'
 *   404: { error: 'not_found' }
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';

$session = require_session();

$pdo = db();
$ds  = require_dataset($pdo, $session, $_GET);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$table = $ds['table'];
$stmt = $pdo->prepare("SELECT CONVERT(logboek USING utf8mb4) AS logboek FROM `$table` WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) json_response(['error' => 'not_found'], 404);

$raw = (string)($row['logboek'] ?? '');
$lines = $raw === '' ? [] : preg_split('/\r?\n/', $raw);

// Each line is mostly free-form Dutch text written by the various save
// endpoints. Some recent entries (added since this feature shipped) begin
// with a `[YYYY-MM-DD HH:MM] ` timestamp prefix — extract it so the UI
// can show it in a column instead of inline.
$entries = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $ts = null;
    if (preg_match('/^\[(\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2})?)\]\s*(.*)$/u', $line, $m)) {
        $ts   = $m[1];
        $line = $m[2];
    } elseif (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4}\s*@\s*\d{1,2}:\d{2})/u', $line, $m)) {
        $ts = $m[1];
    }
    $entries[] = ['text' => $line, 'ts' => $ts];
}

json_response([
    'gloss_id' => $id,
    'dataset'  => $ds['code'],
    'entries'  => $entries,
]);
