<?php
include(__DIR__ . '/../mysql_config.php');
require_once __DIR__ . '/php_api/datasets.php';   // datasets_registry()
require_once __DIR__ . '/php_api/session.php';    // context_codes()

header('Content-Type: application/json');

// Allow activity tracking POSTs from signcollect subdomains (e.g. mocap.dev2.taila8bdbd.ts.net)
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin && preg_match('#^https://[a-z0-9-]+\.signcollect\.nl$#i', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}
$conn->set_charset("utf8");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    switch ($action) {
        case 'list':
            requireAdmin();
            listUsers();
            break;
        case 'add':
            requireAdmin();
            addUser();
            break;
        case 'update':
            requireAdmin();
            updateUser();
            break;
        case 'block':
            requireAdmin();
            toggleBlock();
            break;
        case 'delete':
            requireAdmin();
            deleteUser();
            break;
        case 'activity':
            updateActivity();
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}

/**
 * Authorise an admin-only action.
 *
 * Identity comes from the session, which current_session() validates against
 * the database. It used to come from $_POST['requestingUserId'] - a value the
 * caller supplies - so anyone who knew an admin's numeric id could send it and
 * be treated as that admin.
 */
function requireAdmin() {
    $s = current_session();
    if ($s === null) {
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    if (($s['role'] ?? 'user') !== 'admin') {
        echo json_encode(['error' => 'Unauthorized: admin role required']);
        exit;
    }
    return $s;
}

/** JSON column → validated array of codes; NULL/garbage → $fallback. */
function decodeCodeList($json, array $valid, array $fallback) {
    if ($json === null || $json === '') return $fallback;
    $d = json_decode($json, true);
    if (!is_array($d)) return $fallback;
    $d = array_values(array_intersect($valid, array_filter($d, 'is_string')));
    return $d ? $d : $fallback;
}

/**
 * Read the four access fields from $_POST (each optional).
 *   allowed_datasets[] / allowed_contexts[]  — arrays of codes
 *   default_dataset / default_context        — single code
 * Returns ['ok'=>true, 'set'=>[col=>value]] or ['ok'=>false, 'error'=>msg].
 * Only fields that were actually posted end up in 'set'; when both a list
 * and its default are posted the default must be inside the list.
 */
function readAccessFields() {
    $set = [];
    $specs = [
        ['allowed_datasets', 'default_dataset', array_keys(datasets_registry()), 'dataset'],
        ['allowed_contexts', 'default_context', context_codes(),                'context'],
    ];
    foreach ($specs as [$listKey, $defKey, $valid, $label]) {
        $list = null;
        if (isset($_POST[$listKey])) {
            $raw = $_POST[$listKey];
            if (!is_array($raw)) $raw = [$raw];
            $list = array_values(array_intersect($valid, array_filter($raw, 'is_string')));
            if (!$list) return ['ok' => false, 'error' => "At least one $label must be allowed"];
            $set[$listKey] = json_encode($list);
        }
        if (isset($_POST[$defKey]) && $_POST[$defKey] !== '') {
            $def = (string)$_POST[$defKey];
            if (!in_array($def, $valid, true)) return ['ok' => false, 'error' => "Unknown $label: $def"];
            if ($list !== null && !in_array($def, $list, true)) {
                return ['ok' => false, 'error' => "Default $label must be one of the allowed {$label}s"];
            }
            $set[$defKey] = $def;
        }
    }
    return ['ok' => true, 'set' => $set];
}

function listUsers() {
    global $conn;
    $result = $conn->query("SELECT userId, user, lang, role, last_login, last_activity, last_page, blocked,
                                   default_dataset, allowed_datasets, default_context, allowed_contexts
                            FROM users ORDER BY userId");
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $row['allowed_datasets'] = decodeCodeList($row['allowed_datasets'], array_keys(datasets_registry()), ['ngt']);
        $row['allowed_contexts'] = decodeCodeList($row['allowed_contexts'], context_codes(), context_codes());
        $users[] = $row;
    }
    echo json_encode($users);
}

function addUser() {
    global $conn;
    $user = isset($_POST['user']) ? $_POST['user'] : '';
    $pass = isset($_POST['pass']) ? $_POST['pass'] : '';
    $lang = isset($_POST['lang']) ? $_POST['lang'] : 'nld';
    $role = isset($_POST['role']) ? $_POST['role'] : 'user';

    if (empty($user) || empty($pass)) {
        echo json_encode(['error' => 'Username and password are required']);
        return;
    }

    // Check if username already exists
    $checkStmt = $conn->prepare("SELECT userId FROM users WHERE user = ?");
    $checkStmt->bind_param("s", $user);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) {
        $checkStmt->close();
        echo json_encode(['error' => 'Username already exists']);
        return;
    }
    $checkStmt->close();

    $access = readAccessFields();
    if (!$access['ok']) {
        echo json_encode(['error' => $access['error']]);
        return;
    }
    // New users default to NGT + both contexts unless the form says otherwise.
    $set = array_merge([
        'allowed_datasets' => json_encode(['ngt']),
        'default_dataset'  => 'ngt',
        'allowed_contexts' => json_encode(context_codes()),
        'default_context'  => 'signio',
    ], $access['set']);

    $stmt = $conn->prepare("INSERT INTO users (user, pass, lang, role, last_login, blocked, logboek, tableCheck,
                                               allowed_datasets, default_dataset, allowed_contexts, default_context)
                            VALUES (?, ?, ?, ?, NULL, 0, '', '', ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $user, $pass, $lang, $role,
                      $set['allowed_datasets'], $set['default_dataset'],
                      $set['allowed_contexts'], $set['default_context']);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'userId' => $stmt->insert_id]);
    } else {
        echo json_encode(['error' => 'Failed to add user: ' . $conn->error]);
    }
    $stmt->close();
}

