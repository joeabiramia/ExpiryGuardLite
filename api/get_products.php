<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

$sql = "SELECT p.*, u.full_name AS entered_by_name, ru.full_name AS removed_by_name
        FROM products p
        LEFT JOIN users u ON p.entered_by = u.id
        LEFT JOIN users ru ON p.removed_by = ru.id
        ORDER BY p.id DESC";
$result = $conn->query($sql);

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

jsonResponse(true, 'Products fetched successfully', $products);

