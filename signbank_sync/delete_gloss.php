<?php
/**
 * POST { id }  →  archive the connected Signbank gloss and clear
 * form_data.signbank on our side.
 */

require_once __DIR__ . '/../php_api/db.php';
require_once __DIR__ . '/../php_api/session.php';
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_helpers.php';

$session = require_session();

$body = json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();

require_once __DIR__ . '/../php_api/datasets.php';
$ds    = require_dataset($pdo, $session, $body);

$glossid = signbank_get_connected_glossid($pdo, $id, $ds['code']);
if ($glossid === null) {
    json_response(['ok' => false, 'error' => 'not_connected'], 400);
}

$sb = signbank_dataset_info_for($ds['code']);
if ($sb === null) json_response(['ok' => false, 'error' => 'dataset_not_synced', 'dataset' => $ds['code']], 400);

$path = '/dictionary/api_delete_gloss/' . rawurlencode($sb['id']) . '/' . rawurlencode($glossid) . '/';
$res  = signbank_request('DELETE', $path, ['confirmed' => 'true']);

$log = [[
    't'     => date('H:i:s'),
    'level' => $res['ok'] ? 'ok' : 'error',
    'msg'   => "DELETE → Signbank glossid {$glossid}: HTTP {$res['status']}",
    'data'  => $res['body'],
]];

if ($res['ok']) {
    $session = current_session() ?? ['userId' => 0, 'username' => 'unknown'];
    $logEntry = logboek_entry(sprintf(
        'Signbank ontkoppeld (was glossid %s) door: %s',
        $glossid,
        $session['username'] ?: $session['userId']
    ));
    signbank_set_connection($pdo, $id, null, $logEntry, $ds['code']);
    $log[] = [
        't' => date('H:i:s'), 'level' => 'ok',
        'msg' => 'Cleared ' . $ds['table'] . '.signbank locally',
    ];
}

json_response([
    'ok'             => $res['ok'],
    'previous_glossid' => $glossid,
    'status'         => $res['status'],
    'response'       => $res['body'],
    'log'            => $log,
]);
