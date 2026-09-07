<?php
/**
 * The Signbank ECV export: one ~11MB JSON dump of every gloss in the
 * connected Signbank with its senses, phonology, dictionary link and video
 * URL. It is rebuilt out of band by the Signbank connector script, so
 * nothing here writes it - we only read whatever is currently on disk.
 *
 * The dump lives at the docroot root, not inside any one interface. That is
 * where the other consumers already look (nmm/findSBid.py and
 * nmm/liteConvert.py both open /web/glosses_transformed.json); only
 * get_glosses.php and the old Glos Wizard read the copy under /web/menu_old,
 * which no longer exists.
 */

function signbank_ecv_path(): string {
    return '/web/glosses_transformed.json';
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
