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
    ];
}

function signbank_senses_keys(): array {
    return ['senses' => 'Senses (Dutch)', 'sensesEngels' => 'Senses (English)'];
}

function signbank_normalize_value(string $sourceField, $value): string {
    if ($value === null) return '';
    if (in_array($sourceField, ['RepeatedMovement', 'AlternatingMovement'], true)) {
        $v = strtolower(trim((string)$value));
        if ($v === 'yes' || $v === 'true' || $v === '1') return 'True';
        return 'False';
    }
    return trim((string)$value);
}

function signbank_build_update_payload(array $changedRow): array {
    // NOTE: Signbank's api_update_gloss does NOT accept Senses fields — they
    // are only settable on create. Auto-sync therefore only pushes the
    // lemma/annotation/phonology subset. To change senses post-create the
    // user must either re-broadcast or use a future sense-specific endpoint.
    $map = signbank_field_map();
    $out = [];
    foreach ($map as $src => $dst) {
        if (!array_key_exists($src, $changedRow)) continue;
        $v = signbank_normalize_value($src, $changedRow[$src]);
        if ($v !== '') $out[$dst] = $v;
    }
    return $out;
}

function signbank_get_connected_glossid(PDO $pdo, int $form_data_id): ?string {
    $stmt = $pdo->prepare("SELECT signbank FROM form_data WHERE id = ?");
    $stmt->execute([$form_data_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $sb = trim((string)($row['signbank'] ?? ''));
    return $sb === '' ? null : $sb;
}

function signbank_set_connection(PDO $pdo, int $form_data_id, ?string $glossid, string $logEntry): void {
    $stmt = $pdo->prepare(
        "UPDATE form_data
         SET signbank = ?,
             logboek  = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
         WHERE id = ?"
    );
    $stmt->execute([$glossid, $logEntry, $form_data_id]);
}

function signbank_auto_sync_fields(PDO $pdo, int $form_data_id, array $changed): ?array {
    $glossid = signbank_get_connected_glossid($pdo, $form_data_id);
    if ($glossid === null) return null;
    $payload = signbank_build_update_payload($changed);
    if (!$payload) return null;
    $cfg = signbank_config();
    $path = '/dictionary/api_update_gloss/' . rawurlencode($cfg['dataset_id']) . '/' . rawurlencode($glossid) . '/';
    $res = signbank_request('POST', $path, $payload);
    $res['fields_sent'] = array_keys($payload);
    $res['glossid']     = $glossid;
    return $res;
}

/**
 * Multipart upload of a local file as the gloss's center video on Signbank.
 * No-op (returns null) if the row isn't connected.
 */
function signbank_upload_video_for(PDO $pdo, int $form_data_id, string $localPath): ?array {
    $glossid = signbank_get_connected_glossid($pdo, $form_data_id);
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

    return [
        'ok'          => ($err === null && $status >= 200 && $status < 300),
        'status'      => $status,
        'body'        => $parsed ?: $body,
        'error'       => $err,
        'duration_ms' => (int)round((microtime(true) - $start) * 1000),
        'glossid'     => $glossid,
    ];
}
