<?php

function current_session(): ?array {
    if (!isset($_COOKIE['sessionObject'])) return null;
    $raw = $_COOKIE['sessionObject'];
    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || empty($parsed['userId'])) return null;
    if (!empty($parsed['expiresAt'])) {
        $exp = strtotime($parsed['expiresAt']);
        if ($exp !== false && $exp < time()) return null;
    }
    return [
        'userId'   => (string)$parsed['userId'],
        'username' => $parsed['username'] ?? '',
        'role'     => $parsed['role'] ?? 'user',
    ];
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
