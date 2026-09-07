<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';

$session = require_session();

$body         = json_body();

$pdo    = db();
$ds     = require_dataset($pdo, $session, $body);
$table  = $ds['table'];                                        // e.g. form_data | lsm_data
$studio = dataset_studio_video_exists($ds['code'], 'f');       // EXISTS subquery, aliased gloss table = f
$mtZog  = dataset_matched_zog_clause($ds['code'], 'mt', 'f'); // AND-fragment for free-form joins
$search       = trim((string)($body['search']   ?? ''));
$thema        = trim((string)($body['thema']    ?? ''));
$labels       = is_array($body['labels'] ?? null)   ? $body['labels']   : [];
$statuses     = is_array($body['statuses'] ?? null) ? $body['statuses'] : [];
$ownerUserId  = isset($body['ownerUserId']) ? trim((string)$body['ownerUserId']) : '';
$context      = require_context($pdo, $session, $body);            // 403 if the user may not use it
$sort         = isset($body['sort']) ? (string)$body['sort'] : 'glos_az';
$page         = max(1, (int)($body['page'] ?? 1));
$pageSize     = 50;
$offset       = ($page - 1) * $pageSize;

$where = [];
$args  = [];

if ($search !== '') {
    $needle = '%' . $search . '%';
    $where[] = '(glos LIKE ? OR glos_engels LIKE ? OR senses LIKE ? OR sensesEngels LIKE ?)';
    array_push($args, $needle, $needle, $needle, $needle);
}

if ($thema !== '') {
    $where[]  = 'thema = ?';
    $args[]   = $thema;
}

foreach ($labels as $label) {
    $where[]  = 'labels LIKE ?';
    $args[]   = '%"' . str_replace('"', '\"', $label) . '"%';
}

$statusMap = [
    'hidden'           => 'glosZichtbaar = 1',
    'no_label'         => '(labels IS NULL OR labels = \'\' OR labels = \'[]\')',
    'no_thema'         => '(thema IS NULL OR thema = \'\')',
    'no_zelfopname'    => '(zelfopname IS NULL OR zelfopname = \'\' OR zelfopname = \'[]\')',
    'has_zelfopname'   => '(zelfopname IS NOT NULL AND zelfopname <> \'\' AND zelfopname <> \'[]\')',
    'no_studio_video'  => 'NOT ' . $studio,
    'has_studio_video' => $studio,
    'extern_duplicate' => "extern = '1' AND glos IS NOT NULL AND glos <> ''
                            AND glos IN (
                              SELECT glos FROM `$table`
                              WHERE extern = '1' AND glos IS NOT NULL AND glos <> ''
                                AND (glosZichtbaar = 0 OR glosZichtbaar IS NULL)
                              GROUP BY glos HAVING COUNT(*) > 1
                            )",
];
foreach ($statuses as $s) {
    if (isset($statusMap[$s])) $where[] = $statusMap[$s];
}

if (!in_array('hidden', $statuses, true)) {
    $where[] = '(glosZichtbaar = 0 OR glosZichtbaar IS NULL)';
}

// Only apply the signio/signbank sub-view filter for datasets that
// actually use it. LSM (and any future dataset with has_extern_subview=false)
// doesn't split rows by `extern`, so we show everything from its table.
if (!empty($ds['has_extern_subview'])) {
    if ($context === 'signbank') {
        $where[] = 'extern IS NULL';
    } else {
        $where[] = 'extern = \'1\'';
    }
}

