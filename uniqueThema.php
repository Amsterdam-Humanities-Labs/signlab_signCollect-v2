<?php
// Include database configuration
include('/web/mysql_config.php');

// Create connection
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// Determine query based on 'extern' parameter
if (isset($_GET['extern'])) {
    $extern = intval($_GET['extern']);
    $sql = "SELECT DISTINCT thema FROM form_data WHERE glosZichtbaar = '0' AND extern = ? ORDER BY thema ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $extern);
} else {
    $sql = "SELECT DISTINCT thema FROM form_data WHERE glosZichtbaar = '0' AND extern IS NULL ORDER BY thema ASC";
    $stmt = $conn->prepare($sql);
}

$stmt->execute();
$result = $stmt->get_result();

$themas = array();
while ($row = $result->fetch_assoc()) {
    if ($row['thema'] != "") {
        $themas[] = strtoupper($row['thema']);
    }
}
echo json_encode($themas);

$stmt->close();
$conn->close();
?>
