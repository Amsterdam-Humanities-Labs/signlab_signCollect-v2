<?php

// signcollect-lib's install-root resolver: sc_path(), sc_dir(), sc_root().
// Vendored shim - it finds /web/lib/paths.php, or falls back to /web.
require_once __DIR__ . '/../sc_paths.php';

/**
 * POST /menu_beta/php_api/lsm_video_upload.php
 *
 * Machine-to-machine endpoint to add a new LSM studio video.
 *
 *   - Saves the upload to /web/uploads/lsm/<gloss_id>_<sha-prefix>.<ext>
 *   - Inserts a matched_transcriptions row with:
 *       zOg             = 'lsm'
 *       m_transcription = <gloss_id>
 *       m_file          = stored filename (with extension)
 *       date            = today (YYYY-MM-DD)
 *       format          = lowercased extension (mp4 / webm / mov / …)
 *
 * Auth:  Authorization: Bearer <token>   (token from upload_tokens.php)
 * Body:  multipart/form-data
 *          file:     the video file
 *          gloss_id: integer, must exist in lsm_data
 *
 * Idempotency: re-uploading the same bytes for the same gloss_id produces
 * the same filename. If a matched_transcriptions row already exists for
 * that file + zOg='lsm', the existing row is returned instead of inserting
 * a duplicate.
 *
 * Response (200):
 *   { "ok": true, "id": 123456, "gloss_id": 42, "m_file": "42_b2a28de7.mp4",
 *     "url": "https://signcollect.nl/uploads/lsm/42_b2a28de7.mp4",
 *     "sha256": "...", "size": 12345, "created": true }
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/upload_tokens.php';

// ── Auth ────────────────────────────────────────────────────────────────────
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) || !upload_token_valid('lsm', $m[1])) {
    json_response(['error' => 'unauthorized'], 401);
}

// ── Method ──────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['error' => 'method_not_allowed'], 405);
}

// ── Inputs ──────────────────────────────────────────────────────────────────
$glossId = (int)($_POST['gloss_id'] ?? 0);
if ($glossId <= 0)                       json_response(['error' => 'invalid_gloss_id'], 400);
if (!isset($_FILES['file']))             json_response(['error' => 'no_file'], 400);
if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_response(['error' => 'upload_failed', 'code' => $_FILES['file']['error']], 400);
}

$pdo = db();

// Gloss must exist in lsm_data.
$g = $pdo->prepare("SELECT id FROM lsm_data WHERE id = ?");
$g->execute([$glossId]);
if (!$g->fetch()) json_response(['error' => 'gloss_not_found'], 404);

// ── Extension whitelist ─────────────────────────────────────────────────────
$allowedExts = ['mp4', 'webm', 'mov', 'm4v', 'mkv'];
$origName    = $_FILES['file']['name'] ?? '';
$ext         = strtolower(pathinfo($origName, PATHINFO_EXTENSION) ?: '');
if (!in_array($ext, $allowedExts, true)) {
    json_response(['error' => 'bad_extension', 'allowed' => $allowedExts], 400);
}

// ── Save file ───────────────────────────────────────────────────────────────
$tmp  = $_FILES['file']['tmp_name'];
$sha  = hash_file('sha256', $tmp);
if (!$sha) json_response(['error' => 'hash_failed'], 500);

$dir      = sc_path('uploads/lsm');
if (!is_dir($dir)) json_response(['error' => 'uploads_dir_missing', 'path' => $dir], 500);
$filename = $glossId . '_' . substr($sha, 0, 16) . '.' . $ext;
$dest     = $dir . '/' . $filename;

if (!is_file($dest)) {
    if (!move_uploaded_file($tmp, $dest)) {
        json_response(['error' => 'move_failed'], 500);
    }
}
$size = (int)filesize($dest);

// ── Insert (or return existing) matched_transcriptions row ──────────────────
$existing = $pdo->prepare(
    "SELECT id FROM matched_transcriptions
     WHERE zOg = 'lsm' AND m_transcription = ? AND m_file = ?
     LIMIT 1"
);
$existing->execute([(string)$glossId, $filename]);
$row = $existing->fetch();

$created = false;
if ($row) {
    $rowId = (int)$row['id'];
} else {
    $ins = $pdo->prepare(
        "INSERT INTO matched_transcriptions
            (zOg, m_transcription, m_file, date, format)
         VALUES ('lsm', ?, ?, ?, ?)"
    );
    $ins->execute([(string)$glossId, $filename, date('Y-m-d'), $ext]);
    $rowId   = (int)$pdo->lastInsertId();
    $created = true;
}

json_response([
    'ok'       => true,
    'id'       => $rowId,
    'gloss_id' => $glossId,
    'm_file'   => $filename,
    'url'      => 'https://signcollect.nl/uploads/lsm/' . $filename,
    'sha256'   => $sha,
    'size'     => $size,
    'created'  => $created,
]);
