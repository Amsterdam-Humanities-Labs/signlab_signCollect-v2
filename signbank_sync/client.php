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
 * Performs a request to a Signbank endpoint via libcurl.
 * Auth: `X-API-Key` header (Bearer is documented in the OpenAPI spec but doesn't
 * work against the live signbank.cls.ru.nl instance — verified empirically).
 *
 * Returns ['ok','status','body','raw','error','duration_ms','content_type','request']
 */
function signbank_request(string $method, string $path, ?array $payload = null): array {
    $cfg = signbank_config();
    $url = rtrim($cfg['base_url'], '/') . $path;
    $start = microtime(true);

    $authScheme = strtolower((string)($cfg['auth_scheme'] ?? 'bearer'));
    if ($authScheme === 'x-api-key') {
        $authHeader = 'X-API-Key: ' . $cfg['api_key'];
    } else {
        $authHeader = 'Authorization: Bearer ' . $cfg['api_key'];
    }
    $headers = [
        $authHeader,
        'Accept: application/json',
    ];
    if ($payload !== null) $headers[] = 'Content-Type: application/json';

    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => (int)($cfg['timeout_seconds'] ?? 30),
        CURLOPT_HEADER         => true,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($method === 'POST') $opts[CURLOPT_POST] = true;
    }
    curl_setopt_array($ch, $opts);

    $raw     = curl_exec($ch);
    $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
    $err     = curl_error($ch) ?: null;
    curl_close($ch);

    $resBody = $raw !== false ? substr($raw, $headerSize) : '';
    $parsed  = null;
    if ($resBody !== '') {
        $parsed = json_decode($resBody, true);
        if ($parsed === null) $parsed = signbank_extract_html_error($resBody);
    }

    return [
        'ok'           => ($err === null && $status >= 200 && $status < 300),
        'status'       => $status,
        'body'         => $parsed,
        'raw'          => $resBody,
        'error'        => $err,
        'duration_ms'  => (int)round((microtime(true) - $start) * 1000),
        'content_type' => $contentType,
        'request'      => [
            'method'  => $method,
            'url'     => $url,
            'headers' => array_map(
                fn($h) => preg_replace('/(Authorization:\s*\S+\s+|X-API-Key:\s*)\S+/i', '$1***', $h),
                $headers
            ),
            'payload' => $payload,
        ],
    ];
}

function signbank_post_json(string $path, array $payload): array {
    return signbank_request('POST', $path, $payload);
}

function signbank_get(string $path): array {
    return signbank_request('GET', $path, null);
}

/**
 * Pulls the title / h1 / first paragraph out of a Django error HTML page so the
 * modal can show a meaningful one-liner instead of an 8 KB blob of HTML.
 */
function signbank_extract_html_error(string $html): array {
    $title = ''; $h1 = ''; $detail = '';
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $title = trim(preg_replace('~\s+~', ' ', strip_tags($m[1])));
    }
    if (preg_match_all('~<h1[^>]*>(.*?)</h1>~is', $html, $mm)) {
        $cleanH1s = array_filter(array_map(fn($s) => trim(strip_tags($s)), $mm[1]));
        // pick the most specific (often the second h1)
        $h1 = end($cleanH1s) ?: '';
    }
    if (preg_match('~<p[^>]*>(.*?)</p>~is', $html, $m)) {
        $detail = trim(preg_replace('~\s+~', ' ', strip_tags($m[1])));
    }
    return [
        '_html_error' => true,
        'title'  => $title,
        'h1'     => $h1,
        'detail' => $detail,
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
