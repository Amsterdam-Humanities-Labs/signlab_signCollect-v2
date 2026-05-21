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

$users = $pdo->query("SELECT userId, user FROM users ORDER BY user")->fetchAll();

json_response([
    'themas' => array_map(fn($r) => $r['thema'], $themas),
    'labels' => $labels,
    'users'  => $users,
]);
