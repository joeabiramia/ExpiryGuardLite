<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

header('Content-Type: application/json');

function apiResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiResponse(false, 'Invalid request method');
}

$sql = "
    SELECT
        id,
        category_name,
        alert_days_before
    FROM category_rules
    ORDER BY category_name ASC
";

$result = $conn->query($sql);

if (!$result) {
    apiResponse(false, 'Failed to load categories: ' . $conn->error);
}

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

apiResponse(true, 'Categories loaded successfully', $data);