if ($ownerUserId !== '') {
    $where[] = 'wie LIKE ?';
    $args[]  = '%"' . $ownerUserId . '"%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM `$table` f {$whereSql}");
$countStmt->execute($args);
$total = (int)$countStmt->fetch()['c'];

$externDupActive = in_array('extern_duplicate', $statuses, true);
$sortMap = [
    'newest'         => 'f.id DESC',
    'oldest'         => 'f.id ASC',
    'glos_az'        => "(f.glos IS NULL OR f.glos = '') ASC, f.glos ASC, f.id DESC",
    'glos_za'        => "(f.glos IS NULL OR f.glos = '') ASC, f.glos DESC, f.id DESC",
    'latest_capture' => 'COALESCE(_lc.max_id, 0) DESC, f.id DESC',
    'oldest_capture' => '(_lc.max_id IS NULL) ASC, _lc.max_id ASC, f.id ASC',
];

$needsCaptureJoin = in_array($sort, ['latest_capture', 'oldest_capture'], true);
// Inside the subquery there is no `f` table, so we can't use the full NGT per-row zOg/extern clause.
// Use a coarse filter: for LSM restrict to zOg='lsm'; for NGT skip the filter (1=1) to preserve
// current behaviour (the subquery is only used for ordering, not correctness-critical filtering).
$captureMtClause = $ds['code'] === 'ngt' ? '1=1' : "zOg = '" . addslashes($ds['code']) . "'";
$captureJoin = '';
if ($needsCaptureJoin) {
    $captureJoin = "LEFT JOIN (
        SELECT CAST(m_transcription AS UNSIGNED) AS gid, MAX(id) AS max_id
        FROM matched_transcriptions
        WHERE m_transcription REGEXP '^[0-9]+$'
          AND (added IS NULL OR UPPER(added) <> 'DELETE')
          AND $captureMtClause
        GROUP BY CAST(m_transcription AS UNSIGNED)
    ) _lc ON _lc.gid = f.id";
}
$orderBy = 'ORDER BY ' . ($externDupActive ? 'f.glos ASC, f.id DESC' : ($sortMap[$sort] ?? $sortMap['glos_az']));

$listSql = "SELECT f.id, f.glos, f.glos_engels, f.wie,
                   f.thema, f.labels, f.glosZichtbaar,
                   f.zelfopname, f.senses, f.sensesEngels,
                   f.control_nodig,
                   f.fonologie_fase1, f.fonologie_fase2,
                   f.signbank
            FROM `$table` f
            {$captureJoin}
            {$whereSql}
            {$orderBy}
            LIMIT {$pageSize} OFFSET {$offset}";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($args);
$rows = $listStmt->fetchAll();

$ids = array_map(fn($r) => (int)$r['id'], $rows);

$videosByGloss      = [];
$thumbnailByGloss   = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stringIds = array_map('strval', $ids);

    // Modal list: zOg rule is dataset-specific (via dataset registry).
    $videoMtZog = dataset_matched_zog_clause($ds['code'], 'mt', 'fd');
    $videoStmt = $pdo->prepare(
        "SELECT mt.id, mt.m_transcription, mt.m_file, mt.l_file, mt.r_file,
                mt.thumbnail, mt.post_processed, mt.definitive_outcome, mt.date,
                mt.zOg, mt.added
         FROM matched_transcriptions mt
         INNER JOIN `$table` fd
                 ON mt.m_transcription REGEXP '^[0-9]+$'
                AND CAST(mt.m_transcription AS UNSIGNED) = fd.id
         WHERE fd.id IN ($placeholders)
           AND $videoMtZog
         ORDER BY mt.m_transcription, mt.id DESC"
    );
    $videoStmt->execute($ids);
    foreach ($videoStmt->fetchAll() as $v) {
        $key = (int)$v['m_transcription'];
        $videosByGloss[$key][] = [
            'id'                 => (int)$v['id'],
            'm_file'             => $v['m_file'],
            'l_file'             => $v['l_file'],
            'r_file'             => $v['r_file'],
            'thumbnail'          => (int)($v['thumbnail'] ?? 0),
            'post_processed'     => (int)($v['post_processed'] ?? 0),
            'definitive_outcome' => $v['definitive_outcome'],
            'date'               => $v['date'],
            'zOg'                => $v['zOg'],
            'added'              => $v['added'],
            'deleted'            => in_array(strtoupper((string)$v['added']), ['DELETE'], true),
        ];
    }

    // Row thumbnail: latest non-deleted entry, with zOg rule via dataset registry.
    $thumbMtZog = dataset_matched_zog_clause($ds['code'], 'mt2', 'fd');
    $thumbStmt = $pdo->prepare(
        "SELECT mt.m_transcription, mt.m_file, mt.post_processed
         FROM matched_transcriptions mt
         INNER JOIN (
           SELECT mt2.m_transcription, MAX(mt2.id) AS max_id
           FROM matched_transcriptions mt2
           INNER JOIN `$table` fd
                   ON mt2.m_transcription REGEXP '^[0-9]+$'
                  AND CAST(mt2.m_transcription AS UNSIGNED) = fd.id
           WHERE fd.id IN ($placeholders)
             AND (mt2.added IS NULL OR UPPER(mt2.added) <> 'DELETE')
             AND $thumbMtZog
           GROUP BY mt2.m_transcription
         ) latest ON mt.id = latest.max_id"
    );
    $thumbStmt->execute($ids);
    foreach ($thumbStmt->fetchAll() as $t) {
        $thumbnailByGloss[(int)$t['m_transcription']] = [
            'm_file'         => $t['m_file'],
            'post_processed' => (int)($t['post_processed'] ?? 0),
        ];
    }
}

