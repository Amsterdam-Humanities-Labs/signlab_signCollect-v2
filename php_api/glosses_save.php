<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$session = require_session();

require_once __DIR__ . '/datasets.php';
$pdo   = db();
$ds    = require_dataset($pdo, $session, $body);
$table = $ds['table'];

require_once __DIR__ . '/../signbank_sync/sync_helpers.php';

$body   = json_body();
$id     = (int)($body['id'] ?? 0);
$fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];

if ($id <= 0 || !$fields) json_response(['error' => 'invalid_input'], 400);

$scalarFields = [
    'glos', 'glos_engels', 'thema',
    // phonology selects (stored as the option's `value` id)
    'Handeness', 'strongHand', 'weakHand', 'HandshapeChange', 'RelationArticulators',
    'handLocation', 'ContactType', 'MovementShape', 'MovementDirection',
    'RepeatedMovement', 'AlternatingMovement',
    'relativeOrienationMovement', 'relativeOrienationLocation', 'orientationChange',
    // phonology free-text
    'virtualObjectt', 'phonologyOther', 'mouthGesture', 'mouthing', 'phoneticVariation',
    // fase klaar flags ("0"/"1")
    'fonologie_fase1', 'fonologie_fase2',
];
$jsonFields   = ['labels', 'senses', 'sensesEngels', 'control_nodig', 'zelfopname', 'wie'];
$intFields    = ['glosZichtbaar'];

$updates = [];
$args    = [];

foreach ($fields as $name => $value) {
    if (in_array($name, $scalarFields, true)) {
        $updates[] = "`$name` = ?";
        $args[]    = $value === null ? null : (string)$value;
    } elseif (in_array($name, $jsonFields, true)) {
        if (!is_array($value)) json_response(['error' => "field_$name must be array"], 400);
        $updates[] = "`$name` = ?";
        $args[]    = json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
    } elseif (in_array($name, $intFields, true)) {
        $updates[] = "`$name` = ?";
        $args[]    = (int)$value;
    }
}

if (!$updates) json_response(['error' => 'no_editable_fields'], 400);

$logEntry = "Bijgewerkt op " . date('j/n/Y @ H:i') . " door: " . ($session['username'] ?: $session['userId']);
$updates[] = "logboek = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)";
$args[]    = $logEntry;

$args[] = $id;
$stmt = $pdo->prepare("UPDATE `$table` SET " . implode(', ', $updates) . " WHERE id = ?");
$stmt->execute($args);

$row = $pdo->prepare(
    "SELECT id, glos, glos_engels, wie, thema, labels, glosZichtbaar,
            zelfopname, senses, sensesEngels, control_nodig, signbank,
            Handeness, strongHand, weakHand, HandshapeChange, RelationArticulators,
            handLocation, ContactType, MovementShape, MovementDirection,
            RepeatedMovement, AlternatingMovement,
            relativeOrienationMovement, relativeOrienationLocation, orientationChange,
            virtualObjectt, phonologyOther, mouthGesture, mouthing, phoneticVariation
     FROM `$table` WHERE id = ?"
);
$row->execute([$id]);
$r = $row->fetch();
if (!$r) json_response(['error' => 'not_found'], 404);

// Auto-sync any field changes to Signbank when this row is connected.
// Only push the fields the caller actually changed in *this* request — pushing
// the entire row would re-validate every phonology value on Signbank, and a
// single stale value would roll the whole atomic update back.
$signbankSync = null;
if (!empty($r['signbank']) && $fields) {
    $relevantKeys = array_merge(array_keys(signbank_field_map()), array_keys(signbank_senses_keys()));
    $relevant = array_intersect_key($fields, array_flip($relevantKeys));
    if ($relevant) {
        $changedOnly = [];
        foreach ($relevant as $k => $v) $changedOnly[$k] = is_array($v) ? json_encode($v) : $v;
        try {
            // TODO(LSM-task-13): pass $ds['code'] once signbank_auto_sync_fields supports it
            $sync = signbank_auto_sync_fields($pdo, $id, $changedOnly);
            if ($sync !== null) {
                $signbankSync = [
                    'ok'           => $sync['ok'],
                    'status'       => $sync['status'],
                    'glossid'      => $sync['glossid'],
                    'fields_sent'  => $sync['fields_sent'],
                    'response'     => $sync['body'],
                ];
            }
        } catch (Throwable $e) {
            $signbankSync = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

json_response([
    'id'             => (int)$r['id'],
    'glos'           => $r['glos'] ?? '',
    'glos_engels'    => $r['glos_engels'] ?? '',
    'wie'            => parse_json_array($r['wie']),
    'thema'          => $r['thema'] ?? '',
    'labels'         => parse_json_array($r['labels']),
    'glosZichtbaar'  => (int)$r['glosZichtbaar'],
    'zelfopname'     => parse_json_array($r['zelfopname']),
    'senses'         => parse_json_array($r['senses']),
    'sensesEngels'   => parse_json_array($r['sensesEngels']),
    'control_nodig'  => parse_json_array($r['control_nodig']),
    'signbank'       => $r['signbank'] ?: null,
    'signbank_sync'  => $signbankSync,
]);
