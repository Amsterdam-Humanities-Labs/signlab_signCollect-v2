<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$session = require_session();

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
$pdo = db();
$stmt = $pdo->prepare("UPDATE form_data SET " . implode(', ', $updates) . " WHERE id = ?");
$stmt->execute($args);

$row = $pdo->prepare(
    "SELECT id, glos, glos_engels, wie, thema, labels, glosZichtbaar,
            zelfopname, senses, sensesEngels, control_nodig
     FROM form_data WHERE id = ?"
);
$row->execute([$id]);
$r = $row->fetch();
if (!$r) json_response(['error' => 'not_found'], 404);

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
]);
