<?php
require_once '../config/db.php';
require_once '../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

$product_id = (int)($_POST['product_id'] ?? 0);
$removed_by = (int)($_POST['removed_by'] ?? 0);

if ($product_id <= 0 || $removed_by <= 0) {
    jsonResponse(false, 'product_id and removed_by are required');
}

$stmt = $conn->prepare('UPDATE products SET status = ?, is_removed = 1, removed_by = ?, removed_on = NOW() WHERE id = ?');
$status = 'removed';
$stmt->bind_param('sii', $status, $removed_by, $product_id);

if ($stmt->execute()) {
    jsonResponse(true, 'Product marked as removed');
}

jsonResponse(false, 'Failed to update product');

