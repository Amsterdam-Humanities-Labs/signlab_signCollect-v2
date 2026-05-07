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
