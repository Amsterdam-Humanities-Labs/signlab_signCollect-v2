<?php
// Turn off PHP error reporting to avoid HTML in JSON response
error_reporting(0);
ini_set('display_errors', 0);

// Include database configuration
require_once '/web/mysql_config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Function to safely output JSON response
function outputJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

try {
    // Initialize response array
    $response = [
        'errors' => [],
        'success' => [],
        'specialChars' => [],
        'duplicatesInText' => [],
        'duplicatesWithSuffix' => [],
        'glossMatches' => [],
        'updatedExisting' => [],
        'updatedLabels' => [],  // Track updated labels
        'labelsOnlyUpdated' => [],  // Track glosses that had only their labels updated
        'existsInSenses' => []  // Track words that already exist in senses
    ];
    
    // Check if this is a POST request with wordList
    if ($_SERVER["REQUEST_METHOD"] != "POST" || !isset($_POST['wordList'])) {
        outputJsonResponse(['error' => 'Invalid request method or missing wordList'], 400);
    }
    
    // Get userid from userId
    if (!isset($_POST['userId'])) {
        outputJsonResponse(['error' => 'Missing user ID'], 400);
    }
    $userId = $_POST['userId'];
    
    $wie = [];
    $wie[] = $userId;
    $wie = json_encode($wie);
    
    // Validate thema parameter first
    if (!isset($_POST['thema']) || trim($_POST['thema']) === '') {
        outputJsonResponse([
            'error' => 'Please select a thema or enter a custom thema before adding words.',
            'errorType' => 'missing_thema'
        ], 400);
    }

    $thema = trim($_POST['thema']);

    // Process labels if provided
    $labels = isset($_POST['labels']) ? trim($_POST['labels']) : '';
    
    // Get checkbox values
    $checkDuplicatesWithSuffix = isset($_POST['checkDuplicatesWithSuffix']) && $_POST['checkDuplicatesWithSuffix'] === 'true';
    $linkWithSignbankID = isset($_POST['linkWithSignbankID']) && $_POST['linkWithSignbankID'] === 'true';
    $checkWordExistsInSenses = isset($_POST['checkWordExistsInSenses']) && $_POST['checkWordExistsInSenses'] === 'true';
    
    // Create database connection
    $conn = new mysqli($servername, $username, $password, $database);
    
    // Check connection
    if ($conn->connect_error) {
        outputJsonResponse(['error' => "Connection failed: " . $conn->connect_error], 500);
    }
    
    // Process word data if available
    $wordData = [];
    if (isset($_POST['wordData'])) {
        $wordData = json_decode($_POST['wordData'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            outputJsonResponse(['error' => 'Invalid wordData JSON: ' . json_last_error_msg()], 400);
        }
    }
    
    // Split the input by newline
    $words = preg_split('/\r\n|\r|\n/', $_POST['wordList']);
    
    // Array to track unique words in the input
    $uniqueWords = [];
    // Track glosses we've already processed to avoid duplicates
    $processedGlosses = [];
    
    // Process each word
    foreach ($words as $word) {
        $word = trim($word);
        
        // Skip empty lines
        if (empty($word)) {
            continue;
        }
        
        // Check for special characters (only alphanumeric, spaces, and hyphens allowed)
        if (!preg_match('/^[a-zA-Z0-9\s-]+$/', $word)) {
            $response['specialChars'][] = htmlspecialchars($word);
            continue;
        }
        
        // Check for duplicates within the input text (case insensitive)
        $wordLower = strtolower($word);
        if (in_array($wordLower, $uniqueWords)) {
            $response['duplicatesInText'][] = htmlspecialchars($word);
            continue;
        }
        
        // Add to unique words tracker
        $uniqueWords[] = $wordLower;
        
        // Check if we have gloss information for this word from the search
        $gloss = '';
        $signbankId = '';
        $foundGloss = false;
        $updateLabelsOnly = false;
        
        if (isset($wordData[$word]) && $wordData[$word]['glossFound']) {
            $gloss = $wordData[$word]['gloss'];
            $signbankId = $wordData[$word]['signbankId'];
            $foundGloss = true;
            $matchType = $wordData[$word]['matchType'] ?? '';
            $updateLabelsOnly = isset($wordData[$word]['updateLabelsOnly']) ? $wordData[$word]['updateLabelsOnly'] : false;
            
            // Check if we've already processed this gloss to avoid duplicates
            if (in_array($gloss, $processedGlosses)) {
                $updateLabelsOnly = true;
            }
            
            // If Signbank linking is enabled
            if ($linkWithSignbankID) {
                // Check if this gloss already exists in form_data
                $checkStmt = $conn->prepare("SELECT id, labels FROM form_data WHERE glos = ? AND extern = 1");
                $checkStmt->bind_param("s", $gloss);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                
                if ($checkResult->num_rows > 0) {
                    // The gloss already exists in the database, update its labels
                    $row = $checkResult->fetch_assoc();
                    $existingId = $row['id'];
                    $existingLabels = json_decode($row['labels'] ?? '[]', true);
                    
                    // Parse the new labels we want to add
                    $newLabels = json_decode($labels, true);
                    
                    // Merge labels (avoid duplicates)
                    if (is_array($existingLabels) && is_array($newLabels)) {
                        $mergedLabels = array_unique(array_merge($existingLabels, $newLabels));
                        $mergedLabelsJson = json_encode($mergedLabels);
                        
                        // Update the existing record with the merged labels
                        $updateStmt = $conn->prepare("UPDATE form_data SET labels = ? WHERE id = ?");
                        $updateStmt->bind_param("si", $mergedLabelsJson, $existingId);
                        $updateStmt->close();
                        
                        // Add to the appropriate response list
                        if ($updateLabelsOnly) {
                            $response['labelsOnlyUpdated'][] = [
                                'word' => htmlspecialchars($word),
                                'gloss' => htmlspecialchars($gloss)
                            ];
                        } else {
                            $response['updatedLabels'][] = [
                                'word' => htmlspecialchars($word),
                                'glos' => htmlspecialchars($gloss)
                            ];
                        }
                        
                        // Add to processed glosses to avoid duplicates
                        if (!in_array($gloss, $processedGlosses)) {
                            $processedGlosses[] = $gloss;
                        }
                        
                        $checkStmt->close();
                        continue; // Skip to the next word, no need to insert
                    }
                }
                $checkStmt->close();
                
                // Add to glossMatches for tracking (only if not updating labels only)
                if (!$updateLabelsOnly) {
                    $response['glossMatches'][] = [
                        'word' => htmlspecialchars($word),
                        'gloss' => htmlspecialchars($gloss),
                        'signbankId' => htmlspecialchars($signbankId),
                        'matchType' => $matchType
                    ];
                }
            }
        }
        
        // If we're just updating labels and already processed this word, skip insert
        if ($updateLabelsOnly) {
            continue;
        }
        
        // If linking with Signbank ID and found a gloss, use the original Signbank gloss
        if ($linkWithSignbankID && $foundGloss) {
            $wordWithSuffix = $gloss;
            // Add to processed glosses to avoid duplicates
            if (!in_array($gloss, $processedGlosses)) {
                $processedGlosses[] = $gloss;
            }
        } else {
            // Replace spaces with hyphens for the gloss
            $wordForGloss = str_replace(' ', '-', $word);
            // Find an available suffix for this word if no gloss found
            $wordWithSuffix = findAvailableSuffix($conn, $wordForGloss, $checkDuplicatesWithSuffix);
            
            if ($wordWithSuffix === false) {
                // All suffixes from A-Z are taken
                $response['errors'][] = htmlspecialchars($word) . " (all suffixes A-Z are already used)";
                continue;
            }
            
            $wordWithSuffix = strtoupper($wordWithSuffix);
        }
        
        // If duplicate checking with suffixes is enabled, check first
        if ($checkDuplicatesWithSuffix && wordExistsWithOrWithoutSuffix($conn, $word)) {
            $response['duplicatesWithSuffix'][] = htmlspecialchars($word);
            continue;
        }

        //we also want to check if glos without suffix already exists in senses
        //remove everything including and after -
        $wordWithoutSuffix = preg_replace('/-[A-Z]$/', '', $wordWithSuffix);
        $wordWithoutSuffix = strtolower($wordWithoutSuffix);
        
        // Check if this word already exists in any senses field (only if checkbox is enabled)
        if ($checkWordExistsInSenses && checkIfWordExistsInSenses($conn, $wordWithoutSuffix)) {
            $response['existsInSenses'][] = htmlspecialchars($word);
            continue;
        }
        
        // DOUBLE CHECK if this exact glos already exists to avoid duplicates
        $checkGlosStmt = $conn->prepare("SELECT id, labels FROM form_data WHERE glos = ? AND extern = 1");
        $checkGlosStmt->bind_param("s", $wordWithSuffix);
        $checkGlosStmt->execute();
        $checkGlosResult = $checkGlosStmt->get_result();
        
        if ($checkGlosResult->num_rows > 0) {
            // The exact glos already exists - update its labels instead of creating a new entry
            $row = $checkGlosResult->fetch_assoc();
            $existingId = $row['id'];
            $existingLabels = json_decode($row['labels'] ?? '[]', true);
            
            // Parse the new labels we want to add
            $newLabels = json_decode($labels, true);
            
            // Merge labels (avoid duplicates)
            if (is_array($existingLabels) && is_array($newLabels)) {
                $mergedLabels = array_unique(array_merge($existingLabels, $newLabels));
                $mergedLabelsJson = json_encode($mergedLabels);
                
                // Update the existing record with the merged labels
                $updateGlosStmt = $conn->prepare("UPDATE form_data SET labels = ? WHERE id = ?");
                $updateGlosStmt->bind_param("si", $mergedLabelsJson, $existingId);
                $updateGlosStmt->execute();
                $updateGlosStmt->close();
                
                // Add to updated labels list
                $response['updatedLabels'][] = [
                    'word' => htmlspecialchars($word),
                    'glos' => htmlspecialchars($wordWithSuffix)
                ];
                
                $checkGlosStmt->close();
                continue; // Skip to the next word - no need to insert
            }
        }
        $checkGlosStmt->close();
        
        // Word with available suffix found, prepare to add it to the database
        $wordToSense = strtolower($word);
        $senses = json_encode([$wordToSense]);
        
        // Insert the new record
        $insertStmt = $conn->prepare("INSERT INTO form_data (woord, extern, thema, wie, labels, senses, glos, signbank) VALUES (?, 1, ?, ?, ?, ?, ?, ?)");
        $insertStmt->bind_param("sssssss", $wordToSense, $thema, $wie, $labels, $senses, $wordWithSuffix, $signbankId);
        
        if ($insertStmt->execute()) {
            $response['success'][] = htmlspecialchars($wordWithSuffix) . ($foundGloss ? " (linked to Signbank ID: $signbankId)" : "");
        } else {
            // If insertion fails
            $response['errors'][] = htmlspecialchars($wordWithSuffix) . " (insertion failed)";
        }
        
        $insertStmt->close();
    }
    
    $conn->close();
    
    // Add the thema used to the response
    $response['thema'] = $thema;
    
    // Return JSON response
    outputJsonResponse($response);
    
} catch (Exception $e) {
    // Catch any exceptions and return as JSON error
    outputJsonResponse([
        'error' => 'An error occurred: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 500);
}

// Function to find the first available suffix for a word
function findAvailableSuffix($conn, $word, $checkDuplicatesWithOrWithoutSuffix = false) {
    // Replace spaces with hyphens in the word
    $originalWord = str_replace(' ', '-', $word);
    $originalWordUpper = strtoupper($originalWord);
    
    // First check if the word exists with or without a suffix using LIKE
    $stmt = $conn->prepare("SELECT glos FROM form_data WHERE (glos = ? OR glos = ? OR glos LIKE ? OR glos LIKE ?) AND extern = 1");
    $likePattern = $originalWord . "-%";
    $likePatternUpper = $originalWordUpper . "-%";
    $stmt->bind_param("ssss", $originalWord, $originalWordUpper, $likePattern, $likePatternUpper);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // If no results, use the original word without suffix
    if ($result->num_rows == 0) {
        $stmt->close();
        return $originalWord;
    }
    
    // Collect all existing words and their suffixes
    $existingWords = [];
    $hasExactMatch = false;
    $hasSuffixVariants = false;
    
    while ($row = $result->fetch_assoc()) {
        $existingWords[] = $row['glos'];
        if ($row['glos'] === $originalWord || $row['glos'] === $originalWordUpper) {
            $hasExactMatch = true;
        }
        // Check if there are already suffixed variants
        if (preg_match('/' . preg_quote($originalWordUpper, '/') . '-[A-Z]$/', $row['glos'])) {
            $hasSuffixVariants = true;
        }
    }
    $stmt->close();
    
    // If "check for duplicates" is enabled and there's an exact match, return false
    // This will be caught earlier in the code and word will be added to duplicatesWithSuffix
    if ($checkDuplicatesWithOrWithoutSuffix && ($hasExactMatch || $hasSuffixVariants)) {
        return false;
    }
    
    // If there's an exact match without suffix
    if ($hasExactMatch) {
        $wordWithASuffix = strtoupper($originalWord . "-A");
        
        // Check if -A already exists
        if (!in_array($wordWithASuffix, $existingWords)) {
            // Rename the original word to have -A suffix
            $updateStmt = $conn->prepare("UPDATE form_data SET glos = ? WHERE (glos = ? OR glos = ?) AND extern = 1");
            $updateStmt->bind_param("sss", $wordWithASuffix, $originalWord, $originalWordUpper);
            $updateStmt->execute();
            $updateStmt->close();
            
            return strtoupper($originalWord . "-B");
        }
    }
    
    // Find the highest suffix already in use
    $highestSuffix = null;
    foreach ($existingWords as $existingWord) {
        // Extract suffix if it exists
        if (preg_match('/-([A-Z])$/', $existingWord, $matches)) {
            $suffix = $matches[1];
            if ($highestSuffix === null || $suffix > $highestSuffix) {
                $highestSuffix = $suffix;
            }
        }
    }
    
    // Determine the next suffix to use
    $nextSuffix = $highestSuffix ? chr(ord($highestSuffix) + 1) : 'A';
    
    // Check if we've exhausted all suffixes
    if (ord($nextSuffix) > ord('Z')) {
        return false; // All suffixes A-Z are taken
    }
    
    return strtoupper($originalWord . "-" . $nextSuffix);
}

// Function to check if a word exists in any senses field
function checkIfWordExistsInSenses($conn, $word) {
    $stmt = $conn->prepare("SELECT id FROM form_data WHERE senses IS NOT NULL AND senses != '' AND JSON_VALID(senses) AND JSON_CONTAINS(senses, ?) AND extern = 1");
    $searchWord = json_encode($word);
    $stmt->bind_param("s", $searchWord);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $exists = ($result->num_rows > 0);
    $stmt->close();
    
    return $exists;
}

// Function to check if a word exists with or without suffix
function wordExistsWithOrWithoutSuffix($conn, $word) {
    // Replace spaces with hyphens in the word
    $originalWord = str_replace(' ', '-', $word);
    $originalWordUpper = strtoupper($originalWord);
    
    // Check if the word exists with or without a suffix using LIKE
    $stmt = $conn->prepare("SELECT glos FROM form_data WHERE (glos = ? OR glos = ? OR glos LIKE ? OR glos LIKE ?) AND extern = 1");
    $likePattern = $originalWord . "-%";
    $likePatternUpper = $originalWordUpper . "-%";
    $stmt->bind_param("ssss", $originalWord, $originalWordUpper, $likePattern, $likePatternUpper);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $exists = ($result->num_rows > 0);
    $stmt->close();
    
    return $exists;
}
?>
