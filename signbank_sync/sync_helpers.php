<?php
/**
 * Helpers shared by the broadcast / delete / auto-sync endpoints.
 *
 * Connection key:  form_data.signbank (varchar)  →  Signbank glossid.
 * When that column is set on a row, the row is "connected" to Signbank and
 * any field changes are pushed automatically.
 */

require_once __DIR__ . '/client.php';

/**
 * Map our form_data column → the Signbank field name expected by
 * /dictionary/api_update_gloss/{datasetid}/{glossid}/.
 */
function signbank_field_map(): array {
    return [
        'glos'                       => 'Annotation ID Gloss (Dutch)',
        'glos_engels'                => 'Annotation ID Gloss (English)',
        'Handeness'                  => 'Handedness',
        'strongHand'                 => 'Strong Hand',
        'weakHand'                   => 'Weak Hand',
        'HandshapeChange'            => 'Handshape Change',
        'RelationArticulators'       => 'Relation Between Articulators',
        'handLocation'               => 'Location',
        'ContactType'                => 'Contact Type',
        'MovementShape'              => 'Movement Shape',
        'MovementDirection'          => 'Movement Direction',
        'RepeatedMovement'           => 'Repeated Movement',
        'AlternatingMovement'        => 'Alternating Movement',
        'relativeOrienationMovement' => 'Relative Orientation: Movement',
        'relativeOrienationLocation' => 'Relative Orientation: Location',
        'orientationChange'          => 'Orientation Change',
        'virtualObjectt'             => 'Virtual Object',
        'phonologyOther'             => 'Phonology Other',
        'mouthGesture'               => 'Mouth Gesture',
        'mouthing'                   => 'Mouthing',
        'phoneticVariation'          => 'Phonetic Variation',
        // "Gecontroleerd" (fonologie_fase1='1' = klaar) controls public
        // visibility on Signbank's web dictionary. Toggling klaar also
        // toggles inWeb so the gloss page stops being "not available
        // for public viewing."
        'fonologie_fase1'            => 'In The Web Dictionary',
    ];
}

function signbank_senses_keys(): array {
    return ['senses' => 'Senses (Dutch)', 'sensesEngels' => 'Senses (English)'];
}

/**
 * Phonology dropdown fields: form_data stores the FieldChoice machine_value
 * (e.g. "2") but Signbank's api_update_gloss expects the human label
 * (e.g. "1", "2a", "5w"). We translate via the same `phonology_options.json`
 * the UI uses.
 *
 * Boolean fields (RepeatedMovement, AlternatingMovement) are intentionally
 * NOT in this list — their FieldChoice options are also "True"/"False"
 * strings, which signbank_normalize_value already produces.
 */
function signbank_phonology_dropdown_fields(): array {
    return [
        'Handeness', 'strongHand', 'weakHand', 'HandshapeChange',
        'RelationArticulators', 'handLocation', 'ContactType',
        'MovementShape', 'MovementDirection',
        'relativeOrienationMovement', 'relativeOrienationLocation',
        'orientationChange',
    ];
}

/** Returns the cached map: fieldName → [machine_value => human_label]. */
function signbank_phonology_translation(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    $path = __DIR__ . '/../data/phonology_options.json';
    if (!is_file($path)) return $cache;
    $opts = json_decode(file_get_contents($path), true);
    if (!is_array($opts)) return $cache;

    foreach ($opts as $field => $rows) {
        if (!is_array($rows)) continue;
        $cache[$field] = [];
        foreach ($rows as $row) {
            if (!isset($row['value'])) continue;
            $label = $row['EN'] ?? $row['NL'] ?? null;  // prefer English; same value usually
            if ($label === null) continue;
            $cache[$field][(string)$row['value']] = (string)$label;
        }
    }
    return $cache;
}

function signbank_normalize_value(string $sourceField, $value): string {
    if ($value === null) return '';
    if (in_array($sourceField, ['RepeatedMovement', 'AlternatingMovement', 'fonologie_fase1'], true)) {
        $v = strtolower(trim((string)$value));
        if ($v === 'yes' || $v === 'true' || $v === '1') return 'True';
        return 'False';
    }
    $v = trim((string)$value);
    if ($v === '') return '';
    if (in_array($sourceField, signbank_phonology_dropdown_fields(), true)) {
        $map = signbank_phonology_translation()[$sourceField] ?? null;
        if ($map !== null) {
            // Only translate when the value looks like a numeric machine_value AND
            // is in the map. Anything else is passed through (covers legacy rows
            // that already store human labels).
            if (ctype_digit($v) && isset($map[$v])) {
                return $map[$v];
            }
        }
    }
    return $v;
}

function signbank_build_update_payload(array $changedRow): array {
    // Build the lemma/annotation/phonology subset.
    $map = signbank_field_map();
    $out = [];
    foreach ($map as $src => $dst) {
        if (!array_key_exists($src, $changedRow)) continue;
        $v = signbank_normalize_value($src, $changedRow[$src]);
        if ($v !== '') $out[$dst] = $v;
    }
    // Senses go in a single combined field "Senses" with a stringified
    // dict-of-list-of-lists: '{"en":[["sense1"],["sense2"]],"nl":[["..."]]}'.
    // The view accepts that (see check_fields_can_be_updated → "Senses").
    if (array_key_exists('senses', $changedRow) || array_key_exists('sensesEngels', $changedRow)) {
        $nl = signbank_decode_json_array($changedRow['senses'] ?? null);
        $en = signbank_decode_json_array($changedRow['sensesEngels'] ?? null);
        $nl = array_values(array_filter(array_map('trim', $nl), fn($s) => $s !== ''));
        $en = array_values(array_filter(array_map('trim', $en), fn($s) => $s !== ''));
        [$alignedNl, $alignedEn] = signbank_align_sense_pair($nl, $en);
        if ($alignedNl || $alignedEn) {
            $dict = [
                'en' => array_map(fn($s) => [$s], $alignedEn),
                'nl' => array_map(fn($s) => [$s], $alignedNl),
            ];
            $out['Senses'] = json_encode($dict, JSON_UNESCAPED_UNICODE);
        }
    }
    return $out;
}

