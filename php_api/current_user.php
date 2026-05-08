<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$s = require_session();

// Look up persisted user prefs (gracefully tolerates an unmigrated `users` table).
try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT default_context FROM users WHERE userId = ?");
    $stmt->execute([(int)$s['userId']]);
    $row = $stmt->fetch();
    $s['defaultContext'] = ($row && in_array($row['default_context'], ['signio', 'signbank'], true))
        ? $row['default_context']
        : 'signio';
} catch (Throwable $e) {
    $s['defaultContext'] = 'signio';
}

json_response($s);
