<?php
/**
 * The Signbank connector: rebuilds the Signbank gloss dump
 * (/web/signbank_data/glosses_transformed.json) from the live Signbank.
 *
 * That file is the ECV dump four components read by absolute path
 * (menu_beta php_api/signbank_ecv.php, zin getSenses.php, hh getGlosses.php,
 * nmm findSBid.py / liteConvert.py). Until now nothing in the tree produced
 * it - it was copied onto each host by hand, and went stale silently. This
 * script is the producer, runnable three ways:
 *
 *   php ecv_refresh.php --force        rebuild now, whatever the schedule says
 *   php ecv_refresh.php                rebuild only if the admin schedule is due
 *   php_api/signbank_admin.php         the "Ververs nu" button, which spawns
 *                                      the first form in the background
 *
 * ---------------------------------------------------------------------------
 * How the dump is built
 *
 * Signbank has no bulk-export endpoint. What it has:
 *
 *   GET /dictionary/ajax/gloss/<datasetid>/     every gloss in the dataset as
 *                                               {annotation_idgloss, idgloss, pk}
 *                                               (~800KB, ~30s for NGT)
 *   GET /dictionary/get_gloss_data/<ds>/<id>/   one gloss, as {"<id>": {...}}
 *
 * so a rebuild is one enumeration followed by one request per gloss, run
 * concurrently through curl_multi. NGT is ~7.5k glosses; at the default
 * concurrency that is roughly four minutes and ~7.5k requests against a
 * third-party service, which is why this is a scheduled job with a manual
 * override and not something a page load can trigger by accident.
 *
 * get_gloss_data returns exactly the per-gloss object the dump already
 * contains, plus checksum fields (`Video_checksum`, and `Checksum` inside the
 * NME/perspective video lists) that Signbank added for its own upload
 * bookkeeping. Those are dropped - verified against 30 random entries of the
 * existing dump, which then match field for field. Nothing else is rewritten:
 * a field Signbank adds later lands in the dump untouched.
 *
 * ---------------------------------------------------------------------------
 * Writing it
 *
 * Readers are mid-parse of an 11MB file at unpredictable times, so the new
 * dump is written to a temp file beside the target and renamed over it.
 * rename(2) is atomic within a filesystem: a reader either opens the old
 * inode or the new one, never a half-written file.
 *
 * The dump lives in the connector's own directory because rename needs write
 * permission on the *directory*, not the file, and /web is not writable by
 * www-data. A target that is a symlink is resolved first, so a host that
 * publishes the dump under another name still gets an atomic replace of the
 * real file rather than a replaced link. See scripts/host-config.sh in the
 * deploy repo for how the directory is created.
 *
 * ---------------------------------------------------------------------------
 * Scheduling (pythonCron)
 *
 * signlab_pythonCron schedules a job as one entry in its config.json - an
 * executable, a path, and an interval - so this script is registered there
 * exactly like the PHP jobs already in it:
 *
 *   {
 *       "service_name": "Signbank ECV refresh",
 *       "executable": "/usr/bin/php",
 *       "path": "/web/menu_beta/signbank_sync/ecv_refresh.php",
 *       "working_dir": "/web/menu_beta/signbank_sync",
 *       "interval_minutes": 60,
 *       "time_or_minute": "minute",
 *       "timeout_minutes": 30,
 *       "execute_immediately": false
 *   }
 *
 * pythonCron runs it hourly and this script decides whether a rebuild is
 * actually due, from the schedule an admin set in the interface (off /
 * hourly / daily at a chosen time). Doing it that way round is deliberate:
 * pythonCron's config.json belongs to a live systemd unit and is not
 * writable by the web server, so an admin changing "daily" to "hourly" in a
 * browser must not require rewriting it. The cost is that the finest
 * schedule the page can offer is the interval pythonCron polls at.
 */

require_once __DIR__ . '/client.php';

/* -------------------------------------------------------------------------
 * Paths. signbank_state_dir() and the key helpers live in client.php, next
 * to the config they come from.
 * ---------------------------------------------------------------------- */

/**
 * The published dump - the path every consumer opens.
 *
 * Defaults into the connector's own directory rather than the docroot root:
 * /web is not writable by the web server, and a file this job has to replace
 * atomically has to live somewhere it can. ecv_path overrides it for a host
 * that keeps the dump elsewhere.
 */
