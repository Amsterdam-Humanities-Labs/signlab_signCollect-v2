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
$page         = max(1, (int)($body['page'] ?? 1));
$pageSize     = 25;
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
    'no_studio_video'  => 'NOT EXISTS (SELECT 1 FROM matched_transcriptions mt
                            WHERE mt.m_transcription REGEXP \'^[0-9]+$\'
                              AND CAST(mt.m_transcription AS UNSIGNED) = form_data.id)',
];
foreach ($statuses as $s) {
    if (isset($statusMap[$s])) $where[] = $statusMap[$s];
}

if (!in_array('hidden', $statuses, true)) {
    $where[] = '(glosZichtbaar = 0 OR glosZichtbaar IS NULL)';
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

$listSql = "SELECT id, glos, glos_engels, wie, thema, labels, glosZichtbaar,
                   zelfopname, senses, sensesEngels, control_nodig
            FROM form_data {$whereSql}
            ORDER BY id DESC
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

    // Modal list: zOg in (Glos, labels, extern), latest first, all `added` states
    $videoStmt = $pdo->prepare(
        "SELECT id, m_transcription, m_file, l_file, r_file,
                thumbnail, post_processed, definitive_outcome, date, zOg, added
         FROM matched_transcriptions
         WHERE m_transcription IN ($placeholders)
           AND zOg IN ('labels','extern')
         ORDER BY m_transcription, id DESC"
    );
    $videoStmt->execute($stringIds);
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

    // Row thumbnail: latest non-deleted entry (any zOg)
    $thumbStmt = $pdo->prepare(
        "SELECT mt.m_transcription, mt.m_file, mt.post_processed
         FROM matched_transcriptions mt
         INNER JOIN (
           SELECT m_transcription, MAX(id) AS max_id
           FROM matched_transcriptions
           WHERE m_transcription IN ($placeholders)
             AND (added IS NULL OR UPPER(added) <> 'DELETE')
           GROUP BY m_transcription
         ) latest ON mt.id = latest.max_id"
    );
    $thumbStmt->execute($stringIds);
    foreach ($thumbStmt->fetchAll() as $t) {
        $thumbnailByGloss[(int)$t['m_transcription']] = [
            'm_file'         => $t['m_file'],
            'post_processed' => (int)($t['post_processed'] ?? 0),
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
        'studio_videos'  => $videosByGloss[$id] ?? [],
        'thumbnail_video' => $thumbnailByGloss[$id] ?? null,
    ];
}

json_response([
    'rows'     => $out,
    'total'    => $total,
    'page'     => $page,
    'pageSize' => $pageSize,
]);
