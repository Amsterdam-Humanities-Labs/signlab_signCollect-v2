<?php
/**
 * POST { id }  →  pull all Signbank fields into form_data, overwriting local
 * values. Reverse-translates phonology labels to FieldChoice machine_values.
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
$stmt = $pdo->prepare("SELECT * FROM form_data WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) json_response(['error' => 'not_found'], 404);
if (empty($row['signbank'])) json_response(['ok' => false, 'error' => 'not_connected'], 400);

$cfg  = signbank_config();
$path = '/dictionary/get_gloss_data/' . rawurlencode($cfg['dataset_id']) . '/' . rawurlencode($row['signbank']) . '/';
$res  = signbank_get($path);
if (!$res['ok'] || !is_array($res['body'])) {
    json_response(['ok' => false, 'status' => $res['status'], 'response' => $res['body']], 200);
}
$remote = $res['body'][$row['signbank']] ?? null;
if (!$remote) {
    json_response(['ok' => false, 'error' => 'no_payload', 'response' => $res['body']], 200);
}

// Map Signbank read keys (with ":") → form_data column names
$readMap = [
    'Annotation ID Gloss: Dutch'    => 'glos',
    'Annotation ID Gloss: English'  => 'glos_engels',
    'Handedness'                    => 'Handeness',
    'Strong Hand'                   => 'strongHand',
    'Weak Hand'                     => 'weakHand',
    'Handshape Change'              => 'HandshapeChange',
    'Relation Between Articulators' => 'RelationArticulators',
    'Location'                      => 'handLocation',
    'Contact Type'                  => 'ContactType',
    'Movement Shape'                => 'MovementShape',
    'Movement Direction'            => 'MovementDirection',
    'Repeated Movement'             => 'RepeatedMovement',
    'Alternating Movement'          => 'AlternatingMovement',
    'Relative Orientation: Movement'=> 'relativeOrienationMovement',
    'Relative Orientation: Location'=> 'relativeOrienationLocation',
    'Orientation Change'            => 'orientationChange',
    'Virtual Object'                => 'virtualObjectt',
    'Phonology Other'               => 'phonologyOther',
    'Mouth Gesture'                 => 'mouthGesture',
    'Mouthing'                      => 'mouthing',
    'Phonetic Variation'            => 'phoneticVariation',
];

$updates = [];
$report  = [];
foreach ($readMap as $sbKey => $localKey) {
    $rv = $remote[$sbKey] ?? null;
    if ($rv === null || $rv === '' || $rv === '-') continue;
    $rv = is_array($rv) ? json_encode($rv) : (string)$rv;
    $rv = trim($rv);
    if ($rv === '') continue;
    // Reverse-translate phonology dropdown values
    $stored = signbank_label_to_machine_value($localKey, $rv);
    $updates[$localKey] = $stored;
    $report[]           = ['field' => $localKey, 'remote' => $rv, 'stored' => $stored];
}

// Senses: Signbank returns {"1":"a","2":"b"} → store as JSON array
foreach ([['Senses: Dutch','senses'], ['Senses: English','sensesEngels']] as [$sbKey,$localKey]) {
    $rv = $remote[$sbKey] ?? null;
    if (!is_array($rv)) continue;
    ksort($rv, SORT_NUMERIC);
    $arr = array_values($rv);
    $updates[$localKey] = json_encode($arr, JSON_UNESCAPED_UNICODE);
    $report[]           = ['field' => $localKey, 'remote' => implode(' · ', $arr), 'stored' => json_encode($arr)];
}

if (!$updates) {
    json_response(['ok' => true, 'message' => 'no usable fields to pull', 'report' => []]);
}

$cols = array_keys($updates);
$set  = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
$args = array_values($updates);
$logEntry = sprintf('Signbank pull (force) op %s door: %s', date('j/n/Y @ H:i'),
                    $session['username'] ?: $session['userId']);
$args[] = $logEntry;
$args[] = $id;
$sql = "UPDATE form_data SET $set,
            logboek = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
        WHERE id = ?";
$pdo->prepare($sql)->execute($args);

json_response([
    'ok'          => true,
    'glossid'     => $row['signbank'],
    'fields_set'  => array_keys($updates),
    'report'      => $report,
]);
