<?php
require_once '../config/db.php';

$search = trim($_GET['q'] ?? '');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=products_report.csv');

$output = fopen('php://output', 'w');

fputcsv($output, ['Barcode', 'Product Name', 'Expiry Date', 'Status']);

if ($search) {
    $stmt = $conn->prepare("
        SELECT barcode, product_name, expiry_date, status
        FROM products
        WHERE barcode LIKE CONCAT('%', ?, '%')
           OR product_name LIKE CONCAT('%', ?, '%')
           OR expiry_date LIKE CONCAT('%', ?, '%')
           OR status LIKE CONCAT('%', ?, '%')
        ORDER BY entered_on DESC
    ");
    $stmt->bind_param("ssss", $search, $search, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("
        SELECT barcode, product_name, expiry_date, status
        FROM products
        ORDER BY entered_on DESC
    ");
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