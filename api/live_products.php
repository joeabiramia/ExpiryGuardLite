<?php
require_once '../config/db.php';

header('Content-Type: application/json');

function jsonResponse($success, $message, $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

$selectedBranch = trim($_GET['branch'] ?? 'all');

$branchColumn = null;
$branchCandidates = ['branch', 'branch_name', 'branch_code', 'branch_id'];

foreach ($branchCandidates as $candidate) {
    $result = $conn->query("SHOW COLUMNS FROM products LIKE '" . $conn->real_escape_string($candidate) . "'");
    if ($result && $result->num_rows > 0) {
        $branchColumn = $candidate;
        break;
    }
}

$sql = "
    SELECT
        p.*,
        u.full_name AS entered_by_name,
        ru.full_name AS removed_by_name
    FROM products p
    LEFT JOIN users u ON p.entered_by = u.id
    LEFT JOIN users ru ON p.removed_by = ru.id
    WHERE 1=1
";

$params = [];
$types = '';

if ($branchColumn && $selectedBranch !== 'all') {
    $sql .= " AND p.`$branchColumn` = ? ";
    $types .= 's';
    $params[] = $selectedBranch;
}

$sql .= " ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    jsonResponse(false, 'SQL prepare failed: ' . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$products = [];

while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

jsonResponse(true, 'Products loaded successfully', $products);