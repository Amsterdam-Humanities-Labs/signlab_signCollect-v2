<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';

$session = require_session();

$pdo   = db();
$ds    = require_dataset($pdo, $session, $_GET);
$table = $ds['table'];

$themas = $pdo->query(
    "SELECT thema, COUNT(*) AS c FROM `$table`
     WHERE thema IS NOT NULL AND thema <> ''
     GROUP BY thema ORDER BY c DESC"
)->fetchAll();

$labels = $pdo->query("SELECT id, label, color FROM labels ORDER BY label")->fetchAll();

// Filter the owner dropdown to users who actually have access to the active
// dataset. Users without `allowed_datasets` (NULL) are treated as NGT-only
// because the migration backfilled every existing user to ["ngt"].
$userStmt = $pdo->prepare(
    "SELECT userId, user FROM users
     WHERE JSON_CONTAINS(COALESCE(allowed_datasets, JSON_ARRAY('ngt')), JSON_QUOTE(?), '$')
     ORDER BY user"
);
$userStmt->execute([$ds['code']]);
$users = $userStmt->fetchAll();

json_response([
    'themas' => array_map(fn($r) => $r['thema'], $themas),
    'labels' => $labels,
    'users'  => $users,
]);
