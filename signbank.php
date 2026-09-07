<?php
/**
 * Signbank connector - admin page.
 *
 * PHP rather than a static .html like users.html and labels_add.html,
 * because those pages are only guarded in the browser (userProtect.js
 * redirects, then every action they offer is refused server-side). This one
 * shows the state of a credential, so the page itself is gated too: apache
 * serves nothing at all to a non-admin. The gate is the shared session
 * layer - current_session() reads the role from the users table, never from
 * the cookie - and not a second idea of what admin means.
 */
require_once __DIR__ . '/php_api/db.php';
require_once __DIR__ . '/php_api/session.php';

$session = current_session();
if ($session === null) {
    header('Location: /login.html?redirect=' . rawurlencode('/menu_beta/signbank.php'));
    exit;
}
if (!session_is_admin()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Geen toegang</title>'
       . '<p style="font-family:sans-serif;padding:40px">Deze pagina is alleen voor beheerders. '
       . '<a href="/menu_beta/index.html">Terug naar het menu</a>.</p>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>signCollect - Signbank koppeling</title>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="/userProtect.js"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 40px 20px;
        }
        .page-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 40px;
            max-width: 900px;
            margin: 0 auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 28px; flex-wrap: wrap; gap: 12px;
        }
        .page-header-left { display: flex; align-items: center; gap: 16px; }
        .page-header-left i { font-size: 32px; color: #0f3460; }
        .page-header-left h1 { font-size: 24px; font-weight: 700; color: #1a1a2e; margin: 0; }
        .btn-back {
            padding: 10px 20px; border: 2px solid #e0e0e0; border-radius: 10px;
            background: #fff; color: #1a1a2e; font-size: 14px; font-weight: 600;
            text-decoration: none; transition: border-color 0.2s, transform 0.15s;
        }
        .btn-back:hover { border-color: #0f3460; transform: translateY(-1px); color: #0f3460; }
        .btn-primary-solid {
            padding: 10px 24px; border: none; border-radius: 10px;
            background: linear-gradient(135deg, #0f3460, #1a1a2e); color: #fff;
            font-size: 14px; font-weight: 600; cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .btn-primary-solid:hover:not(:disabled) {
            transform: translateY(-1px); box-shadow: 0 6px 20px rgba(15, 52, 96, 0.4);
        }
        .btn-primary-solid:disabled { opacity: 0.55; cursor: default; }
        .panel {
            border: 1px solid #e8e8ef; border-radius: 12px; padding: 22px 24px;
            margin-bottom: 20px; background: #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .panel h2 {
            font-size: 15px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.6px; color: #1a1a2e; margin: 0 0 16px;
        }
        .facts { display: grid; grid-template-columns: 190px 1fr; gap: 8px 18px; font-size: 14px; }
        .facts dt { color: #6c757d; }
        .facts dd { margin: 0; color: #222; word-break: break-word; }
        .pill {
            display: inline-block; padding: 3px 12px; border-radius: 999px;
            font-size: 12px; font-weight: 700; letter-spacing: 0.3px;
        }
        .pill-ok   { background: #e3f6e9; color: #1c6b39; }
        .pill-warn { background: #fdf3e0; color: #8a5b06; }
        .pill-bad  { background: #fbe6e6; color: #8c1f1f; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 16px; }
        .muted { color: #6c757d; font-size: 13px; }
        .progress-wrap { margin-top: 16px; display: none; }
        .progress { height: 10px; }
        code { background: #f3f4f8; padding: 2px 6px; border-radius: 5px; color: #0f3460; }
        .toast-msg { position: fixed; top: 24px; right: 24px; z-index: 1080; }
    </style>
</head>
<body>

<div class="page-card">
    <div class="page-header">
        <div class="page-header-left">
            <i class="fas fa-right-left"></i>
            <h1>Signbank koppeling</h1>
        </div>
        <a href="/menu_beta/index.html" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Terug</a>
    </div>

    <div class="panel">
        <h2>Verbinding</h2>
        <dl class="facts">
            <dt>Status</dt>            <dd id="connStatus">…</dd>
            <dt>Server</dt>            <dd id="connHost">…</dd>
            <dt>Dataset</dt>           <dd id="connDataset">…</dd>
            <dt>API-sleutel</dt>       <dd id="keyStatus">…</dd>
        </dl>
        <div class="actions">
            <button class="btn-primary-solid" id="btnTest"><i class="fas fa-plug me-2"></i>Verbinding testen</button>
            <span class="muted" id="testResult"></span>
        </div>
    </div>

    <div class="panel">
        <h2>API-sleutel vervangen</h2>
        <p class="muted">De sleutel wordt buiten git bewaard, in <code id="keyFile">…</code>, en is
           nooit opnieuw uitleesbaar via deze pagina. Een nieuwe sleutel geldt direct.</p>
        <div class="row g-2">
            <div class="col-sm-8">
                <input type="password" class="form-control" id="newKey" autocomplete="off"
                       placeholder="Nieuwe Signbank API-sleutel">
            </div>
            <div class="col-sm-4">
                <button class="btn-primary-solid w-100" id="btnSaveKey">
                    <i class="fas fa-key me-2"></i>Opslaan
                </button>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>Glossenbestand</h2>
        <dl class="facts">
            <dt>Bestand</dt>          <dd><code id="dumpPath">…</code></dd>
            <dt>Laatst ververst</dt>  <dd id="dumpMtime">…</dd>
            <dt>Aantal glossen</dt>   <dd id="dumpEntries">…</dd>
            <dt>Grootte</dt>          <dd id="dumpSize">…</dd>
            <dt>Laatste run</dt>      <dd id="lastRun">…</dd>
        </dl>
        <div class="actions">
            <button class="btn-primary-solid" id="btnRefresh">
                <i class="fas fa-rotate me-2"></i>Nu verversen
            </button>
            <button class="btn-back" id="btnSample">Testronde (40 glossen)</button>
            <span class="muted" id="refreshNote"></span>
        </div>
        <div class="progress-wrap" id="progressWrap">
            <div class="progress"><div class="progress-bar" id="progressBar" style="width:0%"></div></div>
            <div class="muted mt-1" id="progressText"></div>
        </div>
    </div>

    <div class="panel">
        <h2>Automatisch verversen</h2>
        <div class="row g-2 align-items-center">
            <div class="col-sm-4">
                <select class="form-select" id="schedule">
                    <option value="off">Uit</option>
                    <option value="hourly">Elk uur</option>
                    <option value="daily">Dagelijks</option>
                </select>
            </div>
            <div class="col-sm-3">
                <input type="time" class="form-control" id="dailyTime" value="03:30">
            </div>
            <div class="col-sm-3">
                <button class="btn-primary-solid w-100" id="btnSchedule">
                    <i class="fas fa-clock me-2"></i>Opslaan
                </button>
            </div>
        </div>
        <p class="muted mt-3" id="scheduleNote"></p>
        <p class="muted">
            De planning wordt uitgevoerd door pythonCron, dat
            <code>signbank_sync/ecv_refresh.php</code> elk uur aanroept; dit scherm bepaalt
            of die aanroep daadwerkelijk ververst. Zonder die job blijft alleen
            handmatig verversen over.
        </p>
    </div>
</div>

<div class="toast-msg" id="toastContainer"></div>

<script>
    // Cosmetic guard, as on the other admin pages. The real one already ran:
    // this page is PHP and never reached a non-admin.
    if (typeof userRole !== 'undefined' && userRole !== 'admin') {
        window.location.href = '/menu_beta/index.html';
    }

    var API = '/menu_beta/php_api/signbank_admin.php';
    var pollTimer = null;

    function toast(msg, type) {
        var id = 'toast-' + Date.now();
        $('#toastContainer').append(
            '<div id="' + id + '" class="alert alert-' + (type || 'success') + ' shadow" role="alert">' +
            $('<div>').text(msg).html() + '</div>');
        setTimeout(function () { $('#' + id).fadeOut(300, function () { $(this).remove(); }); }, 4000);
    }

    function post(payload) {
        return $.ajax({
            url: API, method: 'POST', contentType: 'application/json',
            data: JSON.stringify(payload), dataType: 'json'
        });
    }

    function fmtDate(ts) {
        if (!ts) return '—';
        var d = new Date(ts * 1000);
        return d.toLocaleString('nl-NL', { dateStyle: 'medium', timeStyle: 'short' });
    }
    function fmtAge(ts) {
        if (!ts) return '';
        var mins = Math.round((Date.now() / 1000 - ts) / 60);
        if (mins < 60)   return ' (' + mins + ' min geleden)';
        if (mins < 1440) return ' (' + Math.round(mins / 60) + ' uur geleden)';
        return ' (' + Math.round(mins / 1440) + ' dagen geleden)';
    }
    function fmtSize(b) { return b ? (b / 1048576).toFixed(1) + ' MB' : '—'; }
    function pill(cls, text) { return '<span class="pill ' + cls + '">' + text + '</span>'; }

    function render(s) {
        if (!s.ok) {
            $('#connStatus').html(pill('pill-bad', 'configuratiefout'));
            $('#connHost').text(s.error || 'onbekend');
            return;
        }
        var keySet = s.key && s.key.set;
        $('#connStatus').html(keySet ? pill('pill-ok', 'ingesteld')
                                     : pill('pill-warn', 'geen sleutel'));
        $('#connHost').html('<code>' + (s.connection.base_url || '—') + '</code>');
        $('#connDataset').text((s.connection.dataset || '—') + ' (id ' + (s.connection.dataset_id || '?') + ')');
        $('#keyStatus').html(keySet
            ? (s.key.hint + ' · ' + s.key.length + ' tekens · bron: ' +
               (s.key.source === 'runtime' ? 'beheerscherm' : 'config.php'))
            : 'nog niet ingesteld');
        $('#keyFile').text(s.key ? s.key.file : '');

        var d = s.dump || {};
        $('#dumpPath').text(d.path || '—');
        $('#dumpMtime').text(d.exists ? fmtDate(d.mtime) + fmtAge(d.mtime) : 'bestaat niet');
        $('#dumpEntries').text(d.entries === null || d.entries === undefined ? '—' : d.entries.toLocaleString('nl-NL'));
        $('#dumpSize').text(fmtSize(d.size));

        var st = s.state || {}, r = st.last_result;
        if (r && r.ok) {
            $('#lastRun').html(pill('pill-ok', 'gelukt') + ' ' + r.entries + ' glossen in ' +
                (r.duration_ms / 1000).toFixed(1) + ' s' + (r.staging ? ' (testronde)' : '') +
                ' · ' + fmtDate(st.finished_at));
        } else if (r) {
            $('#lastRun').html(pill('pill-bad', 'mislukt') + ' ' +
                $('<div>').text(r.error || '').html() + ' · ' + fmtDate(st.finished_at));
        } else {
            $('#lastRun').text('nog niet gedraaid via dit scherm');
        }

        if (!s.writable) {
            $('#refreshNote').html(pill('pill-bad', 'niet schrijfbaar') +
                ' de webserver mag het glossenbestand niet vervangen');
        }

        $('#schedule').val(s.settings.schedule);
        $('#dailyTime').val(s.settings.daily_time);
        $('#scheduleNote').text(s.schedule_reason ? 'Nu: ' + s.schedule_reason : '');

        var running = st.status === 'running';
        $('#btnRefresh, #btnSample').prop('disabled', running);
        if (running) {
            var p = st.progress || {};
            var pct = p.total ? Math.round(100 * p.done / p.total) : 0;
            $('#progressWrap').show();
            $('#progressBar').css('width', pct + '%');
            $('#progressText').text(p.phase === 'enumerating'
                ? 'glossenlijst ophalen bij Signbank…'
                : p.done + ' / ' + (p.total || '?') + ' glossen opgehaald');
            if (!pollTimer) pollTimer = setInterval(load, 2000);
        } else {
            $('#progressWrap').hide();
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        }
    }

    function load() {
        $.getJSON(API).done(render).fail(function (x) {
            toast('Status ophalen mislukt (' + x.status + ')', 'danger');
        });
    }

    $(document).ready(function () {
        load();

        $('#btnTest').on('click', function () {
            var $b = $(this).prop('disabled', true);
            $('#testResult').text('bezig…');
            post({ action: 'test' }).done(function (r) {
                $('#testResult').text(r.ok
                    ? 'OK — glos ' + r.sample + ' opgehaald in ' + r.duration_ms + ' ms'
                    : 'mislukt — HTTP ' + r.status + (r.error ? ' ' + r.error : ''));
            }).fail(function (x) {
                $('#testResult').text('mislukt (' + x.status + ')');
            }).always(function () { $b.prop('disabled', false); });
        });

        $('#btnSaveKey').on('click', function () {
            var key = $('#newKey').val().trim();
            if (!key) { toast('Vul een sleutel in', 'warning'); return; }
            var $b = $(this).prop('disabled', true);
            post({ action: 'save_key', key: key }).done(function () {
                $('#newKey').val('');
                toast('Sleutel opgeslagen');
                load();
            }).fail(function (x) {
                toast('Opslaan mislukt: ' + ((x.responseJSON || {}).error || x.status), 'danger');
            }).always(function () { $b.prop('disabled', false); });
        });

        $('#btnSample').on('click', function () {
            var $b = $(this).prop('disabled', true);
            $('#refreshNote').text('testronde bezig — dit duurt ongeveer een minuut…');
            post({ action: 'refresh', mode: 'sample', limit: 40 }).done(function (r) {
                $('#refreshNote').text('testronde: ' + r.entries + ' glossen in ' +
                    (r.duration_ms / 1000).toFixed(1) + ' s (niet gepubliceerd)');
                load();
            }).fail(function (x) {
                $('#refreshNote').text('testronde mislukt: ' + ((x.responseJSON || {}).error || x.status));
            }).always(function () { $b.prop('disabled', false); });
        });

        $('#btnRefresh').on('click', function () {
            if (!confirm('Het volledige glossenbestand opnieuw ophalen bij Signbank? ' +
                         'Dit duurt enkele minuten.')) return;
            $('#refreshNote').text('gestart…');
            post({ action: 'refresh', mode: 'full' }).done(function () {
                toast('Verversen gestart');
                load();
            }).fail(function (x) {
                $('#refreshNote').text('starten mislukt: ' + ((x.responseJSON || {}).error || x.status));
            });
        });

        $('#btnSchedule').on('click', function () {
            var $b = $(this).prop('disabled', true);
            post({ action: 'schedule', schedule: $('#schedule').val(),
                   daily_time: $('#dailyTime').val() }).done(function () {
                toast('Planning opgeslagen');
                load();
            }).fail(function (x) {
                toast('Opslaan mislukt: ' + ((x.responseJSON || {}).error || x.status), 'danger');
            }).always(function () { $b.prop('disabled', false); });
        });
    });
</script>
</body>
</html>