function signbank_ecv_published_path(): string {
    $cfg = signbank_config();
    return (string)($cfg['ecv_path'] ?? (signbank_state_dir() . '/glosses_transformed.json'));
}

/**
 * Where a rebuild actually writes. A symlinked target is followed so the
 * rename happens in the directory that holds the real file; readers still
 * open the published path and never notice.
 */
function signbank_ecv_write_path(): string {
    $p = signbank_ecv_published_path();
    if (is_link($p)) {
        $t = readlink($p);
        if ($t !== false) {
            $p = ($t[0] === '/') ? $t : dirname($p) . '/' . $t;
        }
    }
    return $p;
}

/*
 * The connector's own files are dotfiles. The state directory sits inside
 * the docroot - it has to, because the dump is served from it - so anything
 * in it that is not the dump would otherwise be fetchable by anyone. Apache
 * denies dotfiles (FilesMatch "^\."), which is the same protection
 * /web/.session_secret relies on.
 */
function signbank_settings_path(): string { return signbank_state_dir() . '/.settings.json'; }
function signbank_state_path():    string { return signbank_state_dir() . '/.refresh_state.json'; }
function signbank_lock_path():     string { return signbank_state_dir() . '/.refresh.lock'; }
function signbank_log_path():      string { return signbank_state_dir() . '/.refresh.log'; }

/**
 * Store a new key, replacing whatever the host was provisioned with. Written
 * to a temp file and renamed so a reader mid-refresh never sees an empty or
 * half-written key, and mode 0640 so only the web user and its group can
 * read it.
 */
function signbank_key_store(string $key): void {
    $key = trim($key);
    if ($key === '' || in_array($key, signbank_key_placeholders(), true)) {
        throw new RuntimeException('refusing to store an empty or placeholder key');
    }
    signbank_atomic_write(signbank_key_path(), $key . "\n", 0640);
}

/* -------------------------------------------------------------------------
 * Small file helpers
 * ---------------------------------------------------------------------- */

/**
 * Write $contents to $path via a temp file in the same directory, then
 * rename. Same reasoning as the dump, at a smaller scale: a partially
 * written settings or key file is worse than no write at all.
 */
function signbank_atomic_write(string $path, string $contents, int $mode = 0644): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("cannot create $dir");
    }
    $tmp = $dir . '/.tmp-' . basename($path) . '-' . getmypid();
    $fh  = @fopen($tmp, 'wb');
    if ($fh === false) throw new RuntimeException("cannot write in $dir (permission?)");
    try {
        if (fwrite($fh, $contents) === false) throw new RuntimeException("write failed: $tmp");
        fflush($fh);
    } finally {
        fclose($fh);
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("cannot replace $path");
    }
}

function signbank_read_json(string $path): array {
    if (!is_readable($path)) return [];
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

/* -------------------------------------------------------------------------
 * Settings and run state
 * ---------------------------------------------------------------------- */

function signbank_settings(): array {
    $s = signbank_read_json(signbank_settings_path());
    $schedule = (string)($s['schedule'] ?? 'off');
    if (!in_array($schedule, ['off', 'hourly', 'daily'], true)) $schedule = 'off';
    $time = (string)($s['daily_time'] ?? '03:30');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '03:30';
    return ['schedule' => $schedule, 'daily_time' => $time];
}

function signbank_settings_save(string $schedule, string $dailyTime): array {
    if (!in_array($schedule, ['off', 'hourly', 'daily'], true)) {
        throw new RuntimeException('unknown schedule');
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dailyTime)) {
        throw new RuntimeException('daily_time must be HH:MM');
    }
    $s = ['schedule' => $schedule, 'daily_time' => $dailyTime];
    signbank_atomic_write(signbank_settings_path(),
        json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0664);
    return $s;
}

function signbank_state(): array {
    return signbank_read_json(signbank_state_path());
}

function signbank_state_write(array $state): void {
    signbank_atomic_write(signbank_state_path(),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0664);
}

/**
 * Is a scheduled rebuild due?
 *
 * Called by the hourly pythonCron job, so it answers "since the last
 * successful run, has the configured moment passed?" rather than counting
 * ticks - a missed hour (host down, job overran) is caught up on the next
 * one instead of being skipped.
 */
