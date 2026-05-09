<?php
/**
 * GET ?id=<form_data_id>  →  Fetch the full Signbank record for a connected
 * gloss and pre-compute a side-by-side diff against the local form_data row.
 */

require_once __DIR__ . '/../php_api/db.php';
require_once __DIR__ . '/../php_api/session.php';
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_helpers.php';

require_session();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, glos, glos_engels, senses, sensesEngels, signbank, zelfopname,
            Handeness, strongHand, weakHand, HandshapeChange, RelationArticulators,
            handLocation, ContactType, MovementShape, MovementDirection,
            RepeatedMovement, AlternatingMovement,
            relativeOrienationMovement, relativeOrienationLocation, orientationChange,
            virtualObjectt, phonologyOther, mouthGesture, mouthing, phoneticVariation,
            fonologie_fase1, fonologie_fase2, signbank_status, extern
     FROM form_data WHERE id = ?"
);
$stmt->execute([$id]);
$local = $stmt->fetch();
if (!$local) json_response(['error' => 'not_found'], 404);

$glossid = trim((string)($local['signbank'] ?? ''));
if ($glossid === '') {
    json_response(['ok' => false, 'error' => 'not_connected', 'message' => 'This gloss is not connected to Signbank.'], 400);
}

$cfg = signbank_config();
$path = '/dictionary/get_gloss_data/' . rawurlencode($cfg['dataset_id']) . '/' . rawurlencode($glossid) . '/';
$res = signbank_get($path);

if (!$res['ok']) {
    json_response([
        'ok'        => false,
        'glossid'   => $glossid,
        'status'    => $res['status'],
        'response'  => $res['body'],
        'request'   => $res['request'],
    ], 200);
}

// Signbank returns { "<glossid>": { …fields… } }
$remote = is_array($res['body']) ? ($res['body'][$glossid] ?? null) : null;
if (!$remote) {
    json_response([
        'ok'      => false,
        'glossid' => $glossid,
        'status'  => $res['status'],
        'error'   => 'Signbank returned no payload for glossid ' . $glossid,
        'response' => $res['body'],
    ], 200);
}

// Build the comparison map.  Each entry: signCollect column → Signbank key.
// Note: Signbank uses ":" (e.g. "Annotation ID Gloss: Dutch") in `get_gloss_data`,
// but "(Dutch)" in `api_create_gloss`. The colon form is canonical for *reading*.
$compareMap = [
    'glos'                       => 'Annotation ID Gloss: Dutch',
    'glos_engels'                => 'Annotation ID Gloss: English',
    'Handeness'                  => 'Handedness',
    'strongHand'                 => 'Strong Hand',
    'weakHand'                   => 'Weak Hand',
    'HandshapeChange'            => 'Handshape Change',
    'RelationArticulators'       => 'Relation Between Articulators',
    'handLocation'               => 'Location',
    'ContactType'                => 'Contact Type',
    'MovementShape'              => 'Movement Shape',
    'MovementDirection'          => 'Movement Direction',
    'RepeatedMovement'           => 'Repeated Movement',
    'AlternatingMovement'        => 'Alternating Movement',
    'relativeOrienationMovement' => 'Relative Orientation: Movement',
    'relativeOrienationLocation' => 'Relative Orientation: Location',
    'orientationChange'          => 'Orientation Change',
    'virtualObjectt'             => 'Virtual Object',
    'phonologyOther'             => 'Phonology Other',
    'mouthGesture'               => 'Mouth Gesture',
    'mouthing'                   => 'Mouthing',
    'phoneticVariation'          => 'Phonetic Variation',
];

// Senses: Signbank returns {"1":"a","2":"b"} — flatten to ordered array for comparison.
$flattenSenses = function ($v) {
    if (is_array($v)) {
        // Could be either a list or a {n: text} dict — flatten to ordered list.
        if (array_keys($v) !== range(0, count($v) - 1)) {
            ksort($v, SORT_NUMERIC);
            return array_values($v);
        }
        return $v;
    }
    if (is_string($v)) {
        $d = json_decode($v, true);
        if (is_array($d)) return $flattenSenses ? $flattenSenses($d) : $d;
    }
    return [];
};

