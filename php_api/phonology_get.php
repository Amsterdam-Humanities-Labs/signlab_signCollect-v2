<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

require_session();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, glos,
            Handeness, strongHand, weakHand, HandshapeChange, RelationArticulators,
            handLocation, ContactType, MovementShape, MovementDirection,
            RepeatedMovement, AlternatingMovement,
            relativeOrienationMovement, relativeOrienationLocation, orientationChange,
            virtualObjectt, phonologyOther, mouthGesture, mouthing, phoneticVariation,
            fonologie_fase1, fonologie_fase2
     FROM form_data WHERE id = ?"
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) json_response(['error' => 'not_found'], 404);

json_response($row);