function signbank_schedule_due(?int $now = null): array {
    $now      = $now ?? time();
    $settings = signbank_settings();
    if ($settings['schedule'] === 'off') return [false, 'schedule is off'];

    $state = signbank_state();
    $last  = (int)($state['last_success_at'] ?? 0);

    if ($settings['schedule'] === 'hourly') {
        // 55 minutes, not 60: an hourly job that starts a few seconds late
        // must not defer to the next tick and quietly become two-hourly.
        if ($now - $last >= 55 * 60) return [true, 'hourly interval elapsed'];
        return [false, 'last run was ' . (int)round(($now - $last) / 60) . ' minutes ago'];
    }

    [$h, $m] = array_map('intval', explode(':', $settings['daily_time']));
    $today = mktime($h, $m, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
    if ($now >= $today && $last < $today) return [true, 'daily time ' . $settings['daily_time'] . ' passed'];
    return [false, 'not due before ' . $settings['daily_time']];
}

/* -------------------------------------------------------------------------
 * Reading what is currently published
 * ---------------------------------------------------------------------- */

/**
 * Facts about the dump on disk: when it was written and how many glosses it
 * holds.
 *
 * The count is cached against the file's mtime and size, because getting it
 * honestly means decoding 11MB of JSON and no admin page should pay that on
 * every load. A rebuild records its own count, so the expensive path only
 * runs for a dump this connector did not write - a hand-copied one, once.
 */
function signbank_ecv_stats(): array {
    $path = signbank_ecv_published_path();
    $out  = ['path' => $path, 'exists' => false, 'entries' => null, 'mtime' => null, 'size' => null];
    if (!is_readable($path)) return $out;

    $out['exists'] = true;
    $out['mtime']  = filemtime($path) ?: null;
    $out['size']   = filesize($path) ?: null;

    $state = signbank_state();
    $c = $state['dump_cache'] ?? null;
    if (is_array($c) && (int)($c['mtime'] ?? 0) === (int)$out['mtime']
                     && (int)($c['size'] ?? 0) === (int)$out['size']) {
        $out['entries'] = (int)$c['entries'];
        return $out;
    }

    $n = signbank_ecv_count_entries($path);
    $out['entries'] = $n;
    if ($n !== null) {
        $state['dump_cache'] = ['mtime' => (int)$out['mtime'], 'size' => (int)$out['size'], 'entries' => $n];
        try { signbank_state_write($state); } catch (Throwable $e) { /* read-only host: still report */ }
    }
    return $out;
}

/**
 * Count top-level entries without holding the decoded document in memory.
 *
 * The dump is a list of one-key objects, so the count is the number of `{`
 * at depth 1. Scanned in chunks with a string/escape aware walk - a value
 * containing a brace (there are plenty: URLs, annotation instructions) would
 * otherwise be counted as an entry.
 */
function signbank_ecv_count_entries(string $path): ?int {
    $fh = @fopen($path, 'rb');
    if ($fh === false) return null;
    $depth = 0; $count = 0; $inStr = false; $esc = false;
    while (!feof($fh)) {
        $chunk = fread($fh, 1 << 20);
        if ($chunk === false) break;
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            $ch = $chunk[$i];
            if ($inStr) {
                if ($esc)            { $esc = false; }
                elseif ($ch === '\\') { $esc = true; }
                elseif ($ch === '"')  { $inStr = false; }
                continue;
            }
            if     ($ch === '"') $inStr = true;
            elseif ($ch === '{') { if ($depth === 1) $count++; $depth++; }
            elseif ($ch === '[') $depth++;
            elseif ($ch === '}' || $ch === ']') $depth--;
        }
    }
    fclose($fh);
    return $count;
}

/* -------------------------------------------------------------------------
 * Talking to Signbank
 * ---------------------------------------------------------------------- */

/** Headers every request carries. Mirrors signbank_request() in client.php. */
function signbank_ecv_headers(): array {
    $cfg = signbank_config();
    if (($cfg['key_source'] ?? 'none') === 'none') {
        throw new RuntimeException('no Signbank API key configured');
    }
    $key    = (string)$cfg['api_key'];
    $scheme = strtolower((string)($cfg['auth_scheme'] ?? 'bearer'));
    return [
        $scheme === 'x-api-key' ? 'X-API-Key: ' . $key : 'Authorization: Bearer ' . $key,
        'Accept: application/json',
        // Django 4.2 raises TypeError in parse_accept_lang_header(None) and
        // 500s before doing anything if this is absent. See client.php.
        'Accept-Language: en',
    ];
}

