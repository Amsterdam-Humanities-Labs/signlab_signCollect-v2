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

// `users`: full list — used to resolve userId → username when rendering
// owner pills on a row. A row's `wie` may reference users outside the
// active dataset (e.g. an LSM gloss owned by gomer, who's an NGT admin);
// we still need to show their name, not the raw id.
$users = $pdo->query("SELECT userId, user FROM users ORDER BY user")->fetchAll();

// `users_for_dataset`: subset that actually has access to the active
// dataset. This populates the "Eigenaar" filter dropdown so LSM users
// don't see NGT-only people in the picker. NULL allowed_datasets is
// treated as ["ngt"] (the migration backfilled every pre-LSM user there).
$dropdownStmt = $pdo->prepare(
    "SELECT userId, user FROM users
     WHERE JSON_CONTAINS(COALESCE(allowed_datasets, JSON_ARRAY('ngt')), JSON_QUOTE(?), '$')
     ORDER BY user"
);
$dropdownStmt->execute([$ds['code']]);
$usersForDataset = $dropdownStmt->fetchAll();

json_response([
    'themas'            => array_map(fn($r) => $r['thema'], $themas),
    'labels'            => $labels,
    'users'             => $users,
    'users_for_dataset' => $usersForDataset,
]);
