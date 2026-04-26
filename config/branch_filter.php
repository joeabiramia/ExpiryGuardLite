<?php

/*
|--------------------------------------------------------------------------
| Branch Filter
|--------------------------------------------------------------------------
| Sets $branchFilterSql, $branchFilterSqlAlias, $branchFilterValue, $branches.
| Layout_top.php already sets these — this file is only included by pages
| that need it standalone (e.g. if called without layout_top.php).
| All statements are closed immediately after use to prevent "commands out of sync".
|--------------------------------------------------------------------------
*/

// If layout_top.php already set these, don't re-run
if (isset($branchFilterValue)) {
    return;
}

$sessionRole      = $_SESSION['role']       ?? 'viewer';
$sessionBranchId  = (int)($_SESSION['branch_id']   ?? 0);
$sessionCompanyId = (int)($_SESSION['company_id']  ?? 0);

$canSwitch      = in_array($sessionRole, ['super_admin', 'company_admin'], true);
$selectedBranch = $canSwitch ? trim($_GET['branch'] ?? 'all') : ($sessionBranchId > 0 ? (string)$sessionBranchId : 'all');

// Load branch list
$bSql    = "SELECT id, branch_name FROM branches WHERE is_active = 1";
$bTypes  = '';
$bParams = [];
if ($sessionRole !== 'super_admin' && $sessionCompanyId > 0) {
    $bSql   .= ' AND company_id = ?'; $bTypes .= 'i'; $bParams[] = $sessionCompanyId;
}
if (!$canSwitch && $sessionBranchId > 0) {
    $bSql   .= ' AND id = ?';         $bTypes .= 'i'; $bParams[] = $sessionBranchId;
}
$bSql .= ' ORDER BY branch_name ASC';

$bStmt = $conn->prepare($bSql);
if ($bTypes !== '') $bStmt->bind_param($bTypes, ...$bParams);
$bStmt->execute();
$bRes     = $bStmt->get_result();
$branches = $bRes->fetch_all(MYSQLI_ASSOC);
$bRes->free();
$bStmt->close();

// Set filter variables
$branchColumn         = 'branch_id';
$branchFilterSql      = '';
$branchFilterSqlAlias = '';
$branchFilterValue    = null;

if ($selectedBranch !== 'all') {
    $branchFilterSql      = ' AND `branch_id` = ?';
    $branchFilterSqlAlias = ' AND p.`branch_id` = ?';
    $branchFilterValue    = $selectedBranch;
}
