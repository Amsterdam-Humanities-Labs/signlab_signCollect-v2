<?php
/**
 * POST { id, note_text }  →  append a note to a gloss in the active dataset.
 * Returns the newly-inserted row in the same shape as notes_list.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';

$session = require_session();

$body = json_body();
$id   = (int)($body['id'] ?? 0);
$text = trim((string)($body['note_text'] ?? ''));

if ($id <= 0)        json_response(['error' => 'invalid_id'], 400);
if ($text === '')    json_response(['error' => 'empty_note'], 400);
if (mb_strlen($text) > 5000) json_response(['error' => 'note_too_long'], 400);

$pdo = db();
$ds  = require_dataset($pdo, $session, $body);

// Verify the gloss exists in the active dataset's table — prevents the
// caller from leaving stranded notes if they fat-finger an id.
$table = $ds['table'];
$exists = $pdo->prepare("SELECT 1 FROM `$table` WHERE id = ? LIMIT 1");
$exists->execute([$id]);
if (!$exists->fetchColumn()) json_response(['error' => 'gloss_not_found'], 404);

$ins = $pdo->prepare(
    "INSERT INTO gloss_notes (dataset, gloss_id, user_id, note_text)
     VALUES (?, ?, ?, ?)"
);
$ins->execute([$ds['code'], $id, (int)$session['userId'], $text]);
$noteId = (int)$pdo->lastInsertId();

$sel = $pdo->prepare(
    "SELECT n.id, n.user_id, COALESCE(u.user, CONCAT('#', n.user_id)) AS user,
            n.note_text, n.created_at
     FROM gloss_notes n
     LEFT JOIN users u ON u.userId = n.user_id
     WHERE n.id = ?"
);
$sel->execute([$noteId]);
$row = $sel->fetch();

json_response([
    'id'         => (int)$row['id'],
    'user_id'    => (int)$row['user_id'],
    'user'       => $row['user'],
    'note_text'  => $row['note_text'],
    'created_at' => $row['created_at'],
]);
