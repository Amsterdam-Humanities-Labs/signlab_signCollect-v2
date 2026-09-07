<?php
/**
 * Glos Wizard naming - given a word, propose the gloss name to create.
 *
 * NGT gloss names are unique, and a second sign for the same word gets a
 * single-letter suffix: TWIX, then TWIX-A, TWIX-B, … So the answer to "may I
 * create GLOS?" is either "yes, that name is free" or "no, but GLOS-C is".
 * Names are taken if they exist locally *or* in the Signbank ECV, because a
 * name Signbank already uses cannot be reused here either.
 *
 * Ported from the first phase of menu_old/createGlos.php, with its
 * findNextFreeIdentifier() bug fixed: that function looked only at the last
 * name it happened to have collected and incremented its letter, so a
 * collection holding TWIX and TWIX-B (with TWIX-A withdrawn) proposed
 * TWIX-C and left the free name unused. This returns the first free
 * candidate, which is what the caller has always meant by "next".
 *
 * GET ?glos=<word>  ->  { input, glos, base, exists, taken, senses, ecv }
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';
require_once __DIR__ . '/signbank_ecv.php';

$session = require_session();

$raw = trim((string)($_GET['glos'] ?? ''));
if ($raw === '') json_response(['error' => 'glos_required'], 400);

// The wizard's input field normalises as you type; normalise again here so
// the endpoint is correct on its own and not only when called from that field.
$input = mb_strtoupper(preg_replace('/\s+/u', '-', $raw));

// Strip an existing single-letter suffix so "TWIX-B" and "TWIX" ask the same
// question. Anything longer than one letter is part of the name, not a suffix.
$base = preg_replace('/[-+][A-Z]$/u', '', $input);

$candidates = [$base];
for ($c = 'A'; strlen($c) === 1; $c = chr(ord($c) + 1)) {
    $candidates[] = $base . '-' . $c;
    if ($c === 'Z') break;
}

$pdo   = db();
$ds    = require_dataset($pdo, $session, null);
$table = $ds['table'];

$taken = [];

$in   = implode(',', array_fill(0, count($candidates), '?'));
$stmt = $pdo->prepare("SELECT glos FROM `$table` WHERE glos IN ($in)");
$stmt->execute($candidates);
foreach ($stmt->fetchAll() as $row) $taken[mb_strtoupper((string)$row['glos'])] = true;

$wanted = array_flip($candidates);
foreach (signbank_ecv_entries() as $entry) {
    $name = mb_strtoupper((string)($entry['d']['Annotation ID Gloss: Dutch'] ?? ''));
    if ($name !== '' && isset($wanted[$name])) $taken[$name] = true;
}

$suggestion = null;
foreach ($candidates as $name) {
    if (!isset($taken[$name])) { $suggestion = $name; break; }
}

json_response([
    'input'  => $input,
    'base'   => $base,
    // Every one of A..Z taken is not a case anyone has ever hit; say so
    // rather than proposing a name that would collide.
    'glos'   => $suggestion,
    'exists' => $taken !== [],
    'taken'  => array_values(array_intersect($candidates, array_keys($taken))),
    // The default sense, the way the old wizard seeded it: the gloss name
    // read back as ordinary words.
    'senses' => [mb_strtolower(str_replace('-', ' ', $base))],
    'ecv'    => signbank_ecv_available(),
]);
