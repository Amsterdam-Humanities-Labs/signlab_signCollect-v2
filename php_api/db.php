<?php
require_once '/web/mysql_config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    global $servername, $username, $password, $database;
    $dsn = "mysql:host={$servername};dbname={$database};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

function parse_json_array($val): array {
    if ($val === null || $val === '') return [];
    if (is_array($val)) return $val;
    $decoded = json_decode($val, true);
    if (is_array($decoded)) return $decoded;
    // bare string fallback (legacy zelfopname)
    return [$val];
}
