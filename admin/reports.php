<?php
include 'layout_top.php';
?>

<div class="dashboard-header mb-4">
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3">
        <div>
            <p class="text-muted mb-1">Reports center</p>
            <h2 class="fw-bold mb-2">Corporate reporting</h2>
            <p class="text-muted mb-0">Group reports, exports, and scheduled insights for management.</p>
        </div>
        <div>
            <?php if ($selectedBranch !== 'all'): ?>
                <div class="text-muted small mb-2">Viewing: <?= htmlspecialchars($selectedBranch) ?></div>
            <?php endif; ?>
            <a href="analytics.php<?= $selectedBranch !== 'all' ? '?branch=' . urlencode($selectedBranch) : '' ?>" class="btn btn-outline-secondary"><i class="bi bi-bar-chart-line-fill me-2"></i> Open analytics</a>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm p-4 mb-4">
    <div class="card-body">
        <h5 class="mb-3">Reporting in progress</h5>
        <p class="text-muted">This section is designed for enterprise reports, CSV exports, and branch-level dashboards. Use the Analytics page to view current chart-based KPIs today.</p>
        <div class="mt-4">
            <a href="analytics.php<?= $selectedBranch !== 'all' ? '?branch=' . urlencode($selectedBranch) : '' ?>" class="btn btn-outline-secondary btn-lg">Go to Analytics</a>
        </div>
    </div>
</div>

<?php include 'layout_bottom.php'; ?>