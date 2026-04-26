<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

// Any authenticated user can view categories
resolveApiUser($conn);

$result = $conn->query("
    SELECT id, category_name, alert_days_before, auto_remove_days_before
    FROM category_rules
    ORDER BY category_name ASC
");

if (!$result) {
    jsonResponse(false, 'Failed to load categories', null, 500);
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

jsonResponse(true, 'Categories loaded successfully', $data);