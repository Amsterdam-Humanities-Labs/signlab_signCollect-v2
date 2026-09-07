<?php
header('Content-Type: application/json');

// The Signbank ECV dump. It used to be read out of /web/menu_old, which was
// only ever where the legacy interface happened to keep it; signbank_ecv.php
// owns the canonical path now.
require_once __DIR__ . '/php_api/signbank_ecv.php';
$jsonFilePath = signbank_ecv_path();

// Check if the JSON file exists
if (!file_exists($jsonFilePath)) {
    echo json_encode(['error' => 'Glosses data file not found.']);
    exit;
}

// Load the JSON data
$jsonData = json_decode(file_get_contents($jsonFilePath), true);
if ($jsonData === null) {
    echo json_encode(['error' => 'Failed to parse glosses data.']);
    exit;
}

// Restructure data for easier lookup
$glossesDict = [];
$glossIdMap = []; // Map to track gloss IDs for quick duplicate checking

foreach ($jsonData as $item) {
    foreach ($item as $glossId => $glossData) {
        if (!is_array($glossData)) {
            continue;
        }
        
        // Check for Lemma ID Gloss: Dutch
        if (isset($glossData['Lemma ID Gloss: Dutch'])) {
            $glossName = $glossData['Lemma ID Gloss: Dutch'];
            $glossesDict[$glossName] = [
                'Signbank ID' => $glossId,
                'Gloss' => $glossName,
                'Type' => 'lemma',
                'ExactMatch' => true
            ];
            $glossIdMap[$glossId] = $glossName;
        }
        
        // Check for Senses: Dutch
        if (isset($glossData['Senses: Dutch']) && is_array($glossData['Senses: Dutch'])) {
            foreach ($glossData['Senses: Dutch'] as $senseKey => $senseValue) {
                if (!empty($senseValue)) {
                    // Store the sense with reference to its gloss
                    $glossesDict[$senseValue] = [
                        'Signbank ID' => $glossId,
                        'Gloss' => isset($glossData['Lemma ID Gloss: Dutch']) ? $glossData['Lemma ID Gloss: Dutch'] : '',
                        'Sense' => $senseValue,
                        'Type' => 'sense',
                        'ExactMatch' => true
                    ];
                }
            }
        }
    }
}

// Get the search query from the request
$query = isset($_GET['query']) ? trim($_GET['query']) : '';
if (empty($query)) {
    echo json_encode(['error' => 'No query provided.']);
    exit;
}

// Normalize the query for case-insensitive comparison
$normalizedQuery = strtolower($query);

// Search for the gloss
$exactMatches = [];
$partialMatches = [];

foreach ($glossesDict as $key => $data) {
    // Check for exact match (case insensitive)
    if (strtolower($key) === $normalizedQuery) {
        $data['MatchedTerm'] = $key;
        $exactMatches[] = $data;
    } 
    // Check for partial match
    elseif (stripos($key, $query) !== false) {
        $data['MatchedTerm'] = $key;
        $data['ExactMatch'] = false;
        $partialMatches[] = $data;
    }
}

// Return the combined results, with exact matches first
$result = array_merge($exactMatches, $partialMatches);

// Ensure we don't have duplicate signbank IDs in the results
$uniqueResults = [];
$seenIds = [];

foreach ($result as $item) {
    $signbankId = $item['Signbank ID'];
    if (!isset($seenIds[$signbankId])) {
        $uniqueResults[] = $item;
        $seenIds[$signbankId] = true;
    }
}

// Return the result
if (empty($uniqueResults)) {
    echo json_encode(['error' => 'No glosses found.']);
} else {
    echo json_encode(['results' => $uniqueResults]);
}
?>
