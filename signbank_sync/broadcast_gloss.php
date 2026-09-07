<?php

// signcollect-lib's install-root resolver: sc_path(), sc_dir(), sc_root().
// Vendored shim - it finds /web/lib/paths.php, or falls back to /web.
require_once __DIR__ . '/../sc_paths.php';

/**
 * POST { id }  →  create the corresponding gloss on Signbank and store
 * the returned glossid in form_data.signbank.
 *
 * No-op if the row is already connected (returns the existing glossid).
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
$table = $ds['table'];

$stmt = $pdo->prepare(
    "SELECT id, glos, glos_engels, senses, sensesEngels, signbank, zelfopname,
            Handeness, strongHand, weakHand, HandshapeChange, RelationArticulators,
            handLocation, ContactType, MovementShape, MovementDirection,
            RepeatedMovement, AlternatingMovement,
            relativeOrienationMovement, relativeOrienationLocation, orientationChange,
            virtualObjectt, phonologyOther, mouthGesture, mouthing, phoneticVariation,
            extern
     FROM `$table` WHERE id = ?"
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) json_response(['error' => 'not_found'], 404);

if (trim((string)$row['glos']) === '') json_response(['error' => 'glos_required'], 400);

if (!empty($row['signbank'])) {
    json_response([
        'ok'              => true,
        'already_connected' => true,
        'glossid'         => $row['signbank'],
        'message'         => 'Already connected to Signbank',
    ]);
}

$sb = signbank_dataset_info_for($ds['code']);
if ($sb === null) json_response(['ok' => false, 'error' => 'dataset_not_synced', 'dataset' => $ds['code']], 400);

$log = [];
$logStep = function (string $level, string $msg, $data = null) use (&$log) {
    $log[] = ['t' => date('H:i:s'), 'level' => $level, 'msg' => $msg, 'data' => $data];
};

// 1) create the gloss on Signbank with the bare minimum required fields
$logStep('info', 'Creating gloss on Signbank', ['glos' => $row['glos']]);
$createPayload = signbank_build_create_payload($row, ['dataset_acronym' => $sb['acronym']]);
$createPath    = '/dictionary/api_create_gloss/' . rawurlencode($sb['id']) . '/';
$createRes     = signbank_post_json($createPath, $createPayload);

if (!$createRes['ok']) {
    $logStep('error', "Create failed (HTTP {$createRes['status']})", $createRes['body']);
    json_response([
        'ok'       => false,
        'stage'    => 'create',
        'status'   => $createRes['status'],
        'response' => $createRes['body'],
        'log'      => $log,
        'request'  => $createRes['request'],
    ], 200);
}

$glossid = (string)($createRes['body']['glossid'] ?? '');
if ($glossid === '') {
    $logStep('error', 'Create returned no glossid', $createRes['body']);
    json_response([
        'ok'       => false,
        'stage'    => 'create',
        'response' => $createRes['body'],
        'log'      => $log,
    ], 200);
}
$logStep('ok', "Signbank assigned glossid {$glossid}", $createRes['body']);

// 2) store the connection on our side
$session = current_session() ?? ['userId' => 0, 'username' => 'unknown'];
$logEntry = logboek_entry(sprintf(
    'Signbank gekoppeld als glossid %s door: %s',
    $glossid,
    $session['username'] ?: $session['userId']
));
signbank_set_connection($pdo, $id, $glossid, $logEntry, $ds['code']);
$logStep('ok', "Stored signbank glossid in {$table}");

// 3) push the rest of the fields (phonology + senses) as a follow-up update,
//    since api_create_gloss only persists the lemma/annotation/senses subset.
$updateRes = signbank_auto_sync_fields($pdo, $id, $row, $ds['code']);
if ($updateRes !== null) {
    if ($updateRes['ok']) {
        $logStep('ok', 'Phonology + extras pushed via api_update_gloss', $updateRes['body']);
    } else {
        $logStep('warn', "Phonology push got HTTP {$updateRes['status']} (non-fatal)", $updateRes['body']);
    }
}

// 4) if a zelfopname exists, push the first one as the gloss video too.
$zelf = signbank_decode_json_array($row['zelfopname'] ?? null);
if ($zelf) {
    $localFile = sc_dir('uploads') . $zelf[0];
    $videoRes = signbank_upload_video_for($pdo, $id, $localFile, $ds['code']);
    if ($videoRes && $videoRes['ok']) {
        $logStep('ok', 'Zelfopname uploaded to Signbank as gloss video', $videoRes['body']);
    } elseif ($videoRes) {
        $logStep('warn', "Zelfopname upload failed (HTTP {$videoRes['status']})", $videoRes['body']);
    }
}

json_response([
    'ok'       => true,
    'glossid'  => $glossid,
    'log'      => $log,
    'create'   => [
        'status'   => $createRes['status'],
        'request'  => $createRes['request'],
        'response' => $createRes['body'],
    ],
]);
