<?php

/**
 * Shared secret used to sign the session cookie. Absent on a host that has
 * not been migrated yet, in which case signatures are not required - see
 * current_session(). Kept outside the docroot rules by name: apache denies
 * dotfiles, so /web/.session_secret is never served.
 */
function session_secret(): ?string {
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;
    $path = '/web/.session_secret';
    if (is_readable($path)) {
        $v = trim((string)file_get_contents($path));
        if ($v !== '') $cached = $v;
    }
    return $cached;
}

/** HMAC over the identity fields only. Role is deliberately not signed - it
 *  is never taken from the cookie, so signing it would imply it is trusted. */
function session_signature(array $c, string $secret): string {
    return hash_hmac('sha256', implode('|', [
        (string)($c['userId']    ?? ''),
        (string)($c['username']  ?? ''),
        (string)($c['expiresAt'] ?? ''),
    ]), $secret);
}

/**
 * The caller's session, or null.
 *
 * sessionObject is client-side JSON, so nothing in it is trusted on its own:
 *
 *  - When a secret is configured the cookie must carry a valid HMAC, which
 *    is what stops a caller inventing someone else's userId.
 *  - role and username always come from the database, never from the cookie.
 *    Before this, writing {"role":"admin"} by hand was enough to be an admin.
 *  - A blocked or deleted user has no session, however good their cookie is.
 */
function current_session(): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;

    if (!isset($_COOKIE['sessionObject'])) return null;
    $parsed = json_decode($_COOKIE['sessionObject'], true);
    if (!is_array($parsed) || empty($parsed['userId'])) return null;

    if (!empty($parsed['expiresAt'])) {
        $exp = strtotime($parsed['expiresAt']);
        if ($exp !== false && $exp < time()) return null;
    }

    // Signature, when this host has been given a secret. A host that has not
    // been migrated keeps working: identity is still only as good as the
    // cookie there, but role forgery is closed either way by the lookup below.
    $secret = session_secret();
    if ($secret !== null) {
        $sig = (string)($parsed['sig'] ?? '');
        if ($sig === '' || !hash_equals(session_signature($parsed, $secret), $sig)) return null;
    }

    require_once __DIR__ . '/db.php';
    try {
        $stmt = db()->prepare("SELECT user, role, blocked FROM users WHERE userId = ?");
        $stmt->execute([(int)$parsed['userId']]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) return null;
    if ((int)($row['blocked'] ?? 0) === 1) return null;

    $cached = [
        'userId'   => (string)$parsed['userId'],
        'username' => (string)$row['user'],
        'role'     => (string)($row['role'] ?: 'user'),
    ];
    return $cached;
}

/** True when the caller is an authenticated admin. */
function session_is_admin(): bool {
    $s = current_session();
    return $s !== null && ($s['role'] ?? 'user') === 'admin';
}

function require_session(): array {
    $s = current_session();
    if ($s === null) {
        require_once __DIR__ . '/db.php';
        json_response(['error' => 'unauthorized'], 401);
    }
    return $s;
}

/**
 * Resolve the active dataset for the current request.
 *  - Looks up the caller's allowed_datasets/default_dataset in `users`.
 *  - Reads the requested dataset code from $body['dataset'] / $_GET['dataset'].
 *  - On forbidden_dataset, emits 403 and exits.
 *
 * Returns the registry entry array (never false).
 */
function require_dataset(PDO $pdo, array $session, $bodyOrNull = null): array {
    require_once __DIR__ . '/datasets.php';

    $stmt = $pdo->prepare("SELECT default_dataset, allowed_datasets FROM users WHERE userId = ?");
    $stmt->execute([(int)$session['userId']]);
    $row = $stmt->fetch() ?: [];

    $default = $row['default_dataset'] ?? dataset_default_code();
    $allowed = [];
    if (!empty($row['allowed_datasets'])) {
        $d = json_decode($row['allowed_datasets'], true);
        if (is_array($d)) $allowed = array_values(array_filter($d, 'is_string'));
    }
    if (!$allowed) $allowed = [$default ?: dataset_default_code()];

    $requested = null;
    if (is_array($bodyOrNull) && isset($bodyOrNull['dataset'])) $requested = (string)$bodyOrNull['dataset'];
    elseif (isset($_GET['dataset']))                            $requested = (string)$_GET['dataset'];

    // null requested → pick the user's default if it's in their allowed list,
    // otherwise the first allowed dataset.
    if ($requested === null || $requested === '') {
        $requested = in_array($default, $allowed, true) ? $default : $allowed[0];
    }

    $ds = dataset_resolve($requested, $allowed);
    if ($ds === false) json_response(['error' => 'forbidden_dataset', 'requested' => $requested], 403);
    return $ds;
}

/** The two NGT sub-views a user can be granted. Order = display order. */
function context_codes(): array { return ['signio', 'signbank']; }

/**
 * Read a user's allowed contexts + default from the `users` row.
 * A NULL / empty / unparsable allowed_contexts means "both" so that a row
 * that predates the migration keeps working unchanged.
 * Returns ['allowed' => string[], 'default' => string].
 */
function user_context_access(PDO $pdo, int $userId): array {
    $allowed = context_codes();
    $default = 'signio';
    try {
        $stmt = $pdo->prepare("SELECT default_context, allowed_contexts FROM users WHERE userId = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];
        if (!empty($row['allowed_contexts'])) {
            $d = json_decode($row['allowed_contexts'], true);
            if (is_array($d)) {
                $d = array_values(array_intersect(context_codes(), array_filter($d, 'is_string')));
                if ($d) $allowed = $d;
            }
        }
        if (in_array($row['default_context'] ?? null, context_codes(), true)) {
            $default = $row['default_context'];
        }
    } catch (Throwable $e) {
        // pre-migration table: keep defaults
    }
    if (!in_array($default, $allowed, true)) $default = $allowed[0];
    return ['allowed' => $allowed, 'default' => $default];
}

/**
 * Resolve the active Signio/Signbank context for the current request.
 *  - Reads the requested code from $body['context'] / $_GET['context'].
 *  - Empty request → the user's default (already clamped to allowed).
 *  - A code the user may not use → 403 forbidden_context and exit.
 * Never silently rewrites a forbidden request to an allowed one.
 */
function require_context(PDO $pdo, array $session, $bodyOrNull = null): string {
    $access = user_context_access($pdo, (int)$session['userId']);

    $requested = null;
    if (is_array($bodyOrNull) && isset($bodyOrNull['context'])) $requested = (string)$bodyOrNull['context'];
    elseif (isset($_GET['context']))                            $requested = (string)$_GET['context'];

    if ($requested === null || $requested === '') return $access['default'];
    if (!in_array($requested, $access['allowed'], true)) {
        json_response(['error' => 'forbidden_context', 'requested' => $requested], 403);
    }
    return $requested;
}