function signbank_ecv_dataset_id(): string {
    $cfg = signbank_config();
    return (string)($cfg['dataset_id'] ?? '5');
}

/**
 * Every gloss id in the dataset, in Signbank's own ordering.
 *
 * /dictionary/ajax/gloss/<ds>/ with an empty prefix is the only endpoint that
 * will list a whole dataset; it answers in about 30 seconds for NGT.
 */
function signbank_ecv_fetch_ids(): array {
    $cfg = signbank_config();
    $url = rtrim((string)$cfg['base_url'], '/')
         . '/dictionary/ajax/gloss/' . rawurlencode(signbank_ecv_dataset_id()) . '/';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => signbank_ecv_headers(),
        // The enumeration is a single large query on Signbank's side and is
        // far slower than any per-gloss call, so it gets its own budget.
        CURLOPT_TIMEOUT        => (int)($cfg['enumerate_timeout_seconds'] ?? 180),
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        throw new RuntimeException("gloss list failed (HTTP $status" . ($err ? ", $err" : '') . ')');
    }
    $list = json_decode((string)$body, true);
    if (!is_array($list)) throw new RuntimeException('gloss list was not JSON - is the API key valid?');

    $ids = [];
    foreach ($list as $row) {
        if (isset($row['pk']) && ctype_digit((string)$row['pk'])) $ids[] = (string)$row['pk'];
    }
    if (!$ids) throw new RuntimeException('gloss list was empty');
    return $ids;
}

/**
 * Drop Signbank's upload bookkeeping. `Video_checksum` sits at the top level,
 * `Checksum` inside each NME / perspective video object; neither is in the
 * dump the consumers were built against, and both change whenever a video is
 * re-encoded, which would make every rebuild look like a content change.
 *
 * Walks stdClass, not arrays: `"Senses: Dutch": {"1": "..."}` is an object
 * whose keys happen to be digits, and decoding to a PHP array would re-encode
 * some of those as JSON lists.
 */
function signbank_ecv_strip_checksums($node) {
    if ($node instanceof stdClass) {
        foreach (get_object_vars($node) as $k => $v) {
            if ($k === 'Checksum' || substr($k, -9) === '_checksum') {
                unset($node->$k);
                continue;
            }
            $node->$k = signbank_ecv_strip_checksums($v);
        }
        return $node;
    }
    if (is_array($node)) {
        foreach ($node as $i => $v) $node[$i] = signbank_ecv_strip_checksums($v);
        return $node;
    }
    return $node;
}

/**
 * Fetch every gloss, $concurrency at a time, and return the encoded entries
 * in the order the ids were given.
 *
 * Entries are re-encoded as they arrive rather than kept as PHP structures:
 * the finished document is ~11MB of JSON but tens of times that as decoded
 * arrays, and a job that dies on memory_limit halfway is a job that never
 * publishes.
 *
 * $progress is called with (done, total) roughly every 1%, so the admin page
 * has something to show during the four minutes this takes.
 */
