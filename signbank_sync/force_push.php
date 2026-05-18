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

// Video upload is a *separate* multipart endpoint, not a regular field.
// Detect when the caller wants the local zelfopname pushed and remember it
// so we can run signbank_upload_video_for after the bulk text update.
$wantsVideoPush = ($only === null) || in_array('zelfopname', $only, true);
$videoLocalPath = null;
if ($wantsVideoPush) {
    $zelf = signbank_decode_json_array($row['zelfopname'] ?? null);
    if (!empty($zelf)) {
        $candidate = '/web/uploads/' . $zelf[0];
        if (is_file($candidate)) $videoLocalPath = $candidate;
    }
}

if (!$payload && !$videoLocalPath) {
    json_response(['ok' => false, 'error' => 'nothing_to_push'], 400);
}

$log = [];
$logStep = function (string $level, string $msg, $data = null) use (&$log) {
    $log[] = ['t' => date('H:i:s'), 'level' => $level, 'msg' => $msg, 'data' => $data];
};

if ($payload) {
    $logStep('info', 'Build payload from form_data', ['fields' => array_keys($payload)]);
}
if ($videoLocalPath) {
    $logStep('info', 'Video to upload (separate endpoint)', ['file' => basename($videoLocalPath)]);
}
$succeeded = []; $failed = [];
$mode = 'bulk';
$bulk = null;

if ($payload) {
    $logStep('out', 'POST → bulk push to Signbank', $payload);
    $bulk = signbank_request('POST', $path, $payload);
    if ($bulk['ok']) {
        $logStep('ok', "Bulk push OK (HTTP {$bulk['status']}, {$bulk['duration_ms']} ms)", $bulk['body']);
        $succeeded = array_keys($payload);
    } else {
        $logStep('warn', "Bulk push rolled back (HTTP {$bulk['status']}); falling back to per-field",
                 $bulk['body']);
        $mode = 'per_field';
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
    }
}

// After the field push (or skipped if no fields), upload the video via the
// dedicated multipart endpoint when we have one to push.
if ($videoLocalPath) {
    $logStep('out', "POST /video → " . basename($videoLocalPath), null);
    $vres = signbank_upload_video_for($pdo, $id, $videoLocalPath);
    if ($vres && $vres['ok']) {
        $succeeded[] = 'zelfopname';
        $logStep('ok', "  video OK (HTTP {$vres['status']}, {$vres['duration_ms']} ms)", $vres['body']);
    } else {
        $vErr = $vres ? $vres['body'] : 'no result';
        $failed[] = [
            'field'    => 'zelfopname',
            'value'    => basename($videoLocalPath),
            'status'   => $vres['status'] ?? null,
            'error'    => is_array($vErr) ? $vErr : ['raw' => $vErr],
            'duration_ms' => $vres['duration_ms'] ?? null,
        ];
        $logStep('error', "  video FAIL (HTTP " . ($vres['status'] ?? '?') . ")", $vErr);
    }
}

$logStep('info', 'Done: ok=' . count($succeeded) . ' failed=' . count($failed),
         ['ok_fields' => $succeeded, 'failed_fields' => array_column($failed, 'field')]);

$fieldsSent = array_keys($payload);
if ($videoLocalPath) $fieldsSent[] = 'zelfopname';

json_response([
    'ok'          => count($failed) === 0,
    'mode'        => $mode,
    'glossid'     => $row['signbank'],
    'fields_sent' => $fieldsSent,
    'succeeded'   => $succeeded,
    'failed'      => $failed,
    'response'    => $bulk['body'] ?? null,
    'request'     => $bulk['request'] ?? null,
    'duration_ms' => $bulk['duration_ms'] ?? null,
    'log'         => $log,
]);
