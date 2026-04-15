<?php
require_once '../config/db.php';
require_once '../config/branch_filter.php';

$search = trim($_GET['q'] ?? '');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=products_report.csv');

$output = fopen('php://output', 'w');

fputcsv($output, ['Barcode', 'Product Name', 'Expiry Date', 'Status']);

if ($search) {
    $sql = "SELECT barcode, product_name, expiry_date, status
        FROM products
        WHERE (barcode LIKE CONCAT('%', ?, '%')
           OR product_name LIKE CONCAT('%', ?, '%')
           OR expiry_date LIKE CONCAT('%', ?, '%')
           OR status LIKE CONCAT('%', ?, '%'))" . $branchFilterSql . "
        ORDER BY entered_on DESC";

    if ($branchFilterValue !== null) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssss', $search, $search, $search, $search, $branchFilterValue);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssss', $search, $search, $search, $search);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    if ($branchFilterValue !== null) {
        $stmt = $conn->prepare("SELECT barcode, product_name, expiry_date, status FROM products WHERE 1=1" . $branchFilterSql . " ORDER BY entered_on DESC");
        $stmt->bind_param('s', $branchFilterValue);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query("SELECT barcode, product_name, expiry_date, status FROM products ORDER BY entered_on DESC");
    }
}

while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['barcode'],
        $row['product_name'],
        $row['expiry_date'],
        $row['status']
    ]);
}

fclose($output);
exit;