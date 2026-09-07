<?php
/**
 * Thin client for the Signbank Global Signbank API.
 * Spec: https://signbank.github.io/Global-signbank/
 *
 * Auth: HTTP Bearer token via "Authorization: Bearer <api_key>" header.
 */

/**
 * Values that are in the api_key slot but are not a credential: the example
 * placeholder, and the inert string a demo host is provisioned with so that
 * signbank_config() does not throw on every page load (current_user.php
 * calls it) before anyone has supplied a key.
 */
function signbank_key_placeholders(): array {
    return ['', 'YOUR_BEARER_TOKEN_HERE', 'demo-instance-no-signbank-access'];
}

/**
 * The directory this install's Signbank connector owns: the runtime key, the
 * refresh state, and - on a host where the web user cannot write the docroot
 * root - the gloss dump itself. One directory, so one chown decides who may
 * refresh.
 */
function signbank_state_dir(): string {
    $cfg = signbank_config();
    return rtrim((string)($cfg['state_dir'] ?? '/web/signbank_data'), '/');
}

/**
 * The key an admin set in the interface, kept out of config.php so that
 * setting it needs write access to one small file rather than to a PHP file
 * inside the docroot. Named with a leading dot: apache denies dotfiles, the
 * same protection /web/.session_secret relies on.
 */
function signbank_key_path(): string {
    return signbank_state_dir() . '/.signbank_key';
}

function signbank_runtime_key(): ?string {
    $p = signbank_key_path();
    if (!is_readable($p)) return null;
    $v = trim((string)@file_get_contents($p));
    return $v === '' ? null : $v;
}

/** 'runtime' | 'config' | 'none' - where the key in use came from. */
function signbank_key_source(): string {
    try {
        $cfg = signbank_config();
    } catch (Throwable $e) {
        return 'none';
    }
    return (string)($cfg['key_source'] ?? 'none');
}

/**
 * Config, with the runtime key layered over the provisioned one.
 *
 * Two places may hold a key, in this order:
 *
 *   1. <state_dir>/.signbank_key - written by an admin on the Signbank page,
 *      or by scripts/host-config.sh at deploy time.
 *   2. config.php - gitignored upstream, provisioned per host.
 *
 * Neither is ever committed. A host with neither still boots: config.php
 * carries a placeholder, key_source reports 'none', and the connector page
 * says the connection is unconfigured instead of pretending otherwise.
 */
function signbank_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('signbank_sync/config.php is missing - copy config.example.php');
    }
    $cfg = require $path;
    if (!is_array($cfg)) {
        throw new RuntimeException('signbank_sync/config.php did not return an array');
    }

    // Resolved here rather than through signbank_state_dir(), which would
    // call back into this function before $cfg is cached.
    $keyFile = rtrim((string)($cfg['state_dir'] ?? '/web/signbank_data'), '/') . '/.signbank_key';
    $runtime = is_readable($keyFile) ? trim((string)@file_get_contents($keyFile)) : '';
    if ($runtime !== '' && !in_array($runtime, signbank_key_placeholders(), true)) {
        $cfg['api_key']    = $runtime;
        $cfg['key_source'] = 'runtime';
    } else {
        $cfg['key_source'] = in_array((string)($cfg['api_key'] ?? ''),
                                      signbank_key_placeholders(), true) ? 'none' : 'config';
    }

    if ($cfg['key_source'] === 'none' && ($cfg['api_key'] ?? '') === '') {
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

    // No key configured: fail here rather than sending the placeholder as a
    // bearer token and reporting Signbank's 403 as if the key were wrong.
    // The shape is the one every caller already handles.
    if (($cfg['key_source'] ?? 'none') === 'none') {
        return [
            'ok' => false, 'status' => 0, 'body' => null, 'raw' => '',
            'error' => 'no Signbank API key configured - set one on the Signbank page',
            'duration_ms' => 0, 'content_type' => '',
            'request' => ['method' => $method, 'url' => $url, 'headers' => [], 'payload' => $payload],
        ];
    }

    $authScheme = strtolower((string)($cfg['auth_scheme'] ?? 'bearer'));
    if ($authScheme === 'x-api-key') {
        $authHeader = 'X-API-Key: ' . $cfg['api_key'];
    } else {
        $authHeader = 'Authorization: Bearer ' . $cfg['api_key'];
    }
    $headers = [
        $authHeader,
        'Accept: application/json',
        // CRITICAL: without this, Signbank's Django 4.2.x parse_accept_lang_header(None)
        // raises TypeError and the view 500s before doing anything.
        'Accept-Language: en',
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

/** Pad two parallel sense arrays to the same length using empty strings. */
function signbank_align_sense_pair(array $nl, array $en): array {
    $nl = array_values(array_map('strval', $nl));
    $en = array_values(array_map('strval', $en));
    $max = max(count($nl), count($en), 1);
    while (count($nl) < $max) $nl[] = '';
    while (count($en) < $max) $en[] = '';
    return [$nl, $en];
}

/**
 * Builds the Signbank create-gloss payload from a /web/menu_beta form_data row.
 */
function signbank_build_create_payload(array $row, array $cfg): array {
    $glosNl = (string)($row['glos'] ?? '');
    $glosEn = (string)($row['glos_engels'] ?? '') ?: $glosNl;
    $sensesNl = signbank_decode_json_array($row['senses'] ?? null);
    $sensesEn = signbank_decode_json_array($row['sensesEngels'] ?? null);
    // Drop empty strings on each side first, then pad to equal length so Signbank's
    // "Sense arrays are not the same length" check passes.
    $sensesNl = array_values(array_filter(array_map('trim', $sensesNl), fn($s) => $s !== ''));
    $sensesEn = array_values(array_filter(array_map('trim', $sensesEn), fn($s) => $s !== ''));
    [$alignedNl, $alignedEn] = signbank_align_sense_pair($sensesNl, $sensesEn);

    return [
        'Dataset'                       => $cfg['dataset_acronym'],
        'Lemma ID Gloss (Dutch)'        => $glosNl,
        'Lemma ID Gloss (English)'      => $glosEn,
        'Annotation ID Gloss (Dutch)'   => $glosNl,
        'Annotation ID Gloss (English)' => $glosEn,
        'Senses (Dutch)'                => signbank_serialize_senses_aligned($alignedNl),
        'Senses (English)'              => signbank_serialize_senses_aligned($alignedEn),
    ];
}

/** Serialize an already-aligned sense list to Signbank's `[["a"],["b"]]` shape, preserving empty slots. */
function signbank_serialize_senses_aligned(array $senses): string {
    if (!$senses) return "[]";
    $rows = array_map(fn($s) => [(string)$s], $senses);
    return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

/**
 * Returns the dataset id + acronym for use against the connected
 * Signbank install, looked up from datasets.php by dataset code.
 *   ['id' => '2', 'acronym' => 'NGT']
 * Returns null when the dataset has no Signbank counterpart (id is null
 * in the registry) — callers should treat that as "no-op the sync".
 */
function signbank_dataset_info_for(string $code): ?array {
    require_once __DIR__ . '/../php_api/datasets.php';
    $reg = datasets_registry();
    if (!isset($reg[$code])) return null;
    if ($reg[$code]['signbank_dataset_id'] === null) return null;
    return [
        'id'      => (string)$reg[$code]['signbank_dataset_id'],
        'acronym' => (string)$reg[$code]['signbank_acronym'],
    ];
}
