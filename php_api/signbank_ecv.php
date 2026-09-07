<?php

// signcollect-lib's install-root resolver: sc_path(), sc_dir(), sc_root().
// Vendored shim - it finds /web/lib/paths.php, or falls back to /web.
require_once __DIR__ . '/../sc_paths.php';

/**
 * The Signbank ECV export: one ~11MB JSON dump of every gloss in the
 * connected Signbank with its senses, phonology, dictionary link and video
 * URL. It is rebuilt out of band by the Signbank connector script, so
 * nothing here writes it - we only read whatever is currently on disk.
 *
 * The dump lives in the connector's own directory, /web/signbank_data, and
 * not inside any one interface - it is shared data that four components read
 * (this one, zin getSenses.php, hh getGlosses.php, nmm findSBid.py and
 * liteConvert.py), and it belongs to whatever rebuilds it.
 *
 * It used to sit at the docroot root, /web/glosses_transformed.json, and on
 * a host that predates the connector it still does: the web server cannot
 * write /web, so a host where the dump can be rebuilt keeps it somewhere the
 * web user owns. Both are checked, new location first, so this accessor
 * answers correctly on either kind of host without anything having to know
 * which one it is on.
 */

function signbank_ecv_path(): string {
    static $resolved = null;
    if ($resolved !== null) return $resolved;
    foreach ([sc_path('signbank_data/glosses_transformed.json'),
              sc_path('glosses_transformed.json')] as $candidate) {
        if (is_readable($candidate)) return $resolved = $candidate;
    }
    // Neither exists: name the one a rebuild would create, so the error a
    // caller reports points at where the file is supposed to be.
    return $resolved = sc_path('signbank_data/glosses_transformed.json');
}

function signbank_ecv_available(): bool {
    return is_readable(signbank_ecv_path());
}

/**
 * Every ECV entry as ['id' => signbank gloss id, 'd' => field map].
 *
 * On disk the dump is a list of single-key objects - `[{"3808": {...}}, …]` -
 * which is awkward to walk more than once, so flatten it here and let
 * callers just foreach. Decoding 11MB is the expensive part; the static
 * cache means a request that both searches and suggests pays it once.
 *
 * Returns [] when the dump is absent. That is a normal state on a host whose
 * connector has not run yet, and every caller degrades to its own data
 * rather than failing the request.
 */
function signbank_ecv_entries(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $cached = [];
    if (!signbank_ecv_available()) return $cached;

    $decoded = json_decode((string)file_get_contents(signbank_ecv_path()), true);
    if (!is_array($decoded)) return $cached;

    foreach ($decoded as $item) {
        if (!is_array($item)) continue;
        foreach ($item as $id => $details) {
            if (is_array($details)) $cached[] = ['id' => (string)$id, 'd' => $details];
        }
    }
    return $cached;
}

/**
 * Senses arrive keyed "1", "2", … rather than as a list, and a gloss with no
 * senses in one language simply omits the key. Flatten to a list of strings.
 */
function signbank_ecv_senses(array $details, string $key): array {
    $v = $details[$key] ?? null;
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $s) {
        $s = trim((string)$s);
        if ($s !== '') $out[] = $s;
    }
    return $out;
}

/**
 * ECV field name → the `form_data` column the interface stores it in. The
 * mapping is the one the old wizard hardcoded in checkGlos.php/createGlos.php;
 * it matches PHONOLOGY_FIELDS in js/phonology.js, so a wizard result can be
 * dropped straight into a new row or the phonology editor.
 */
function signbank_ecv_phonology_map(): array {
    return [
        'Handedness'                      => 'Handeness',
        'Strong Hand'                     => 'strongHand',
        'Weak Hand'                       => 'weakHand',
        'Handshape Change'                => 'HandshapeChange',
        'Relation Between Articulators'   => 'RelationArticulators',
        'Location'                        => 'handLocation',
        'Contact Type'                    => 'ContactType',
        'Movement Shape'                  => 'MovementShape',
        'Movement Direction'              => 'MovementDirection',
        'Relative Orientation: Movement'  => 'relativeOrienationMovement',
        'Relative Orientation: Location'  => 'relativeOrienationLocation',
        'Orientation Change'              => 'orientationChange',
        'Repeated Movement'               => 'RepeatedMovement',
        'Alternating Movement'            => 'AlternatingMovement',
        'Virtual Object'                  => 'virtualObjectt',
        'Phonology Other'                 => 'phonologyOther',
        'Mouth Gesture'                   => 'mouthGesture',
        'Mouthing'                        => 'mouthing',
        'Phonetic Variation'              => 'phoneticVariation',
    ];
}

/** The phonology block of one ECV entry, keyed by interface column name. */
function signbank_ecv_phonology(array $details): array {
    $out = [];
    foreach (signbank_ecv_phonology_map() as $ecvKey => $column) {
        $out[$column] = (string)($details[$ecvKey] ?? '');
    }
    return $out;
}

/** One ECV entry rendered in the shape the Glos Wizard renders cards from. */
function signbank_ecv_result(string $id, array $details, $reason): array {
    return [
        'source'       => 'signbank',
        'glos'         => (string)($details['Annotation ID Gloss: Dutch']   ?? ''),
        'glos_engels'  => (string)($details['Annotation ID Gloss: English'] ?? ''),
        'senses'       => signbank_ecv_senses($details, 'Senses: Dutch'),
        'sensesEngels' => signbank_ecv_senses($details, 'Senses: English'),
        'reason'       => $reason,
        'video'        => (string)($details['Video'] ?? ''),
        'link'         => (string)($details['Link']  ?? ''),
        'signbank'     => $id,
        'web_dict'     => (string)($details['In The Web Dictionary'] ?? '') === 'True',
        'phonology'    => signbank_ecv_phonology($details),
    ];
}