function signbank_ecv_fetch_entries(array $ids, int $concurrency, ?callable $progress = null): array {
    $cfg     = signbank_config();
    $base    = rtrim((string)$cfg['base_url'], '/');
    $ds      = rawurlencode(signbank_ecv_dataset_id());
    $headers = signbank_ecv_headers();
    $timeout = (int)($cfg['timeout_seconds'] ?? 30);

    $total    = count($ids);
    $entries  = array_fill(0, $total, null);
    $skipped  = [];
    $next     = 0;
    $done     = 0;
    $step     = max(25, (int)floor($total / 100));

    $mh      = curl_multi_init();
    $running = 0;
    $slots   = [];   // spl_object_id(handle) => index into $ids

    $add = function () use (&$next, &$slots, $mh, $ids, $base, $ds, $headers, $timeout, $total) {
        if ($next >= $total) return false;
        $i  = $next++;
        $ch = curl_init($base . '/dictionary/get_gloss_data/' . $ds . '/' . rawurlencode($ids[$i]) . '/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        curl_multi_add_handle($mh, $ch);
        $slots[spl_object_id($ch)] = $i;
        return true;
    };

    for ($i = 0; $i < min($concurrency, $total); $i++) $add();

    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) {
            $ch     = $info['handle'];
            $i      = $slots[spl_object_id($ch)] ?? null;
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body   = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($slots[spl_object_id($ch)]);

            if ($i !== null) {
                $obj = ($status === 200 && $body !== null && $body !== '')
                     ? json_decode((string)$body) : null;
                if ($obj instanceof stdClass && get_object_vars($obj)) {
                    $entries[$i] = json_encode(
                        signbank_ecv_strip_checksums($obj),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    );
                } else {
                    // A gloss can legitimately be unavailable - archived, or
                    // not public to this key. It is skipped, not fatal, but
                    // it is counted so a key that fetches nothing is visible.
                    $skipped[] = ['id' => $ids[$i], 'status' => $status];
                }
                $done++;
                if ($progress && ($done % $step === 0 || $done === $total)) $progress($done, $total);
            }
            $add();
            curl_multi_exec($mh, $running);
        }
    } while ($running > 0 || $slots);

    curl_multi_close($mh);

    return [array_values(array_filter($entries, fn($e) => $e !== null)), $skipped];
}

/**
 * Write the entries as the dump: a JSON list, 4-space indented, ASCII with
 * \u escapes and unescaped slashes - byte-for-byte the formatting the
 * existing file uses, so a diff between two rebuilds shows content changes
 * and nothing else.
 */
function signbank_ecv_publish(array $entries, string $target): int {
    $dir = dirname($target);
    if (!is_dir($dir)) throw new RuntimeException("target directory $dir does not exist");
    $tmp = $dir . '/.glosses_transformed.' . getmypid() . '.tmp';

    $fh = @fopen($tmp, 'wb');
    if ($fh === false) {
        throw new RuntimeException("cannot write in $dir - the web user needs write access to it");
    }
    try {
        fwrite($fh, "[\n");
        $last = count($entries) - 1;
        foreach ($entries as $i => $encoded) {
            fwrite($fh, preg_replace('/^/m', '    ', $encoded));
            fwrite($fh, $i === $last ? "\n" : ",\n");
        }
        fwrite($fh, "]");
        fflush($fh);
    } catch (Throwable $e) {
        fclose($fh); @unlink($tmp);
        throw $e;
    }
    fclose($fh);

    // 0664: the scheduled job and the web request may run as different users,
    // and whichever wrote it last must not lock the other out of the next
    // rebuild. Both are in the group that owns the state directory.
    @chmod($tmp, 0664);
    $size = filesize($tmp) ?: 0;
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException("cannot replace $target");
    }
    return (int)$size;
}

/* -------------------------------------------------------------------------
 * The run
 * ---------------------------------------------------------------------- */

/**
 * Rebuild the dump.
 *
 *   'limit'       > 0 fetches only that many glosses and writes them to a
 *                  staging file instead of publishing. That is the "Test
 *                  connection" button: it exercises enumeration, auth,
 *                  transform and atomic write in a few seconds without
 *                  replacing 7.5k live entries with a sample.
 *   'concurrency'  parallel requests. 12 keeps a full run near four minutes
 *                  without leaning on Signbank.
 *
 * Only one run at a time: the lock is held for the whole job so the hourly
 * job and an impatient admin cannot interleave two rebuilds onto one file.
 */
