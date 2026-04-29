<?php
// Handle AJAX save BEFORE layout_top.php to keep the response body clean JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['ajax']))) {
    require_once '../config/auth.php';
    require_once '../config/helpers.php';
    require_once '../config/db.php';
    requireLogin();

    $cat   = trim($_POST['category_name']           ?? '');
    $alert = (int)($_POST['alert_days_before']       ?? 4);
    $auto  = (int)($_POST['auto_remove_days_before'] ?? 0);
    $ok    = false;

    if ($cat !== '') {
        $stmt = $conn->prepare("
            INSERT INTO category_rules (category_name, alert_days_before, auto_remove_days_before)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE alert_days_before       = VALUES(alert_days_before),
                                    auto_remove_days_before = VALUES(auto_remove_days_before)
        ");
        $stmt->bind_param('sii', $cat, $alert, $auto);
        $ok = $stmt->execute();

        if ($ok) {
            // Invalidate category rules dropdown cache used by products/notifications/removed pages
            unset($_SESSION['category_rules_cache']);
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => $ok]);
    exit;
}

include 'layout_top.php';
$userRole       ??= 'viewer';
$selectedBranch ??= 'all';

$userId = (int)($_SESSION['user_id'] ?? 0);

// Access: manager-level roles always allowed; employees/viewers allowed if they have a category permission
$canManage = in_array($userRole, ['super_admin', 'company_admin', 'branch_manager'], true)
          || userHasPermission($conn, $userId, 'manage_categories');
$canView   = $canManage
          || userHasPermission($conn, $userId, 'view_categories');

if (!$canView) {
    header('Location: dashboard.php');
    exit();
}

if (isset($_GET['delete']) && $canManage) {
    $id      = (int)$_GET['delete'];
    $delStmt = $conn->prepare("DELETE FROM category_rules WHERE id = ?");
    $delStmt->bind_param('i', $id);
    $delStmt->execute();
    unset($_SESSION['category_rules_cache']);
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
  <?php if ($canManage): ?>
  <button class="btn-eg btn-primary-eg" data-bs-toggle="modal" data-bs-target="#addRuleModal">
    <i class="bi bi-plus-lg"></i> Add Rule
  </button>
  <?php endif; ?>
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
          <th>Auto-remove (days after expiry)</th>
          <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rules)): ?>
        <tr><td colspan="<?= $canManage ? 4 : 3 ?>"><div class="empty-state"><i class="bi bi-tags"></i><p>No rules defined. Add one to start.</p></div></td></tr>
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
          <?php if ($canManage): ?>
          <td>
            <div style="display:flex;gap:6px">
              <button class="btn-eg btn-ghost-eg btn-xs-eg"
                onclick="editRule('<?= htmlspecialchars($rule['category_name'],ENT_QUOTES) ?>',<?= (int)$rule['alert_days_before'] ?>,<?= (int)$rule['auto_remove_days_before'] ?>)">
                <i class="bi bi-pencil"></i>
              </button>
              <a class="btn-eg btn-ghost-eg btn-xs-eg" style="color:var(--red)"
                 href="category_rules.php?delete=<?= (int)$rule['id'] ?>"
                 onclick="return confirm('Delete this rule?')">
                <i class="bi bi-trash3"></i>
              </a>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<!-- Add/Edit Rule Modal -->
<div class="modal fade" id="addRuleModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="ruleForm">
        <div class="modal-header">
          <h5 class="modal-title" id="ruleModalTitle">Add Category Rule</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
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
              <small style="font-size:.73rem;color:var(--text-muted)">Auto-remove X days after expiry (0 = disabled)</small>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-eg btn-ghost-eg" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-eg btn-primary-eg" id="ruleSubmitBtn">
            <i class="bi bi-check-lg"></i> Save Rule
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
let _ruleModal = null;

function openAddModal() {
  document.getElementById('ruleModalTitle').textContent = 'Add Category Rule';
  document.getElementById('ruleForm').reset();
  _ruleModal = _ruleModal || new bootstrap.Modal(document.getElementById('addRuleModal'));
  _ruleModal.show();
}

function editRule(name, alert, autoRemove) {
  document.getElementById('ruleModalTitle').textContent = 'Edit Category Rule';
  document.getElementById('ruleCatName').value          = name;
  document.getElementById('ruleAlert').value            = alert;
  document.getElementById('ruleAutoRemove').value       = autoRemove;
  _ruleModal = _ruleModal || new bootstrap.Modal(document.getElementById('addRuleModal'));
  _ruleModal.show();
}

document.getElementById('ruleForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('ruleSubmitBtn');
  btn.disabled = true;

  try {
    const fd = new FormData(this);
    fd.append('ajax', '1');
    const res  = await fetch('category_rules.php', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const json = await res.json();
    showToast(json.success ? 'Rule saved' : 'Failed to save rule', json.success ? 'ok' : 'err');
    if (json.success) {
      _ruleModal?.hide();
      setTimeout(() => location.reload(), 500);
    }
  } catch (err) {
    showToast('Network error — please try again', 'err');
  } finally {
    btn.disabled = false;
  }
});

// Wire "Add Rule" button to openAddModal() instead of data-bs-toggle
document.querySelector('[data-bs-target="#addRuleModal"]')?.addEventListener('click', function(e) {
  e.preventDefault();
  openAddModal();
});
</script>
<?php endif; ?>

<?php include 'layout_bottom.php'; ?>