function signbank_get_connected_glossid(PDO $pdo, int $form_data_id, string $datasetCode = 'ngt'): ?string {
    require_once __DIR__ . '/../php_api/datasets.php';
    $table = dataset_table($datasetCode);
    $stmt = $pdo->prepare("SELECT signbank FROM `$table` WHERE id = ?");
    $stmt->execute([$form_data_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $sb = trim((string)($row['signbank'] ?? ''));
    return $sb === '' ? null : $sb;
}

function signbank_set_connection(PDO $pdo, int $form_data_id, ?string $glossid, string $logEntry, string $datasetCode = 'ngt'): void {
    require_once __DIR__ . '/../php_api/datasets.php';
    $table = dataset_table($datasetCode);
    $stmt = $pdo->prepare(
        "UPDATE `$table`
         SET signbank = ?,
             logboek  = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
         WHERE id = ?"
    );
    $stmt->execute([$glossid, $logEntry, $form_data_id]);
}

/**
 * Reverse of signbank_normalize_value: turn a human-readable Signbank label
 * back into the FieldChoice machine_value our form_data stores. Returns the
 * label unchanged for free-text fields and for labels that aren't in the JSON.
 */
function signbank_label_to_machine_value(string $sourceField, string $humanLabel): string {
    static $reverse = null;
    if ($reverse === null) {
        $reverse = [];
        $path = __DIR__ . '/../data/phonology_options.json';
        if (is_file($path)) {
            $opts = json_decode(file_get_contents($path), true) ?: [];
            foreach ($opts as $field => $rows) {
                if (!is_array($rows)) continue;
                foreach ($rows as $row) {
                    foreach (['EN', 'NL'] as $variant) {
                        if (isset($row[$variant])) {
                            $reverse[$field][$row[$variant]] = (string)$row['value'];
                        }
                    }
                }
            }
        }
    }
    if (!in_array($sourceField, signbank_phonology_dropdown_fields(), true)) return $humanLabel;
    return $reverse[$sourceField][$humanLabel] ?? $humanLabel;
}

function signbank_auto_sync_fields(PDO $pdo, int $form_data_id, array $changed, string $datasetCode = 'ngt'): ?array {
    $sb = signbank_dataset_info_for($datasetCode);
    if ($sb === null) return null;
    $glossid = signbank_get_connected_glossid($pdo, $form_data_id, $datasetCode);
    if ($glossid === null) return null;
    $payload = signbank_build_update_payload($changed);
    if (!$payload) return null;
    $path = '/dictionary/api_update_gloss/' . rawurlencode($sb['id']) . '/' . rawurlencode($glossid) . '/';
    $res = signbank_request('POST', $path, $payload);
    $res['fields_sent'] = array_keys($payload);
    $res['glossid']     = $glossid;
    return $res;
}

/**
 * Multipart upload of a local file as the gloss's center video on Signbank.
 * No-op (returns null) if the row isn't connected.
 */
function signbank_upload_video_for(PDO $pdo, int $form_data_id, string $localPath, string $datasetCode = 'ngt'): ?array {
    $sb = signbank_dataset_info_for($datasetCode);
    if ($sb === null) return null;
    $glossid = signbank_get_connected_glossid($pdo, $form_data_id, $datasetCode);
    if ($glossid === null) return null;
    if (!is_file($localPath)) return ['ok' => false, 'error' => 'local file missing', 'path' => $localPath];

    $cfg = signbank_config();
    $url = rtrim($cfg['base_url'], '/') . '/dictionary/api_update_gloss/' . rawurlencode($glossid) . '/video';

    $authScheme = strtolower((string)($cfg['auth_scheme'] ?? 'bearer'));
    $authHeader = $authScheme === 'x-api-key'
        ? 'X-API-Key: ' . $cfg['api_key']
        : 'Authorization: Bearer ' . $cfg['api_key'];

    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'file' => new CURLFile($localPath, mime_content_type($localPath) ?: 'application/octet-stream', basename($localPath)),
    ]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$authHeader, 'Accept: application/json', 'Accept-Language: en']);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)($cfg['timeout_seconds'] ?? 60));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch) ?: null;
    curl_close($ch);
    $parsed = $body !== false ? json_decode($body, true) : null;
    // If response wasn't JSON (e.g. Django DEBUG traceback HTML), extract
    // the title/h1 so the modal can show a meaningful one-liner.
    if (!is_array($parsed) && is_string($body) && $body !== '') {
        $parsed = signbank_extract_html_error($body);
    }

    return [
        'ok'          => ($err === null && $status >= 200 && $status < 300),
        'status'      => $status,
        'body'        => $parsed ?: $body,
        'error'       => $err,
        'duration_ms' => (int)round((microtime(true) - $start) * 1000),
        'glossid'     => $glossid,
    ];
}
