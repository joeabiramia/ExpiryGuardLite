<?php
include 'layout_top.php';

// Only branch_manager and above can manage category rules
if (!in_array($userRole, ['super_admin', 'company_admin', 'branch_manager'], true)) {
    header('Location: dashboard.php');
    exit();
}

// Handle save (AJAX or form post)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cat   = trim($_POST['category_name']         ?? '');
    $alert = (int)($_POST['alert_days_before']     ?? 4);
    $auto  = (int)($_POST['auto_remove_days_before'] ?? 0);

    if ($cat !== '') {
        $stmt = $conn->prepare("
            INSERT INTO category_rules (category_name, alert_days_before, auto_remove_days_before)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE alert_days_before = VALUES(alert_days_before),
                                    auto_remove_days_before = VALUES(auto_remove_days_before)
        ");
        $stmt->bind_param('sii', $cat, $alert, $auto);
        $ok = $stmt->execute();

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $ok]);
            exit;
        }
    }
}

if (isset($_GET['delete'])) {
    $id     = (int)$_GET['delete'];
    $delStmt = $conn->prepare("DELETE FROM category_rules WHERE id = ?");
    $delStmt->bind_param('i', $id);
    $delStmt->execute();
    header("Location: category_rules.php");
    exit;
}

$rules = $conn->query("SELECT * FROM category_rules ORDER BY category_name ASC")->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header">
  <div class="page-header-text">
    <h1>Category Rules</h1>
    <p>Configure alert thresholds per product category</p>
  </div>
  <button class="btn-eg btn-primary-eg" data-bs-toggle="modal" data-bs-target="#addRuleModal">
    <i class="bi bi-plus-lg"></i> Add Rule
  </button>
</div>

<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-tags me-2"></i>Category Rules (<?= count($rules) ?>)</span>
  </div>
  <div class="eg-table-wrap">
    <table class="eg-table">
      <thead>
        <tr>
          <th>Category</th>
          <th>Alert (days before expiry)</th>
          <th>Auto-remove (days before expiry)</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rules)): ?>
        <tr><td colspan="4"><div class="empty-state"><i class="bi bi-tags"></i><p>No rules defined. Add one to start.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($rules as $rule): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($rule['category_name']) ?></td>
          <td>
            <span style="display:inline-flex;align-items:center;gap:6px">
              <span style="width:32px;height:32px;border-radius:8px;background:var(--yellow-light);color:#92400e;font-weight:700;font-size:.82rem;display:flex;align-items:center;justify-content:center"><?= (int)$rule['alert_days_before'] ?></span>
              days
            </span>
          </td>
          <td>
            <?php if ($rule['auto_remove_days_before'] > 0): ?>
            <span style="display:inline-flex;align-items:center;gap:6px">
              <span style="width:32px;height:32px;border-radius:8px;background:var(--red-light);color:#991b1b;font-weight:700;font-size:.82rem;display:flex;align-items:center;justify-content:center"><?= (int)$rule['auto_remove_days_before'] ?></span>
              days
            </span>
            <?php else: ?>
            <span style="font-size:.78rem;color:var(--text-muted)">Disabled</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:6px">
              <button class="btn-eg btn-ghost-eg btn-xs-eg"
                onclick="editRule(<?= (int)$rule['id'] ?>,'<?= htmlspecialchars($rule['category_name'],ENT_QUOTES) ?>',<?= (int)$rule['alert_days_before'] ?>,<?= (int)$rule['auto_remove_days_before'] ?>)">
                <i class="bi bi-pencil"></i>
              </button>
              <a class="btn-eg btn-ghost-eg btn-xs-eg" style="color:var(--red)"
                 href="category_rules.php?delete=<?= (int)$rule['id'] ?>"
                 onclick="return confirm('Delete this rule?')">
                <i class="bi bi-trash3"></i>
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add/Edit Rule Modal -->
<div class="modal fade" id="addRuleModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="ruleForm" method="POST">
        <div class="modal-header">
          <h5 class="modal-title" id="ruleModalTitle">Add Category Rule</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="ajax" value="1">
          <div class="form-group">
            <label class="eg-label">Category Name *</label>
            <input type="text" name="category_name" id="ruleCatName" class="eg-input" placeholder="e.g. Dairy, Snacks…" required>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="eg-label">Alert Days Before Expiry</label>
              <input type="number" name="alert_days_before" id="ruleAlert" class="eg-input" value="4" min="0" max="365">
              <small style="font-size:.73rem;color:var(--text-muted)">Show alert X days before expiry date</small>
            </div>
            <div class="form-group">
              <label class="eg-label">Auto-remove Days (0 = off)</label>
              <input type="number" name="auto_remove_days_before" id="ruleAutoRemove" class="eg-input" value="0" min="0" max="365">
              <small style="font-size:.73rem;color:var(--text-muted)">Auto-remove X days before expiry (0 = disabled)</small>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-eg btn-ghost-eg" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-eg btn-primary-eg"><i class="bi bi-check-lg"></i> Save Rule</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editRule(id, name, alert, autoRemove) {
  document.getElementById('ruleCatName').value   = name;
  document.getElementById('ruleAlert').value      = alert;
  document.getElementById('ruleAutoRemove').value = autoRemove;
  document.getElementById('ruleModalTitle').textContent = 'Edit Category Rule';
  new bootstrap.Modal(document.getElementById('addRuleModal')).show();
}

document.getElementById('ruleForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const res  = await fetch('category_rules.php', { method: 'POST', body: new FormData(this) });
  const json = await res.json();
  showToast(json.success ? 'Rule saved' : 'Failed to save rule', json.success ? 'ok' : 'err');
  if (json.success) { bootstrap.Modal.getInstance(document.getElementById('addRuleModal'))?.hide(); setTimeout(() => location.reload(), 700); }
});
</script>

<?php include 'layout_bottom.php'; ?>