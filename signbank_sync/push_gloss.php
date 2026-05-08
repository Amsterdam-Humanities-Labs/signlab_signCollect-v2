<?php
require_once __DIR__ . '/../php_api/db.php';
require_once __DIR__ . '/../php_api/session.php';
require_once __DIR__ . '/client.php';

require_session();

$body = json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, glos, glos_engels, senses, sensesEngels, zelfopname,
            Handeness, strongHand, weakHand, HandshapeChange, RelationArticulators,
            handLocation, ContactType, MovementShape, MovementDirection,
            RepeatedMovement, AlternatingMovement,
            relativeOrienationMovement, relativeOrienationLocation, orientationChange,
            virtualObjectt, phonologyOther, mouthGesture, mouthing, phoneticVariation,
            fonologie_fase1, fonologie_fase2, extern
     FROM form_data WHERE id = ?"
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) json_response(['error' => 'not_found'], 404);

if (trim((string)$row['glos']) === '') json_response(['error' => 'glos_required'], 400);

try {
    $cfg = signbank_config();
} catch (Throwable $e) {
    json_response(['error' => 'config_error', 'message' => $e->getMessage()], 500);
}

$log = [];
$logStep = function (string $level, string $msg, $data = null) use (&$log) {
    $log[] = [
        't'     => date('H:i:s'),
        'level' => $level,
        'msg'   => $msg,
        'data'  => $data,
    ];
};

$logStep('info', 'Bron-rij gelezen uit form_data', [
    'id'             => (int)$row['id'],
    'glos'           => $row['glos'],
    'glos_engels'    => $row['glos_engels'],
    'senses'         => signbank_decode_json_array($row['senses']),
    'sensesEngels'   => signbank_decode_json_array($row['sensesEngels']),
    'zelfopname'     => signbank_decode_json_array($row['zelfopname']),
    'fonologie_fase1' => $row['fonologie_fase1'],
    'fonologie_fase2' => $row['fonologie_fase2'],
]);

$payload = signbank_build_create_payload($row, $cfg);
$path    = '/dictionary/api_create_gloss/' . rawurlencode($cfg['dataset_id']) . '/';
$logStep('out', 'POST → Signbank ' . $path, $payload);

$res = signbank_post_json($path, $payload);

if ($res['ok']) {
    $logStep('ok', "Signbank antwoord: HTTP {$res['status']} ({$res['duration_ms']} ms)", $res['body']);
} else {
    $msg = "Signbank fout: HTTP {$res['status']}";
    if ($res['error']) $msg .= ' / curl: ' . $res['error'];
    $logStep('error', $msg, $res['body']);
}

// Verify by listing accessible datasets (proves auth + connectivity end-to-end).
$verify = signbank_get('/dictionary/info/');
if ($verify['ok']) {
    $datasets = is_array($verify['body']) ? $verify['body'] : [];
    $logStep('info',
        'Verificatie: API-key heeft toegang tot ' . count($datasets) . ' dataset(s)',
        $datasets);
} else {
    $logStep('error', "Verificatie mislukt (HTTP {$verify['status']})", $verify['body']);
}

// Append outcome to gloss logboek.
$session = current_session() ?? ['userId' => 0, 'username' => 'unknown'];
$tag     = $res['ok'] ? 'OK' : ('FAIL ' . ($res['status'] ?: 'curl'));
$logEntry = sprintf(
    "Signbank push %s op %s door: %s",
    $tag,
    date('j/n/Y @ H:i'),
    $session['username'] ?: $session['userId']
);
$upd = $pdo->prepare(
    "UPDATE form_data
     SET logboek = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
     WHERE id = ?"
);
$upd->execute([$logEntry, $id]);

json_response([
    'ok'           => $res['ok'],
    'status'       => $res['status'],
    'duration_ms'  => $res['duration_ms'],
    'content_type' => $res['content_type'],
    'response'     => $res['body'],
    'raw_excerpt'  => mb_substr($res['raw'] ?? '', 0, 800),
    'http_error'   => $res['error'],
    'request'      => $res['request'],
    'log'          => $log,
    'gloss_id'     => (int)$row['id'],
    'glos'         => $row['glos'],
    'thumbnail'    => signbank_decode_json_array($row['zelfopname'])[0] ?? null,
]);
