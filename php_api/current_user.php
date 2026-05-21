<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';
require_once __DIR__ . '/../signbank_sync/client.php';

$s = require_session();
$cfg = signbank_config();
$s['signbankPublicUrl'] = rtrim((string)($cfg['public_url'] ?? 'https://signbank.cls.ru.nl'), '/');

// Look up persisted user prefs (gracefully tolerates an unmigrated `users` table).
$defaultContext = 'signio';
$defaultDataset = dataset_default_code();
$allowed        = [$defaultDataset];
$language       = 'nl';
try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT default_context, default_dataset, allowed_datasets, lang FROM users WHERE userId = ?");
    $stmt->execute([(int)$s['userId']]);
    $row = $stmt->fetch();
    if ($row) {
        if (in_array($row['default_context'] ?? null, ['signio','signbank'], true)) {
            $defaultContext = $row['default_context'];
        }
        if (!empty($row['default_dataset'])) $defaultDataset = $row['default_dataset'];
        if (!empty($row['allowed_datasets'])) {
            $d = json_decode($row['allowed_datasets'], true);
            if (is_array($d)) $allowed = array_values(array_filter($d, 'is_string'));
        }
        // Normalize legacy 3-char codes (eng/nld) and unknowns to 2-char nl/en.
        $rawLang = strtolower(trim((string)($row['lang'] ?? '')));
        if (in_array($rawLang, ['en', 'eng', 'english'], true))        $language = 'en';
        elseif (in_array($rawLang, ['nl', 'nld', 'dutch', 'nederlands'], true)) $language = 'nl';
    }
} catch (Throwable $e) {
    // leave defaults
}
$s['defaultContext'] = $defaultContext;
$s['language']       = $language;

// datasets payload for the JS switcher
$reg = datasets_registry();
$s['datasets'] = array_values(array_map(fn($c) => [
    'code'              => $c,
    'label'             => $reg[$c]['label'] ?? $c,
    'hasExternSubview'  => (bool)($reg[$c]['has_extern_subview'] ?? true),
    'hideNavMenuItems'  => (bool)($reg[$c]['hide_nav_menu_items'] ?? false),
], array_filter($allowed, fn($c) => isset($reg[$c]))));
$s['defaultDataset'] = in_array($defaultDataset, $allowed, true) ? $defaultDataset
                       : ($allowed[0] ?? dataset_default_code());
$s['activeDataset']  = $s['defaultDataset'];

json_response($s);
