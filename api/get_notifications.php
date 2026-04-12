<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

$sql = "SELECT p.*, u.full_name AS entered_by_name
        FROM products p
        LEFT JOIN users u ON p.entered_by = u.id
        WHERE p.status IN ('near_expiry', 'expired') AND p.is_removed = 0
        ORDER BY p.expiry_date ASC";
$result = $conn->query($sql);

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

jsonResponse(true, 'Notifications fetched successfully', $notifications);

