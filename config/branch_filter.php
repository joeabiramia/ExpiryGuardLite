<?php
$selectedBranch = trim($_GET['branch'] ?? 'all');

$branchColumn = null;
$branchCandidates = ['branch', 'branch_name', 'branch_code', 'branch_id'];

foreach ($branchCandidates as $candidate) {
    $result = $conn->query(
        "SHOW COLUMNS FROM products LIKE '" . $conn->real_escape_string($candidate) . "'"
    );

    if ($result && $result->num_rows > 0) {
        $branchColumn = $candidate;
        break;
    }
}

$branchFilterSql = '';
$branchFilterSqlAlias = '';
$branchFilterValue = null;
$branches = [];

if ($branchColumn === 'branch_id') {
    // Use branches master table for proper display names
    $branchQuery = $conn->query("
        SELECT id, branch_name
        FROM branches
        ORDER BY branch_name ASC
    ");

    while ($row = $branchQuery->fetch_assoc()) {
        $branches[] = [
            'id' => $row['id'],
            'branch_name' => $row['branch_name']
        ];
    }

    if ($selectedBranch !== 'all') {
        $branchFilterSql = " AND `$branchColumn` = ?";
        $branchFilterSqlAlias = " AND p.`$branchColumn` = ?";
        $branchFilterValue = $selectedBranch;
    }

} else {
    // Fallback for text-based branch columns
    if ($branchColumn) {
        $branchQuery = $conn->query("
            SELECT DISTINCT `$branchColumn` AS branch_name
            FROM products
            WHERE `$branchColumn` IS NOT NULL
              AND `$branchColumn` <> ''
            ORDER BY `$branchColumn` ASC
        ");

        while ($row = $branchQuery->fetch_assoc()) {
            $branches[] = [
                'id' => $row['branch_name'],
                'branch_name' => $row['branch_name']
            ];
        }
    }

    if ($branchColumn && $selectedBranch !== 'all') {
        $branchFilterSql = " AND `$branchColumn` = ?";
        $branchFilterSqlAlias = " AND p.`$branchColumn` = ?";
        $branchFilterValue = $selectedBranch;
    }
}