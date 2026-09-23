<?php
/**
 * Let the logged-in user change their own password.
 *
 * POST JSON {current, new}. Only the caller's own row (userId from the
 * session, never from the request) is updated.
 *
 * Passwords are stored as plain text because login_sc.php compares
 * `pass = ?` in SQL. The new one is stored the same way so login keeps
 * working; moving to password_hash() is a separate task that must change
 * login_sc.php, users_api.php and this file together.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'method_not_allowed'], 405);
}

$session = require_session();
$userId  = (int)$session['userId'];

$body    = json_body();
$current = (string)($body['current'] ?? '');
$new     = (string)($body['new'] ?? '');

if ($current === '' || $new === '') json_response(['error' => 'missing_fields'], 400);
if (mb_strlen($new) < 8)            json_response(['error' => 'too_short'], 400);
if (mb_strlen($new) > 255)          json_response(['error' => 'too_long'], 400);
if ($new === $current)              json_response(['error' => 'same_as_old'], 400);

$pdo = db();

// Same comparison as login_sc.php (in SQL, same collation), so "current
// password is right" here means exactly "this password logs you in".
// 403, not 401: api.js turns a 401 into a redirect to the login page.
$check = $pdo->prepare("SELECT 1 FROM users WHERE userId = ? AND pass = ?");
$check->execute([$userId, $current]);
if (!$check->fetchColumn()) json_response(['error' => 'wrong_current'], 403);

$upd = $pdo->prepare("UPDATE users SET pass = ? WHERE userId = ?");
$upd->execute([$new, $userId]);

json_response(['ok' => true]);
