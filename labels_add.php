<?php

// signcollect-lib's install-root resolver: sc_path(), sc_dir(), sc_root().
// Vendored shim - it finds /web/lib/paths.php, or falls back to /web.
require_once __DIR__ . '/sc_paths.php';

// Server-side gate: these write form_data / labels, so a logged-in session is
// required - the page's /userProtect.js is only a browser redirect.
require_once __DIR__ . '/php_api/db.php';
require_once __DIR__ . '/php_api/session.php';
require_session();                                    // 401 JSON without a valid session

// Include database configuration
require_once sc_path('mysql_config.php');

// Set content type to JSON
header('Content-Type: application/json');

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => "Connection failed: " . $conn->connect_error]);
    exit;
}

// Set charset
$conn->set_charset("utf8");

// Handle GET request to fetch existing labels
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action']) && $_GET['action'] == 'get') {
    // Fetch unique labels from the database with their colors
    $sql = "SELECT label, color FROM labels WHERE label IS NOT NULL AND label != '' ORDER BY label ASC";
    $result = $conn->query($sql);
    
    if ($result === false) {
        http_response_code(500);
        echo json_encode(['error' => "Query failed: " . $conn->error]);
        exit;
    }
    
    $labels = [];
    while ($row = $result->fetch_assoc()) {
        $labels[] = [
            'name' => $row['label'],
            'color' => $row['color'] ?? '#e9ecef'
        ];
    }
    
    // Return the labels with their colors
    echo json_encode(['labels' => $labels]);
    exit;
}

// Handle POST request to add a new label
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'add') {
    // Validate label
    if (!isset($_POST['label']) || trim($_POST['label']) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Label cannot be empty']);
        exit;
    }
    
    $newLabel = trim($_POST['label']);
    
    // Check if the label already exists
    $checkSql = "SELECT COUNT(*) as count FROM labels WHERE label = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("s", $newLabel);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $row = $checkResult->fetch_assoc();
    
    if ($row['count'] > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'This label already exists']);
        exit;
    }
    
    // Create a placeholder record for this label
    $sql = "INSERT INTO labels (label) VALUES (?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $newLabel);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Label "' . htmlspecialchars($newLabel) . '" has been added successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add label: ' . $stmt->error]);
    }
    
    $stmt->close();
    exit;
}

// Handle POST request to delete a label
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'delete') {
    // Validate label
    if (!isset($_POST['label']) || trim($_POST['label']) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Label cannot be empty']);
        exit;
    }
    
    $labelToDelete = trim($_POST['label']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete the label from labels table
        $deleteSql = "DELETE FROM labels WHERE label = ?";
        $deleteStmt = $conn->prepare($deleteSql);
        $deleteStmt->bind_param("s", $labelToDelete);
        $deleteStmt->execute();
        
        // Update form_data table to remove this label from JSON arrays in the labels field
        // First check for rows where labels is not NULL and not empty
        $selectSql = "SELECT id, labels FROM form_data WHERE labels IS NOT NULL AND labels != '' AND JSON_CONTAINS(labels, ?)";
        $selectStmt = $conn->prepare($selectSql);
        $jsonValue = json_encode($labelToDelete);
        $selectStmt->bind_param("s", $jsonValue);
        $selectStmt->execute();
        $result = $selectStmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $labelsArray = json_decode($row['labels'], true);
            $index = array_search($labelToDelete, $labelsArray);
            if ($index !== false) {
                array_splice($labelsArray, $index, 1);
                $updatedLabels = json_encode($labelsArray);
                
                $updateSql = "UPDATE form_data SET labels = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("si", $updatedLabels, $row['id']);
                $updateStmt->execute();
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Label "' . htmlspecialchars($labelToDelete) . '" has been deleted successfully'
        ]);
        
    } catch (Exception $e) {
        // Roll back transaction on error
        $conn->rollback();
        
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete label: ' . $e->getMessage()]);
    }
    
    exit;
}

// Handle POST request to update a label
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'update') {
    // Validate input
    if (!isset($_POST['oldLabel']) || trim($_POST['oldLabel']) === '' || 
        !isset($_POST['newLabel']) || trim($_POST['newLabel']) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Both old and new label values are required']);
        exit;
    }
    
    $oldLabel = trim($_POST['oldLabel']);
    $newLabel = trim($_POST['newLabel']);
    
    // Check if new name already exists (but isn't the old name)
    if ($oldLabel !== $newLabel) {
        $checkSql = "SELECT COUNT(*) as count FROM labels WHERE label = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("s", $newLabel);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $row = $checkResult->fetch_assoc();
        
        if ($row['count'] > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'A label with this name already exists']);
            exit;
        }
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update label in labels table
        $updateLabelSql = "UPDATE labels SET label = ? WHERE label = ?";
        $updateLabelStmt = $conn->prepare($updateLabelSql);
        $updateLabelStmt->bind_param("ss", $newLabel, $oldLabel);
        $updateLabelStmt->execute();
        
        // Update form_data table to replace this label in JSON arrays
        // First check for rows where labels is not NULL and not empty
        $selectSql = "SELECT id, labels FROM form_data WHERE labels IS NOT NULL AND labels != '' AND JSON_CONTAINS(labels, ?)";
        $selectStmt = $conn->prepare($selectSql);
        $jsonValue = json_encode($oldLabel);
        $selectStmt->bind_param("s", $jsonValue);
        $selectStmt->execute();
        $result = $selectStmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $labelsArray = json_decode($row['labels'], true);
            $index = array_search($oldLabel, $labelsArray);
            if ($index !== false) {
                $labelsArray[$index] = $newLabel;
                $updatedLabels = json_encode($labelsArray);
                
                $updateSql = "UPDATE form_data SET labels = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("si", $updatedLabels, $row['id']);
                $updateStmt->execute();
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Label has been updated successfully'
        ]);
        
    } catch (Exception $e) {
        // Roll back transaction on error
        $conn->rollback();
        
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update label: ' . $e->getMessage()]);
    }
    
    exit;
}

// Handle POST request to update label color
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'updateColor') {
    // Validate input
    if (!isset($_POST['label']) || trim($_POST['label']) === '' || 
        !isset($_POST['color']) || trim($_POST['color']) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Both label and color values are required']);
        exit;
    }
    
    $label = trim($_POST['label']);
    $color = trim($_POST['color']);
    
    // Validate hex color format
    if (!preg_match('/^#[a-f0-9]{6}$/i', $color)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid color format. Please use hex format (#RRGGBB)']);
        exit;
    }
    
    // Update label color
    $updateSql = "UPDATE labels SET color = ? WHERE label = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param("ss", $color, $label);
    
    if ($updateStmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Label color updated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update label color: ' . $updateStmt->error]);
    }
    
    $updateStmt->close();
    exit;
}

// Handle other cases - invalid request
http_response_code(400);
echo json_encode(['error' => 'Invalid request']);

// Close the database connection
$conn->close();
?>