function signbank_refresh_run(array $opts = []): array {
    $limit       = max(0, (int)($opts['limit'] ?? 0));
    $concurrency = (int)($opts['concurrency'] ?? 12);
    if ($concurrency < 1)  $concurrency = 1;
    if ($concurrency > 32) $concurrency = 32;
    $trigger = (string)($opts['trigger'] ?? 'manual');

    $lockPath = signbank_lock_path();
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException("cannot open $lockPath");
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new RuntimeException('a refresh is already running');
    }
    @chmod($lockPath, 0664);

    $started = microtime(true);
    $state   = signbank_state();
    $state['status']     = 'running';
    $state['trigger']    = $trigger;
    $state['started_at'] = time();
    $state['progress']   = ['done' => 0, 'total' => null, 'phase' => 'enumerating'];
    unset($state['error']);
    signbank_state_write($state);

    try {
        $ids = signbank_ecv_fetch_ids();
        $available = count($ids);
        if ($limit > 0) $ids = array_slice($ids, 0, $limit);

        $state['progress'] = ['done' => 0, 'total' => count($ids), 'phase' => 'fetching'];
        signbank_state_write($state);

        [$entries, $skipped] = signbank_ecv_fetch_entries($ids, $concurrency,
            function ($done, $total) use (&$state) {
                $state['progress'] = ['done' => $done, 'total' => $total, 'phase' => 'fetching'];
                try { signbank_state_write($state); } catch (Throwable $e) { /* progress is best effort */ }
            });

        if (!$entries) throw new RuntimeException('every gloss request failed - check the API key');

        $staging = $limit > 0;
        $target  = $staging
                 ? signbank_state_dir() . '/.glosses_transformed.staging.json'
                 : signbank_ecv_write_path();
        $bytes   = signbank_ecv_publish($entries, $target);

        $result = [
            'ok'          => true,
            'entries'     => count($entries),
            'available'   => $available,
            'skipped'     => count($skipped),
            'bytes'       => $bytes,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'staging'     => $staging,
            'target'      => $target,
            'trigger'     => $trigger,
        ];

        $state['status']      = 'idle';
        $state['last_result'] = $result;
        $state['finished_at'] = time();
        $state['progress']    = ['done' => count($ids), 'total' => count($ids), 'phase' => 'done'];
        if (!$staging) {
            $state['last_success_at'] = time();
            $state['dump_cache'] = [
                'mtime'   => @filemtime(signbank_ecv_published_path()) ?: time(),
                'size'    => $bytes,
                'entries' => count($entries),
            ];
        }
        signbank_state_write($state);
        return $result;

    } catch (Throwable $e) {
        $state['status']      = 'failed';
        $state['finished_at'] = time();
        $state['error']       = $e->getMessage();
        $state['last_result'] = [
            'ok'          => false,
            'error'       => $e->getMessage(),
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'trigger'     => $trigger,
        ];
        try { signbank_state_write($state); } catch (Throwable $ignored) {}
        throw $e;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/* -------------------------------------------------------------------------
 * CLI
 * ---------------------------------------------------------------------- */

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    // A full run holds ~11MB of encoded entries plus curl buffers. The
    // default 128M is enough, but a host that lowered it should not discover
    // that four minutes into a job.
    if ((int)ini_get('memory_limit') > 0 && (int)ini_get('memory_limit') < 512) {
        ini_set('memory_limit', '512M');
    }
    set_time_limit(0);

    $opts  = ['trigger' => 'cli'];
    $force = false;
    $quiet = false;
    foreach (array_slice($argv, 1) as $arg) {
        if     ($arg === '--force')  $force = true;
        elseif ($arg === '--quiet')  $quiet = true;
        elseif (str_starts_with($arg, '--limit='))       $opts['limit']       = (int)substr($arg, 8);
        elseif (str_starts_with($arg, '--concurrency=')) $opts['concurrency'] = (int)substr($arg, 14);
        elseif ($arg === '--status') {
            echo json_encode([
                'settings' => signbank_settings(),
                'state'    => signbank_state(),
                'dump'     => signbank_ecv_stats(),
                'key'      => signbank_key_source(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
            exit(0);
        } else {
            fwrite(STDERR, "unknown option: $arg\n");
            exit(2);
        }
    }
    $say = function (string $m) use ($quiet) {
        if (!$quiet) fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $m . "\n");
    };

    if (!$force) {
        [$due, $why] = signbank_schedule_due();
        $opts['trigger'] = 'schedule';
        if (!$due) { $say("not due: $why"); exit(0); }
        $say("due: $why");
    }

    try {
        $r = signbank_refresh_run($opts);
        $say(sprintf('%s %d glosses (%d skipped) in %.1fs -> %s (%.1f MB)',
            $r['staging'] ? 'staged' : 'published',
            $r['entries'], $r['skipped'], $r['duration_ms'] / 1000,
            $r['target'], $r['bytes'] / 1048576));
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, date('[Y-m-d H:i:s] ') . 'refresh failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