// Look up duplicates by glos for the current page.
$duplicatesByGlos = [];
$pageGlosValues = array_unique(array_filter(array_map(fn($r) => $r['glos'], $rows)));
if ($pageGlosValues && $ids) {
    $glosPh = implode(',', array_fill(0, count($pageGlosValues), '?'));
    $idPh   = implode(',', array_fill(0, count($ids), '?'));
    $dupStmt = $pdo->prepare(
        "SELECT id, glos, extern, glosZichtbaar, wie
         FROM form_data
         WHERE glos IN ($glosPh) AND id NOT IN ($idPh)
           AND extern = '1'
           AND (glosZichtbaar = 0 OR glosZichtbaar IS NULL)"
    );
    $dupStmt->execute([...array_values($pageGlosValues), ...$ids]);
    foreach ($dupStmt->fetchAll() as $d) {
        $duplicatesByGlos[$d['glos']][] = [
            'id'            => (int)$d['id'],
            'glos'          => $d['glos'],
            'extern'        => $d['extern'],
            'glosZichtbaar' => (int)$d['glosZichtbaar'],
            'wie'           => parse_json_array($d['wie']),
        ];
    }
}

$out = [];
foreach ($rows as $r) {
    $id  = (int)$r['id'];
    $out[] = [
        'id'             => $id,
        'glos'           => $r['glos'] ?? '',
        'glos_engels'    => $r['glos_engels'] ?? '',
        'wie'            => parse_json_array($r['wie']),
        'thema'          => $r['thema'] ?? '',
        'labels'         => parse_json_array($r['labels']),
        'glosZichtbaar'  => (int)$r['glosZichtbaar'],
        'zelfopname'     => parse_json_array($r['zelfopname']),
        'senses'         => parse_json_array($r['senses']),
        'sensesEngels'   => parse_json_array($r['sensesEngels']),
        'control_nodig'  => parse_json_array($r['control_nodig']),
        'fonologie_fase1' => $r['fonologie_fase1'],
        'fonologie_fase2' => $r['fonologie_fase2'],
        'signbank'        => $r['signbank'] ?: null,
        'studio_videos'  => $videosByGloss[$id] ?? [],
        'thumbnail_video' => $thumbnailByGloss[$id] ?? null,
        'duplicates'     => $duplicatesByGlos[$r['glos']] ?? [],
    ];
}

json_response([
    'rows'     => $out,
    'total'    => $total,
    'page'     => $page,
    'pageSize' => $pageSize,
]);
