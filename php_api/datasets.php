<?php
/**
 * Hardcoded datasets registry.
 * Adding a new dataset = one entry here + (separately) creating the table.
 *
 *   code:                 short identifier, used in URLs/requests
 *   label:                shown in the UI switcher
 *   table:                MySQL table — MUST be a whitelisted value (never
 *                         user-supplied) because callers interpolate it
 *                         straight into SQL via backticks
 *   matched_zog_clause:   SQL fragment that goes inside the
 *                         matched_transcriptions EXISTS subqueries; uses
 *                         the placeholders {mt} (alias of matched_transcriptions)
 *                         and {f} (alias of the gloss table). Set per-dataset
 *                         because NGT's existing logic depends on extern;
 *                         LSM uses a simple zOg='lsm' tag.
 *   signbank_dataset_id:  numeric id on the connected Signbank install,
 *                         or null if this dataset isn't synced to Signbank yet
 *   signbank_acronym:     dataset acronym on the connected Signbank install
 */
function datasets_registry(): array {
    return [
        'ngt' => [
            'code'                => 'ngt',
            'label'               => 'NGT',
            'table'               => 'form_data',
            'matched_zog_clause'  => "(({f}.extern = '1' AND {mt}.zOg IN ('labels','extern'))"
                                   . " OR ({f}.extern IS NULL AND {mt}.zOg = 'Glos'))",
            'signbank_dataset_id' => 2,            // local install
            'signbank_acronym'    => 'NGT',
        ],
        'lsm' => [
            'code'                => 'lsm',
            'label'               => 'LSM',
            'table'               => 'lsm_data',
            'matched_zog_clause'  => "{mt}.zOg = 'lsm'",
            'signbank_dataset_id' => 3,            // local Signbank LSM dataset
            'signbank_acronym'    => 'LSM',
        ],
    ];
}

/**
 * Resolve a dataset code against the caller's allowed list.
 *   - $code === null              → return the first allowed dataset (default).
 *   - $code in registry + allowed → return its entry.
 *   - $code set but not allowed   → return false so caller can emit 403.
 *   - $code set but not in reg    → return false (treat unknown as forbidden).
 * Never silently rewrite a forbidden request to an allowed one.
 */
function dataset_resolve(?string $code, array $userAllowed) {
    $reg = datasets_registry();
    if ($code === null || $code === '') {
        foreach ($userAllowed as $c) if (isset($reg[$c])) return $reg[$c];
        return $reg['ngt']; // belt-and-braces: registry always has ngt
    }
    if (!isset($reg[$code])) return false;
    if (!in_array($code, $userAllowed, true)) return false;
    return $reg[$code];
}

/** Whitelisted table-name lookup — safe to interpolate via backticks. */
function dataset_table(string $code): string {
    $reg = datasets_registry();
    if (!isset($reg[$code])) {
        throw new InvalidArgumentException("Unknown dataset code: $code");
    }
    return $reg[$code]['table'];
}

/**
 * Render the matched_transcriptions zOg/extern clause for $code with the
 * caller's chosen aliases. Used inside the studio-video EXISTS subqueries.
 */
function dataset_matched_zog_clause(string $code, string $mtAlias = 'mt', string $fAlias = 'f'): string {
    $reg = datasets_registry();
    if (!isset($reg[$code])) {
        throw new InvalidArgumentException("Unknown dataset code: $code");
    }
    $tpl = $reg[$code]['matched_zog_clause'];
    return strtr($tpl, ['{mt}' => $mtAlias, '{f}' => $fAlias]);
}

/**
 * Build a `EXISTS (SELECT 1 FROM matched_transcriptions ... )` predicate
 * for "this gloss row has at least one non-deleted studio video".
 */
function dataset_studio_video_exists(string $code, string $fAlias): string {
    $mtZog = dataset_matched_zog_clause($code, 'mt', $fAlias);
    return "EXISTS (SELECT 1 FROM matched_transcriptions mt
                    WHERE mt.m_transcription REGEXP '^[0-9]+$'
                      AND CAST(mt.m_transcription AS UNSIGNED) = $fAlias.id
                      AND (mt.added IS NULL OR UPPER(mt.added) <> 'DELETE')
                      AND $mtZog)";
}

/** Default dataset code returned when a user record has no default set. */
function dataset_default_code(): string { return 'ngt'; }
