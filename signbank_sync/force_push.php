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
$only = isset($body['only_fields']) && is_array($body['only_fields']) ? $body['only_fields'] : null;
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM form_data WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) json_response(['error' => 'not_found'], 404);
if (empty($row['signbank'])) json_response(['ok' => false, 'error' => 'not_connected'], 400);

$cfg = signbank_config();
$path = '/dictionary/api_update_gloss/' . rawurlencode($cfg['dataset_id']) . '/' . rawurlencode($row['signbank']) . '/';

// Build the candidate payload from the entire row, then optionally narrow to
// only the local field names the caller asked for.
$source = $row;
if ($only !== null) {
    $source = array_intersect_key($row, array_flip($only));
    if (!$source) json_response(['ok' => false, 'error' => 'no_matching_fields_in_row'], 400);
}
$payload = signbank_build_update_payload($source);
if (!$payload) json_response(['ok' => false, 'error' => 'nothing_to_push'], 400);

$log = [];
$logStep = function (string $level, string $msg, $data = null) use (&$log) {
    $log[] = ['t' => date('H:i:s'), 'level' => $level, 'msg' => $msg, 'data' => $data];
};

$logStep('info', 'Build payload from form_data', ['fields' => array_keys($payload)]);
$logStep('out', 'POST → bulk push to Signbank', $payload);

$bulk = signbank_request('POST', $path, $payload);

if ($bulk['ok']) {
    $logStep('ok', "Bulk push OK (HTTP {$bulk['status']}, {$bulk['duration_ms']} ms)", $bulk['body']);
    json_response([
        'ok'           => true,
        'mode'         => 'bulk',
        'status'       => $bulk['status'],
        'glossid'      => $row['signbank'],
        'fields_sent'  => array_keys($payload),
        'succeeded'    => array_keys($payload),
        'failed'       => [],
        'response'     => $bulk['body'],
        'request'      => $bulk['request'],
        'duration_ms'  => $bulk['duration_ms'],
        'log'          => $log,
    ]);
}

$logStep('warn', "Bulk push rolled back (HTTP {$bulk['status']}); falling back to per-field",
         $bulk['body']);

$succeeded = []; $failed = [];
foreach ($payload as $field => $value) {
    $logStep('out', "POST {$field}", [$field => $value]);
    $r = signbank_request('POST', $path, [$field => $value]);
    if ($r['ok']) {
        $succeeded[] = $field;
        $logStep('ok', "  {$field} OK (HTTP {$r['status']}, {$r['duration_ms']} ms)", $r['body']);
    } else {
        $errBody = is_array($r['body']) ? $r['body'] : ['raw' => $r['body']];
        $failed[] = [
            'field'    => $field,
            'value'    => $value,
            'status'   => $r['status'],
            'error'    => $errBody,
            'duration_ms' => $r['duration_ms'] ?? null,
        ];
        $errMsg = is_array($errBody)
            ? ($errBody['errors']['Exception']
                ?? (is_array($errBody['errors'] ?? null) ? json_encode($errBody['errors']) : ($errBody['error'] ?? json_encode($errBody))))
            : (string)$errBody;
        $logStep('error', "  {$field} FAIL (HTTP {$r['status']}): {$errMsg}", $errBody);
    }
}

$logStep('info', "Per-field done: {$succeeded[0]} ok=" . count($succeeded) . ' failed=' . count($failed),
         ['ok_fields' => $succeeded, 'failed_fields' => array_column($failed, 'field')]);

json_response([
    'ok'          => count($failed) === 0,
    'mode'        => 'per_field',
    'glossid'     => $row['signbank'],
    'fields_sent' => array_keys($payload),
    'succeeded'   => $succeeded,
    'failed'      => $failed,
    'bulk_error'  => $bulk['body'],
    'request'     => $bulk['request'],
    'log'         => $log,
]);
