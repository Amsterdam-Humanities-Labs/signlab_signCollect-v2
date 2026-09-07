<?php
/**
 * Read side of the activity log — the data behind menu_beta/activity.html.
 *
 * Admin-only. `activity_log` records which member of staff opened which page
 * and when, so it is personal data about colleagues: everything here goes
 * through the real session layer. require_session() rejects an absent,
 * expired, unsigned or blocked-user cookie; session_is_admin() reads the role
 * out of the `users` table, never out of the cookie. This is the same shape as
 * users_api.php's requireAdmin(), which is the pattern for admin endpoints in
 * this codebase.
 *
 * GET parameters (all optional):
 *   from, to    YYYY-MM-DD, inclusive. Default: the last 30 days.
 *   userId      restrict the event list to one user. 0/absent = everyone.
 *   page        1-based page of the event list.
 *   pageSize    1..200, default 50.
 *
 * Every query is bounded by the [from, to] window, which is what keeps this
 * off a table scan as activity_log grows:
 *
 *   - with a userId filter the WHERE is (userId = ?, visited_at range), which
 *     is exactly idx_user_visited (userId, visited_at) — and that index also
 *     supplies the ORDER BY, so there is no filesort;
 *   - without one it is a range on visited_at alone, which is idx_visited_at,
 *     scanned backwards for the newest-first ordering.
 *
 * The window is never open-ended: an absent `from` means 30 days ago, not the
 * beginning of the table, so there is no request shape that asks MySQL to read
 * every row. The UI shows the window in two date fields, so the bound is
 * visible rather than a silent cap.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

require_session();                                    // 401 without a valid session
if (!session_is_admin()) {
    json_response(['error' => 'forbidden', 'message' => 'admin role required'], 403);
}

/** A YYYY-MM-DD request parameter, or $fallback when absent/malformed. */
function activity_day($raw, string $fallback): string {
    $v = trim((string)$raw);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $v;
    }
    return $fallback;
}

$WINDOW_DAYS = 30;
$defaultTo   = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime("-{$WINDOW_DAYS} days"));

$from = activity_day($_GET['from'] ?? null, $defaultFrom);
$to   = activity_day($_GET['to']   ?? null, $defaultTo);
if ($from > $to) { [$from, $to] = [$to, $from]; }

// Half-open interval so the `to` day is included whatever the time of day is.
$fromTs = $from . ' 00:00:00';
$toTs   = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));

$userId   = (int)($_GET['userId'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$pageSize = (int)($_GET['pageSize'] ?? 50);
if ($pageSize < 1)   $pageSize = 50;
if ($pageSize > 200) $pageSize = 200;

$pdo = db();

// Event-list predicate. Ordered so the userId column comes first: that is the
// leading column of idx_user_visited.
$where = 'a.visited_at >= ? AND a.visited_at < ?';
$args  = [$fromTs, $toTs];
if ($userId > 0) {
    $where = 'a.userId = ? AND ' . $where;
    $args  = [$userId, $fromTs, $toTs];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM activity_log a WHERE $where");
$countStmt->execute($args);
$total = (int)$countStmt->fetch()['c'];

$pages  = $pageSize > 0 ? (int)ceil($total / $pageSize) : 1;
if ($pages < 1) $pages = 1;
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $pageSize;

// LIMIT/OFFSET are interpolated rather than bound: both are integers this
// script produced itself, and native prepares refuse a string there.
$listStmt = $pdo->prepare(
    "SELECT a.id, a.userId, a.page, a.visited_at
       FROM activity_log a
      WHERE $where
      ORDER BY a.visited_at DESC, a.id DESC
      LIMIT $pageSize OFFSET $offset"
);
$listStmt->execute($args);
$events = $listStmt->fetchAll();

/**
 * Per-user aggregate for the window. Deliberately NOT narrowed by the userId
 * filter: this doubles as the user picker and as the "who has been around at
 * all" overview, so it must keep listing everyone while one user's events are
 * on screen.
 *
 * Driven from `users` (a handful of rows) into activity_log, so each user
 * costs one (userId, visited_at) range on idx_user_visited rather than the
 * whole log being read and grouped.
 */
$usersStmt = $pdo->prepare(
    "SELECT u.userId, u.user, u.role, u.blocked, u.last_login, u.last_page,
            COUNT(a.id)        AS visits,
            MAX(a.visited_at)  AS last_seen
       FROM users u
       LEFT JOIN activity_log a
              ON a.userId = u.userId
             AND a.visited_at >= ? AND a.visited_at < ?
      GROUP BY u.userId, u.user, u.role, u.blocked, u.last_login, u.last_page
      ORDER BY (last_seen IS NULL) ASC, last_seen DESC, u.user ASC"
);
$usersStmt->execute([$fromTs, $toTs]);
$users = [];
$nameById = [];
foreach ($usersStmt->fetchAll() as $u) {
    $nameById[(int)$u['userId']] = (string)$u['user'];
    $users[] = [
        'userId'     => (int)$u['userId'],
        'user'       => (string)$u['user'],
        'role'       => (string)($u['role'] ?: 'user'),
        'blocked'    => (int)($u['blocked'] ?? 0) === 1,
        'last_login' => $u['last_login'],
        'last_page'  => $u['last_page'],
        'visits'     => (int)$u['visits'],
        'last_seen'  => $u['last_seen'],
    ];
}

// Which pages the window's traffic actually went to. Same predicate as the
// event list, so it follows the user filter.
$topStmt = $pdo->prepare(
    "SELECT a.page, COUNT(*) AS visits, MAX(a.visited_at) AS last_visit
       FROM activity_log a
      WHERE $where
      GROUP BY a.page
      ORDER BY visits DESC, a.page ASC
      LIMIT 10"
);
$topStmt->execute($args);
$topPages = array_map(fn($r) => [
    'page'       => (string)$r['page'],
    'visits'     => (int)$r['visits'],
    'last_visit' => $r['last_visit'],
], $topStmt->fetchAll());

json_response([
    'window'   => ['from' => $from, 'to' => $to],
    'filter'   => ['userId' => $userId > 0 ? $userId : null],
    'page'     => $page,
    'pageSize' => $pageSize,
    'total'    => $total,
    'pages'    => $pages,
    'rows'     => array_map(fn($e) => [
        'id'         => (int)$e['id'],
        'userId'     => (int)$e['userId'],
        // A log row can outlive the account that wrote it; say so rather than
        // rendering a blank cell.
        'username'   => $nameById[(int)$e['userId']] ?? null,
        'page'       => (string)$e['page'],
        'visited_at' => $e['visited_at'],
    ], $events),
    'users'    => $users,
    'topPages' => $topPages,
]);
