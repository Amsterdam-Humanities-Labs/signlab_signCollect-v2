<?php
/**
 * Admin API behind signbank.php - the Signbank connector page.
 *
 * GET                      -> status: connection, key, dump, schedule, last run
 * POST {"action":"save_key"}  -> replace the stored API key
 * POST {"action":"test"}      -> one live request, to prove the key works
 * POST {"action":"refresh"}   -> rebuild the gloss dump (see below)
 * POST {"action":"schedule"}  -> set the recurring refresh
 *
 * Admin-only, through the session layer every other endpoint uses:
 * require_session() rejects anyone without a valid signed cookie, and
 * session_is_admin() reads the role out of the users table - never out of
 * the cookie, which the caller writes. Same rule as users_api.php's
 * requireAdmin(); there is no second notion of "admin" here.
 *
 * A refresh comes in two shapes because a full one takes minutes:
 *
 *   sample - a few dozen glosses written to a staging file, never published.
 *            Runs inline, proves enumeration + auth + transform + atomic
 *            write, and is what the test suite exercises. It cannot damage
 *            the live dump.
 *   full   - every gloss, published. Spawned as a detached CLI process,
 *            because no browser should hold a request open for four minutes
 *            and no proxy would let it. The page polls this endpoint for
 *            progress, which the job writes to its state file.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../signbank_sync/ecv_refresh.php';

$session = require_session();
if (!session_is_admin()) json_response(['error' => 'forbidden'], 403);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Everything the page renders, in one round trip. */
function signbank_admin_status(): array {
    $out = ['ok' => true];
    try {
        $cfg = signbank_config();
        $out['connection'] = [
            'base_url'    => (string)($cfg['base_url'] ?? ''),
            'public_url'  => (string)($cfg['public_url'] ?? ''),
            'dataset_id'  => (string)($cfg['dataset_id'] ?? ''),
            'dataset'     => (string)($cfg['dataset_acronym'] ?? ''),
            'auth_scheme' => (string)($cfg['auth_scheme'] ?? 'bearer'),
        ];
        // Never the key itself, only enough to tell two keys apart: which
        // file it came from, its length, and its last four characters.
        $key = (string)($cfg['api_key'] ?? '');
        $src = signbank_key_source();
        $out['key'] = [
            'source' => $src,
            'set'    => $src !== 'none',
            'hint'   => $src === 'none' ? null : ('...' . substr($key, -4)),
            'length' => $src === 'none' ? 0 : strlen($key),
            'file'   => signbank_key_path(),
        ];
        $out['dump']     = signbank_ecv_stats();
        $out['settings'] = signbank_settings();
        $out['state']    = signbank_state();
        [$due, $why]     = signbank_schedule_due();
        $out['schedule_due']    = $due;
        $out['schedule_reason'] = $why;
        $out['writable']        = is_writable(dirname(signbank_ecv_write_path()));
        $out['state_dir']       = signbank_state_dir();
        $out['state_writable']  = is_writable(signbank_state_dir());
    } catch (Throwable $e) {
        $out['ok']    = false;
        $out['error'] = $e->getMessage();
    }
    return $out;
}

if ($method === 'GET') json_response(signbank_admin_status());

if ($method !== 'POST') json_response(['error' => 'method_not_allowed'], 405);

$body   = json_body();
$action = (string)($body['action'] ?? '');

try {
    switch ($action) {

        case 'save_key':
            $key = trim((string)($body['key'] ?? ''));
            if ($key === '') json_response(['error' => 'key_required'], 400);
            // Signbank tokens are opaque; only reject what is certainly not
            // one, so a future format change does not lock an admin out.
            if (!preg_match('/^[\x21-\x7e]{8,255}$/', $key)) {
                json_response(['error' => 'key_malformed'], 400);
            }
            signbank_key_store($key);
            // The config cached in this process still holds the old key.
            json_response(['ok' => true, 'key' => ['source' => 'runtime', 'set' => true,
                                                   'hint' => '...' . substr($key, -4)]]);

        case 'test':
            // One real request against the configured host. Deliberately not
            // the enumeration, which takes half a minute: this answers "does
            // this key work", and the sample refresh answers "does the whole
            // pipeline work".
            $probe = (string)($body['gloss'] ?? '');
            if (!ctype_digit($probe)) $probe = '';
            $ds    = signbank_ecv_dataset_id();
            $start = microtime(true);
            $res   = signbank_get('/dictionary/get_gloss_data/' . rawurlencode($ds) . '/'
                                  . rawurlencode($probe !== '' ? $probe : '3808') . '/');
            json_response([
                'ok'          => (bool)$res['ok'],
                'status'      => $res['status'],
                'error'       => $res['error'],
                'duration_ms' => (int)round((microtime(true) - $start) * 1000),
                'sample'      => $res['ok'] && is_array($res['body'])
                                 ? array_key_first($res['body']) : null,
            ]);

        case 'refresh':
            $mode = (string)($body['mode'] ?? 'full');
            if ($mode === 'sample') {
                $limit = (int)($body['limit'] ?? 40);
                if ($limit < 1)   $limit = 1;
                if ($limit > 200) $limit = 200;
                // The enumeration alone is ~30s against Signbank; the default
                // 30s cap would kill this mid-request.
                @set_time_limit(300);
                $r = signbank_refresh_run(['limit' => $limit, 'trigger' => 'sample',
                                           'concurrency' => 8]);
                json_response(['ok' => true] + $r);
            }

            $state = signbank_state();
            if (($state['status'] ?? '') === 'running') {
                json_response(['ok' => false, 'error' => 'a refresh is already running',
                               'state' => $state], 409);
            }
            $cfg  = signbank_config();
            $php  = (string)($cfg['php_cli'] ?? '/usr/bin/php');
            $job  = dirname(__DIR__) . '/signbank_sync/ecv_refresh.php';
            if (!is_executable($php)) {
                json_response(['ok' => false,
                               'error' => "no PHP CLI at $php - set php_cli in signbank_sync/config.php"], 500);
            }
            // Detached: nohup + background, output to the connector's own log
            // so a failure after the browser has gone is still readable.
            $cmd = sprintf('nohup %s %s --force --quiet >> %s 2>&1 &',
                           escapeshellarg($php), escapeshellarg($job),
                           escapeshellarg(signbank_log_path()));
            exec($cmd, $out, $rc);
            if ($rc !== 0) json_response(['ok' => false, 'error' => 'could not start the refresh job'], 500);

            // Let the job claim the lock and write "running" before the page
            // polls, so the first poll does not read the previous run's state
            // and report the new one as already finished.
            usleep(400000);
            json_response(['ok' => true, 'started' => true, 'state' => signbank_state()]);

        case 'schedule':
            // Validated here as well as in signbank_settings_save(), so that
            // a bad value from this form is a 400 rather than the 500 an
            // exception from the library would produce.
            $sched = (string)($body['schedule'] ?? 'off');
            $time  = (string)($body['daily_time'] ?? '03:30');
            if (!in_array($sched, ['off', 'hourly', 'daily'], true)) {
                json_response(['error' => 'unknown_schedule', 'schedule' => $sched], 400);
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                json_response(['error' => 'daily_time must be HH:MM'], 400);
            }
            $s = signbank_settings_save($sched, $time);
            [$due, $why] = signbank_schedule_due();
            json_response(['ok' => true, 'settings' => $s,
                           'schedule_due' => $due, 'schedule_reason' => $why]);

        default:
            json_response(['error' => 'unknown_action'], 400);
    }
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
