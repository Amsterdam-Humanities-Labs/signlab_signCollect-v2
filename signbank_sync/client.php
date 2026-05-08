<?php
/**
 * Thin client for the Signbank Global Signbank API.
 * Spec: https://signbank.github.io/Global-signbank/
 *
 * Auth: HTTP Bearer token via "Authorization: Bearer <api_key>" header.
 */

function signbank_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('signbank_sync/config.php is missing - copy config.example.php');
    }
    $cfg = require $path;
    if (!is_array($cfg) || empty($cfg['api_key']) || $cfg['api_key'] === 'YOUR_BEARER_TOKEN_HERE') {
        throw new RuntimeException('signbank_sync/config.php is missing api_key');
    }
    return $cfg;
}

/**
 * Performs a JSON POST to a Signbank endpoint via libcurl.
 * Returns ['ok' => bool, 'status' => int, 'body' => mixed, 'error' => ?string].
 */
function signbank_post_json(string $path, array $payload): array {
    $cfg = signbank_config();
    $url = rtrim($cfg['base_url'], '/') . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => (int)($cfg['timeout_seconds'] ?? 30),
    ]);

    $resBody = curl_exec($ch);
    $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch) ?: null;
    curl_close($ch);

    $parsed = $resBody !== false ? json_decode($resBody, true) : null;
    if ($parsed === null && $resBody !== false && $resBody !== '') $parsed = $resBody;

    return [
        'ok'     => ($err === null && $status >= 200 && $status < 300),
        'status' => $status,
        'body'   => $parsed,
        'error'  => $err,
    ];
}

function signbank_decode_json_array($raw): array {
    if ($raw === null || $raw === '') return [];
    if (is_array($raw)) return $raw;
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}

function signbank_format_senses(array $senses): string {
    $clean = array_values(array_filter(array_map('trim', $senses), fn($s) => $s !== ''));
    if (!$clean) return "[]";
    $rows = array_map(fn($s) => [$s], $clean);
    return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

/**
 * Builds the Signbank create-gloss payload from a /web/menu_beta form_data row.
 */
function signbank_build_create_payload(array $row, array $cfg): array {
    $glosNl = (string)($row['glos'] ?? '');
    $glosEn = (string)($row['glos_engels'] ?? '') ?: $glosNl;
    $sensesNl = signbank_decode_json_array($row['senses'] ?? null);
    $sensesEn = signbank_decode_json_array($row['sensesEngels'] ?? null);

    return [
        'Dataset'                       => $cfg['dataset_acronym'],
        'Lemma ID Gloss (Dutch)'        => $glosNl,
        'Lemma ID Gloss (English)'      => $glosEn,
        'Annotation ID Gloss (Dutch)'   => $glosNl,
        'Annotation ID Gloss (English)' => $glosEn,
        'Senses (Dutch)'                => signbank_format_senses($sensesNl),
        'Senses (English)'              => signbank_format_senses($sensesEn),
    ];
}
