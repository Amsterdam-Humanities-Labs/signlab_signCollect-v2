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

// Labels are scoped per dataset — only show labels belonging to the active
// dataset so LSM users don't see NGT labels (and vice versa).
$labelStmt = $pdo->prepare("SELECT id, label, color FROM labels WHERE dataset = ? ORDER BY label");
$labelStmt->execute([$ds['code']]);
$labels = $labelStmt->fetchAll();

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
