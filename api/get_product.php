<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (function_exists('addSecurityHeaders')) {
    addSecurityHeaders();
}

requireLogin();
header('Content-Type: application/json; charset=utf-8');

$myRole      = $_SESSION['role'] ?? 'viewer';
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id'] ?? 0);

$productId = (int)($_GET['product_id'] ?? 0);

if ($productId <= 0) {
    jsonResponse(false, 'Invalid product ID.', null, 400);
}

$canEdit = in_array($myRole, ['super_admin', 'company_admin', 'branch_manager'], true)
    || userHasPermission($conn, (int)($_SESSION['user_id'] ?? 0), 'manage_products');

if (!$canEdit) {
    jsonResponse(false, 'You do not have permission to edit products.', null, 403);
}

$stmt = $conn->prepare("
    SELECT
        id,
        company_id,
        branch_id,
        product_name,
        barcode,
        category,
        quantity,
        unit_price,
        unit,
        expiry_date,
        status,
        notes
    FROM products
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    jsonResponse(false, 'Database error: ' . $conn->error, null, 500);
}

$stmt->bind_param('i', $productId);
$stmt->execute();

$res = $stmt->get_result();
$product = $res->fetch_assoc();

$res->free();
$stmt->close();

if (!$product) {
    jsonResponse(false, 'Product not found.', null, 404);
}

if ($myRole !== 'super_admin' && (int)$product['company_id'] !== $myCompanyId) {
    jsonResponse(false, 'You cannot access products from another company.', null, 403);
}

if ($myRole === 'branch_manager' && $myBranchId > 0 && (int)$product['branch_id'] !== $myBranchId) {
    jsonResponse(false, 'You cannot access products from another branch.', null, 403);
}

jsonResponse(true, 'Product loaded.', $product);