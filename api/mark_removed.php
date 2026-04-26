<?php
require_once '../config/helpers.php';
require_once '../config/db.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$apiUser    = resolveApiUser($conn);

if ($apiUser['role'] === 'viewer') {
    jsonResponse(false, 'Access denied — viewers cannot remove products', null, 403);
}

$product_id = (int)($_POST['product_id'] ?? 0);

if ($product_id <= 0) {
    jsonResponse(false, 'product_id is required', null, 400);
}

// Verify the product exists and belongs to user's branch (or super_admin sees all)
$pStmt = $conn->prepare("
    SELECT id, company_id, branch_id, product_name, barcode
    FROM products
    WHERE id = ? AND is_removed = 0
    LIMIT 1
");
$pStmt->bind_param('i', $product_id);
$pStmt->execute();
$pRes    = $pStmt->get_result();
$product = $pRes->fetch_assoc();
$pRes->free();
$pStmt->close();

if (!$product) {
    jsonResponse(false, 'Product not found', null, 404);
}

// Enforce branch scope for non-super_admin
if ($apiUser['role'] !== 'super_admin') {
    if ((int)$product['company_id'] !== (int)$apiUser['company_id']) {
        jsonResponse(false, 'Access denied', null, 403);
    }
    if (!in_array($apiUser['role'], ['company_admin'], true)) {
        if ((int)$product['branch_id'] !== (int)$apiUser['branch_id']) {
            jsonResponse(false, 'Access denied', null, 403);
        }
    }
}

$removed_by = (int)$apiUser['id'];
$status     = 'removed';

$stmt = $conn->prepare("
    UPDATE products
    SET status = ?, is_removed = 1, removed_by = ?, removed_on = NOW()
    WHERE id = ?
");
$stmt->bind_param('sii', $status, $removed_by, $product_id);

if (!$stmt->execute()) {
    jsonResponse(false, 'Failed to mark product as removed', null, 500);
}

logActivity(
    $conn,
    (int)$product['company_id'],
    (int)$product['branch_id'],
    $removed_by,
    'remove_product',
    'products',
    $product_id,
    "Removed product: {$product['product_name']} (barcode: {$product['barcode']})"
);

jsonResponse(true, 'Product marked as removed');