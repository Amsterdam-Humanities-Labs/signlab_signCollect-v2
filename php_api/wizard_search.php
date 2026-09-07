<?php

// signcollect-lib's install-root resolver: sc_path(), sc_dir(), sc_root().
// Vendored shim - it finds /web/lib/paths.php, or falls back to /web.
require_once __DIR__ . '/../sc_paths.php';

/**
 * Glos Wizard search - "does this sign already exist?", asked of both halves
 * of the collection at once.
 *
 * Ported from menu_old/checkGlos.php. Same two sources and the same match
 * rules: a substring of the gloss or of any sense, Dutch or English, in the
 * local gloss table and in the Signbank ECV dump. Differences from the
 * original, all deliberate:
 *
 *   - the local half runs one query over the active dataset's table instead
 *     of hardcoding form_data, so LSM users search their own collection;
 *   - the ECV half walks the dump once instead of four times, collecting
 *     every reason a gloss matched rather than emitting it once per pass and
 *     de-duplicating afterwards;
 *   - a missing ECV dump is reported as `ecv: false` rather than warning into
 *     the JSON body. The local results are still returned.
 *
 * GET ?q=<term>[&limit=]  ->  { query, ecv, truncated, results: [...] }
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';
require_once __DIR__ . '/signbank_ecv.php';

$session = require_session();

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') json_response(['error' => 'query_required'], 400);

// Cap the result set: a one-letter query matches thousands of ECV entries and
// the browser only ever renders a screenful of cards.
$limit = (int)($_GET['limit'] ?? 60);
if ($limit <= 0 || $limit > 200) $limit = 60;

$pdo   = db();
$ds    = require_dataset($pdo, $session, null);
$table = $ds['table'];

$needle  = mb_strtolower($q);
$results = [];

/* ---------- local glosses ---------- */
// glosZichtbaar = 0 keeps hidden rows out, exactly as the old wizard did:
// a hidden gloss is one somebody already decided not to collect.
// Three separate placeholders, not one named parameter used three times:
// db() prepares natively, and MySQL binds by position, so a repeated name
// is a parameter-count mismatch rather than a convenience.
$stmt = $pdo->prepare(
    "SELECT id, glos, glos_engels, senses, sensesEngels, zelfopname, videoCenter, processed
     FROM `$table`
     WHERE (glos LIKE ? OR senses LIKE ? OR sensesEngels LIKE ?) AND glosZichtbaar = 0
     ORDER BY glos"
);
$like = '%' . $q . '%';
$stmt->execute([$like, $like, $like]);

foreach ($stmt->fetchAll() as $row) {
    $results[] = [
        'source'       => 'signcollect',
        'id'           => (int)$row['id'],
        'glos'         => (string)($row['glos'] ?? ''),
        'glos_engels'  => (string)($row['glos_engels'] ?? ''),
        'senses'       => parse_json_array($row['senses']),
        'sensesEngels' => parse_json_array($row['sensesEngels']),
        'reason'       => [],
        'video'        => local_preview_video($row),
        'link'         => '',
        'signbank'     => null,
        'phonology'    => [],
    ];
}

/* ---------- Signbank ECV ---------- */
foreach (signbank_ecv_entries() as $entry) {
    $d      = $entry['d'];
    $reason = [];

    foreach (['Annotation ID Gloss: Dutch', 'Annotation ID Gloss: English'] as $key) {
        $v = (string)($d[$key] ?? '');
        if ($v !== '' && mb_strpos(mb_strtolower($v), $needle) !== false) $reason[] = $v;
    }
    foreach (['Senses: Dutch', 'Senses: English'] as $key) {
        foreach (signbank_ecv_senses($d, $key) as $sense) {
            if (mb_strpos(mb_strtolower($sense), $needle) !== false) $reason[] = $sense;
        }
    }

    if ($reason) $results[] = signbank_ecv_result($entry['id'], $d, array_values(array_unique($reason)));
}

/* ---------- one card per gloss per source ---------- */
$seen   = [];
$unique = [];
foreach ($results as $item) {
    $key = mb_strtolower($item['glos']) . '|' . $item['source'];
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $unique[]   = $item;
}

usort($unique, function ($a, $b) {
    // Local glosses first - they are the ones the user can act on directly -
    // then alphabetically, which is the order the old wizard's cards had.
    if ($a['source'] !== $b['source']) return $a['source'] === 'signcollect' ? -1 : 1;
    return strcmp($a['glos'], $b['glos']);
});

$truncated = count($unique) > $limit;

json_response([
    'query'     => $q,
    'ecv'       => signbank_ecv_available(),
    'truncated' => $truncated,
    'results'   => array_slice($unique, 0, $limit),
]);

/**
 * The thumbnail the card plays on hover. Mirrors the old wizard's choice of
 * source: a processed row shows its post-production studio take, an
 * unprocessed one the mini render, and a row with neither falls back to the
 * signer's own phone recording.
 */
function local_preview_video(array $row): string {
    $center = parse_json_array($row['videoCenter'] ?? null);
    $file   = is_array($center[0] ?? null) ? (string)($center[0]['file'] ?? '') : '';

    if ($file !== '') {
        $file = str_replace(sc_dir(), '/', $file);
        if ((string)($row['processed'] ?? '') === '2') {
            return str_replace('/raw/', '/post/', $file);
        }
        $file = str_replace('studioFiles', 'studioFilesMini', $file);
        // The mini renders are written without the date segment the raw
        // paths carry.
        return preg_replace('/\d{4}-\d{2}-\d{2}/', '', $file);
    }

    $zelf = parse_json_array($row['zelfopname'] ?? null);
    return isset($zelf[0]) && $zelf[0] !== '' ? '/uploads/' . $zelf[0] : '';
}