$normLocalScalar  = fn($v) => trim((string)($v ?? ''));
$normRemoteScalar = function ($v) {
    if ($v === null || $v === '' || $v === '-') return '';
    return trim((string)$v);
};

$fields = [];
$dropdownFields = signbank_phonology_dropdown_fields();
foreach ($compareMap as $localKey => $remoteKey) {
    $lvRaw = $normLocalScalar($local[$localKey] ?? '');
    $rv    = $normRemoteScalar($remote[$remoteKey] ?? '');
    // For phonology dropdowns the local column stores the FieldChoice
    // machine_value; translate to the human label for an apples-to-apples
    // comparison against Signbank's response (which always returns the label).
    $lvForCompare = $lvRaw;
    $lvDisplay    = $lvRaw;
    if ($lvRaw !== '' && in_array($localKey, $dropdownFields, true)) {
        $translated = signbank_normalize_value($localKey, $lvRaw);
        if ($translated !== '' && $translated !== $lvRaw) {
            $lvForCompare = $translated;
            $lvDisplay    = $translated . ' (mv ' . $lvRaw . ')';
        }
    }
    $matches = ($lvForCompare === $rv) || ($lvForCompare === '' && $rv === '');
    $fields[] = [
        'label'      => $remoteKey,
        'local_key'  => $localKey,
        'remote_key' => $remoteKey,
        'local'      => $lvDisplay,
        'remote'     => $rv,
        'matches'    => $matches,
        'note'       => null,
    ];
}

// Senses comparison — flatten Signbank's {1:'a',2:'b'} dict.
$lNl = signbank_decode_json_array($local['senses'] ?? null);
$lEn = signbank_decode_json_array($local['sensesEngels'] ?? null);
$rNl = $flattenSenses($remote['Senses: Dutch'] ?? []);
$rEn = $flattenSenses($remote['Senses: English'] ?? []);
$fields[] = [
    'label'      => 'Senses: Dutch',
    'local_key'  => 'senses',
    'remote_key' => 'Senses: Dutch',
    'local'      => implode(' · ', $lNl),
    'remote'     => implode(' · ', $rNl),
    'matches'    => $lNl == $rNl,
];
$fields[] = [
    'label'      => 'Senses: English',
    'local_key'  => 'sensesEngels',
    'remote_key' => 'Senses: English',
    'local'      => implode(' · ', $lEn),
    'remote'     => implode(' · ', $rEn),
    'matches'    => $lEn == $rEn,
];

$mismatchCount = 0;
foreach ($fields as $f) if (!$f['matches']) $mismatchCount++;

json_response([
    'ok'             => true,
    'form_data_id'   => $id,
    'glossid'        => $glossid,
    'fields'         => $fields,
    'mismatch_count' => $mismatchCount,
    'remote_extra'   => [
        // Surface useful read-only Signbank fields not in our diff
        'Annotation ID Gloss: Dutch'   => $remote['Annotation ID Gloss: Dutch']   ?? null,
        'Annotation ID Gloss: English' => $remote['Annotation ID Gloss: English'] ?? null,
        'In The Web Dictionary'        => $remote['In The Web Dictionary']        ?? null,
        'Affiliation'                  => $remote['Affiliation']                  ?? null,
        'Video'                        => $remote['Video']                        ?? null,
        'Link'                         => $remote['Link']                         ?? null,
        'Tags'                         => $remote['Tags']                         ?? null,
        'Notes'                        => $remote['Notes']                        ?? null,
    ],
    'remote_raw'     => $remote,
    'local'          => [
        'zelfopname' => signbank_decode_json_array($local['zelfopname'] ?? null),
    ],
    'duration_ms'    => $res['duration_ms'],
]);
