<?php
/**
 * GET ?id=<gloss_id>  →  fetch all notes for a gloss in the active dataset.
 *
 *   200: { notes: [{ id, user_id, user, note_text, created_at }, ...] }
 *   400: { error: 'invalid_id' }
 *   403: { error: 'forbidden_dataset' }
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';

$session = require_session();

$pdo = db();
$ds  = require_dataset($pdo, $session, $_GET);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) json_response(['error' => 'invalid_id'], 400);

$stmt = $pdo->prepare(
    "SELECT n.id, n.user_id, COALESCE(u.user, CONCAT('#', n.user_id)) AS user,
            n.note_text, n.created_at
     FROM gloss_notes n
     LEFT JOIN users u ON u.userId = n.user_id
     WHERE n.dataset = ? AND n.gloss_id = ?
     ORDER BY n.created_at ASC, n.id ASC"
);
$stmt->execute([$ds['code'], $id]);

json_response([
    'notes' => array_map(fn($r) => [
        'id'         => (int)$r['id'],
        'user_id'    => (int)$r['user_id'],
        'user'       => $r['user'],
        'note_text'  => $r['note_text'],
        'created_at' => $r['created_at'],
    ], $stmt->fetchAll()),
]);
