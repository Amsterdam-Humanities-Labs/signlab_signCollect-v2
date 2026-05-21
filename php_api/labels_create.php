<?php
/**
 * POST { label, color }  →  Create a new label scoped to the active dataset.
 *
 *   - 200: { id, label, color, dataset }     newly inserted (or already-exists)
 *   - 400: { error: 'invalid_label' }        empty / too long label
 *   - 401: { error: 'unauthorized' }
 *   - 403: { error: 'forbidden_dataset' }
 *
 * If a label with the same name already exists for the active dataset,
 * the existing row is returned (idempotent) instead of inserting a
 * duplicate.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';

$session = require_session();

$body  = json_body();
$label = trim((string)($body['label'] ?? ''));
$color = trim((string)($body['color'] ?? ''));

if ($label === '' || mb_strlen($label) > 255) {
    json_response(['error' => 'invalid_label'], 400);
}
// Allow a #RRGGBB or short #RGB color, else fall back to a default.
if (!preg_match('/^#[0-9a-f]{3,6}$/i', $color)) $color = '#888888';

$pdo = db();
$ds  = require_dataset($pdo, $session, $body);

// Idempotency: same dataset + same label (case-insensitive) → return existing.
$stmt = $pdo->prepare(
    "SELECT id, label, color FROM labels
     WHERE dataset = ? AND LOWER(label) = LOWER(?)
     LIMIT 1"
);
$stmt->execute([$ds['code'], $label]);
$existing = $stmt->fetch();
if ($existing) {
    json_response([
        'id'      => (int)$existing['id'],
        'label'   => $existing['label'],
        'color'   => $existing['color'],
        'dataset' => $ds['code'],
        'created' => false,
    ]);
}

$ins = $pdo->prepare("INSERT INTO labels (label, color, dataset) VALUES (?, ?, ?)");
$ins->execute([$label, $color, $ds['code']]);

json_response([
    'id'      => (int)$pdo->lastInsertId(),
    'label'   => $label,
    'color'   => $color,
    'dataset' => $ds['code'],
    'created' => true,
]);