function updateUser() {
    global $conn;
    $userId = isset($_POST['userId']) ? intval($_POST['userId']) : 0;
    $user = isset($_POST['user']) ? $_POST['user'] : '';
    $lang = isset($_POST['lang']) ? $_POST['lang'] : '';
    $role = isset($_POST['role']) ? $_POST['role'] : '';
    $pass = isset($_POST['pass']) ? $_POST['pass'] : '';

    if ($userId <= 0) {
        echo json_encode(['error' => 'Valid userId is required']);
        return;
    }

    $fields = [];
    $types = '';
    $values = [];

    if (!empty($user)) {
        $fields[] = "user = ?";
        $types .= 's';
        $values[] = $user;
    }
    if (!empty($lang)) {
        $fields[] = "lang = ?";
        $types .= 's';
        $values[] = $lang;
    }
    if (!empty($role)) {
        $fields[] = "role = ?";
        $types .= 's';
        $values[] = $role;
    }
    if (!empty($pass)) {
        $fields[] = "pass = ?";
        $types .= 's';
        $values[] = $pass;
    }

    $access = readAccessFields();
    if (!$access['ok']) {
        echo json_encode(['error' => $access['error']]);
        return;
    }
    foreach ($access['set'] as $col => $val) {
        $fields[] = "$col = ?";
        $types .= 's';
        $values[] = $val;
    }

    if (empty($fields)) {
        echo json_encode(['error' => 'No fields to update']);
        return;
    }

    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE userId = ?";
    $types .= 'i';
    $values[] = $userId;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Failed to update user: ' . $conn->error]);
    }
    $stmt->close();
}

function toggleBlock() {
    global $conn;
    $userId = isset($_POST['userId']) ? intval($_POST['userId']) : 0;

    if ($userId <= 0) {
        echo json_encode(['error' => 'Valid userId is required']);
        return;
    }

    // Toggle the blocked status
    $stmt = $conn->prepare("UPDATE users SET blocked = IF(blocked = 1, 0, 1) WHERE userId = ?");
    $stmt->bind_param("i", $userId);

    if ($stmt->execute()) {
        // Fetch new status
        $getStmt = $conn->prepare("SELECT blocked FROM users WHERE userId = ?");
        $getStmt->bind_param("i", $userId);
        $getStmt->execute();
        $getStmt->bind_result($newBlocked);
        $getStmt->fetch();
        $getStmt->close();
        echo json_encode(['success' => true, 'blocked' => $newBlocked]);
    } else {
        echo json_encode(['error' => 'Failed to toggle block: ' . $conn->error]);
    }
    $stmt->close();
}

function updateActivity() {
    global $conn;
    // The userId comes from the session, not from the request. It used to be
    // read straight off $_POST, which let anyone write activity and last_page
    // onto any account they cared to name.
    $s = current_session();
    if ($s === null) {
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    $userId = (int)$s['userId'];
    $page = isset($_POST['page']) ? $_POST['page'] : '';

    if ($userId <= 0) {
        echo json_encode(['error' => 'Valid userId is required']);
        return;
    }

    // page column is VARCHAR(255) — hard-cap to avoid insert errors
    if (strlen($page) > 255) {
        $page = substr($page, 0, 255);
    }

    $stmt = $conn->prepare("UPDATE users SET last_activity = NOW(), last_page = ? WHERE userId = ?");
    $stmt->bind_param("si", $page, $userId);
    $stmt->execute();
    $stmt->close();

    $logStmt = $conn->prepare("INSERT INTO activity_log (userId, page, visited_at) VALUES (?, ?, NOW())");
    $logStmt->bind_param("is", $userId, $page);
    $logStmt->execute();
    $logStmt->close();

    echo json_encode(['success' => true]);
}

function deleteUser() {
    global $conn;
    $userId = isset($_POST['userId']) ? intval($_POST['userId']) : 0;

    if ($userId <= 0) {
        echo json_encode(['error' => 'Valid userId is required']);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE userId = ?");
    $stmt->bind_param("i", $userId);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Failed to delete user: ' . $conn->error]);
    }
    $stmt->close();
}

$conn->close();
?>
