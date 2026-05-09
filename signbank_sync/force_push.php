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

// Try one bulk push first; if Signbank rolls everything back because a single
// field hits a server-side exception (e.g. "Transaction management error" on
// Virtual Object / Phonology Other in current Signbank builds), fall back to
// pushing each field individually so the rest still land.
$bulk = signbank_auto_sync_fields($pdo, $id, $row);
if ($bulk === null) {
    json_response(['ok' => false, 'error' => 'nothing_to_push'], 400);
}
if ($bulk['ok']) {
    json_response([
        'ok'          => true,
        'mode'        => 'bulk',
        'status'      => $bulk['status'],
        'glossid'     => $bulk['glossid'],
        'fields_sent' => $bulk['fields_sent'],
        'response'    => $bulk['body'],
    ]);
}

// Per-field fallback.
$cfg = signbank_config();
$path = '/dictionary/api_update_gloss/' . rawurlencode($cfg['dataset_id']) . '/' . rawurlencode($bulk['glossid']) . '/';

$payload = signbank_build_update_payload($row);
$succeeded = []; $failed = [];
foreach ($payload as $field => $value) {
    $r = signbank_request('POST', $path, [$field => $value]);
    if ($r['ok']) {
        $succeeded[] = $field;
    } else {
        $errBody = is_array($r['body']) ? $r['body'] : ['raw' => $r['body']];
        $failed[] = ['field' => $field, 'value' => $value, 'status' => $r['status'], 'error' => $errBody];
    }
}

json_response([
    'ok'         => count($failed) === 0,
    'mode'       => 'per_field',
    'glossid'    => $bulk['glossid'],
    'fields_sent'=> array_keys($payload),
    'succeeded'  => $succeeded,
    'failed'     => $failed,
    'bulk_error' => $bulk['body'],
]);
