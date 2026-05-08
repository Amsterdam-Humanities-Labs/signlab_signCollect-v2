<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$session = require_session();

$body         = json_body();
$search       = trim((string)($body['search']   ?? ''));
$thema        = trim((string)($body['thema']    ?? ''));
$labels       = is_array($body['labels'] ?? null)   ? $body['labels']   : [];
$statuses     = is_array($body['statuses'] ?? null) ? $body['statuses'] : [];
$ownerUserId  = isset($body['ownerUserId']) ? trim((string)$body['ownerUserId']) : '';
$context      = isset($body['context']) ? (string)$body['context'] : 'signio';
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
    'no_studio_video'  => 'NOT EXISTS (SELECT 1 FROM matched_transcriptions mt
                            WHERE mt.m_transcription REGEXP \'^[0-9]+$\'
                              AND CAST(mt.m_transcription AS UNSIGNED) = form_data.id
                              AND (mt.added IS NULL OR UPPER(mt.added) <> \'DELETE\')
                              AND ((form_data.extern = \'1\'   AND mt.zOg IN (\'labels\',\'extern\'))
                                OR (form_data.extern IS NULL AND mt.zOg = \'Glos\')))',
    'has_studio_video' => 'EXISTS (SELECT 1 FROM matched_transcriptions mt
                            WHERE mt.m_transcription REGEXP \'^[0-9]+$\'
                              AND CAST(mt.m_transcription AS UNSIGNED) = form_data.id
                              AND (mt.added IS NULL OR UPPER(mt.added) <> \'DELETE\')
                              AND ((form_data.extern = \'1\'   AND mt.zOg IN (\'labels\',\'extern\'))
                                OR (form_data.extern IS NULL AND mt.zOg = \'Glos\')))',
    'extern_duplicate' => 'extern = \'1\' AND glos IS NOT NULL AND glos <> \'\'
                            AND glos IN (
                              SELECT glos FROM form_data
                              WHERE extern = \'1\' AND glos IS NOT NULL AND glos <> \'\'
                                AND (glosZichtbaar = 0 OR glosZichtbaar IS NULL)
                              GROUP BY glos HAVING COUNT(*) > 1
                            )',
];
foreach ($statuses as $s) {
    if (isset($statusMap[$s])) $where[] = $statusMap[$s];
}

if (!in_array('hidden', $statuses, true)) {
    $where[] = '(glosZichtbaar = 0 OR glosZichtbaar IS NULL)';
}

if ($context === 'signbank') {
    $where[] = 'extern IS NULL';
} else {
    $where[] = 'extern = \'1\'';
}

if ($ownerUserId !== '') {
    $where[] = 'wie LIKE ?';
    $args[]  = '%"' . $ownerUserId . '"%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$pdo = db();

$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM form_data {$whereSql}");
$countStmt->execute($args);
$total = (int)$countStmt->fetch()['c'];

$externDupActive = in_array('extern_duplicate', $statuses, true);
$sortMap = [
    'newest'         => 'form_data.id DESC',
    'oldest'         => 'form_data.id ASC',
    'glos_az'        => '(form_data.glos IS NULL OR form_data.glos = \'\') ASC, form_data.glos ASC, form_data.id DESC',
    'glos_za'        => '(form_data.glos IS NULL OR form_data.glos = \'\') ASC, form_data.glos DESC, form_data.id DESC',
    'latest_capture' => 'COALESCE(_lc.max_id, 0) DESC, form_data.id DESC',
    'oldest_capture' => '(_lc.max_id IS NULL) ASC, _lc.max_id ASC, form_data.id ASC',
];

$needsCaptureJoin = in_array($sort, ['latest_capture', 'oldest_capture'], true);
$captureJoin = '';
if ($needsCaptureJoin) {
    $captureJoin = "LEFT JOIN (
        SELECT CAST(m_transcription AS UNSIGNED) AS gid, MAX(id) AS max_id
        FROM matched_transcriptions
        WHERE m_transcription REGEXP '^[0-9]+$'
          AND (added IS NULL OR UPPER(added) <> 'DELETE')
        GROUP BY CAST(m_transcription AS UNSIGNED)
    ) _lc ON _lc.gid = form_data.id";
}
$orderBy = 'ORDER BY ' . ($externDupActive ? 'form_data.glos ASC, form_data.id DESC' : ($sortMap[$sort] ?? $sortMap['glos_az']));

$listSql = "SELECT form_data.id, form_data.glos, form_data.glos_engels, form_data.wie,
                   form_data.thema, form_data.labels, form_data.glosZichtbaar,
                   form_data.zelfopname, form_data.senses, form_data.sensesEngels,
                   form_data.control_nodig,
                   form_data.fonologie_fase1, form_data.fonologie_fase2
            FROM form_data
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

    // Modal list: zOg depends on gloss's extern flag.
    //   - extern = '1'   → zOg IN ('labels', 'extern')
    //   - otherwise      → zOg = 'Glos'
    $videoStmt = $pdo->prepare(
        "SELECT mt.id, mt.m_transcription, mt.m_file, mt.l_file, mt.r_file,
                mt.thumbnail, mt.post_processed, mt.definitive_outcome, mt.date,
                mt.zOg, mt.added
         FROM matched_transcriptions mt
         INNER JOIN form_data fd
                 ON mt.m_transcription REGEXP '^[0-9]+$'
                AND CAST(mt.m_transcription AS UNSIGNED) = fd.id
         WHERE fd.id IN ($placeholders)
           AND (
                  (fd.extern = '1'   AND mt.zOg IN ('labels', 'extern'))
               OR (fd.extern IS NULL AND mt.zOg = 'Glos')
               )
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

    // Row thumbnail: latest non-deleted entry, with same zOg/extern rule as the modal list.
    $thumbStmt = $pdo->prepare(
        "SELECT mt.m_transcription, mt.m_file, mt.post_processed
         FROM matched_transcriptions mt
         INNER JOIN (
           SELECT mt2.m_transcription, MAX(mt2.id) AS max_id
           FROM matched_transcriptions mt2
           INNER JOIN form_data fd
                   ON mt2.m_transcription REGEXP '^[0-9]+$'
                  AND CAST(mt2.m_transcription AS UNSIGNED) = fd.id
           WHERE fd.id IN ($placeholders)
             AND (mt2.added IS NULL OR UPPER(mt2.added) <> 'DELETE')
             AND (
                    (fd.extern = '1'   AND mt2.zOg IN ('labels', 'extern'))
                 OR (fd.extern IS NULL AND mt2.zOg = 'Glos')
                 )
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
