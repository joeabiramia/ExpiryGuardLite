<?php
include 'layout_top.php';

$myRole      = $_SESSION['role']      ?? 'viewer';
$myUserId    = (int)($_SESSION['user_id'] ?? 0);
$myCompanyId = (int)($_SESSION['company_id'] ?? 0);
$myBranchId  = (int)($_SESSION['branch_id']  ?? 0);

// Load companies (for form selects)
$companiesRes = $conn->query("SELECT id, company_name FROM companies WHERE is_active = 1 ORDER BY company_name ASC");
$companiesList = $companiesRes->fetch_all(MYSQLI_ASSOC);

// Load branches (scoped)
$branchQ = "SELECT id, branch_name FROM branches WHERE is_active = 1";
$branchA = [];
if ($myRole !== 'super_admin' && $myCompanyId) $branchQ .= " AND company_id = $myCompanyId";
if ($myRole === 'branch_manager' && $myBranchId) $branchQ .= " AND id = $myBranchId";
$branchQ .= " ORDER BY branch_name ASC";
$branchesRes = $conn->query($branchQ);
$branchesList = $branchesRes->fetch_all(MYSQLI_ASSOC);

// Load users (scoped)
$usersQ = "SELECT u.*, c.company_name, b.branch_name FROM users u
    LEFT JOIN companies c ON c.id = u.company_id
    LEFT JOIN branches  b ON b.id = u.branch_id
    WHERE 1=1";
if ($myRole !== 'super_admin' && $myCompanyId) $usersQ .= " AND u.company_id = $myCompanyId";
if ($myRole === 'branch_manager') $usersQ .= " AND u.branch_id = $myBranchId AND u.created_by = $myUserId";
$usersQ .= " ORDER BY u.created_at DESC";
$usersRes = $conn->query($usersQ);
$users = $usersRes->fetch_all(MYSQLI_ASSOC);

$roleLabels = [
    'super_admin'    => ['label' => 'Owner',          'badge' => 'badge-super'],
    'company_admin'  => ['label' => 'Company Admin',  'badge' => 'badge-company'],
    'branch_manager' => ['label' => 'Branch Manager', 'badge' => 'badge-manager'],
    'employee'       => ['label' => 'Employee',       'badge' => 'badge-employee'],
    'viewer'         => ['label' => 'Viewer',         'badge' => 'badge-viewer'],
];

// Roles the current user can create
$creatableRoles = [
    'super_admin'    => ['super_admin','company_admin','branch_manager','employee','viewer'],
    'company_admin'  => ['company_admin','branch_manager','employee','viewer'],
    'branch_manager' => ['employee','viewer'],
    'employee'       => [],
    'viewer'         => [],
];
$allowedNewRoles = $creatableRoles[$myRole] ?? [];
?>

<!-- Page header -->
<div class="page-header">
  <div class="page-header-text">
    <h1>Users</h1>
    <p>Manage team members and their permissions</p>
  </div>
  <?php if (!empty($allowedNewRoles)): ?>
  <button class="btn-eg btn-primary-eg" onclick="openAddUser()">
    <i class="bi bi-plus-lg"></i> Add User
  </button>
  <?php endif; ?>
</div>

<!-- Users table -->
<div class="eg-card">
  <div class="eg-card-header">
    <span class="eg-card-title"><i class="bi bi-people me-2"></i>Team Members (<?= count($users) ?>)</span>
    <div class="search-wrap">
      <i class="bi bi-search"></i>
      <input type="text" id="userSearch" placeholder="Search users…" oninput="filterUsers()">
    </div>
  </div>

  <div class="eg-table-wrap">
    <table class="eg-table" id="usersTable">
      <thead>
        <tr>
          <th>User</th>
          <th>Role</th>
          <th>Company / Branch</th>
          <th>Status</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($users)): ?>
        <tr><td colspan="6">
          <div class="empty-state"><i class="bi bi-people"></i><p>No users found.</p></div>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($users as $u):
        $ri   = $roleLabels[$u['role']] ?? ['label' => ucfirst($u['role']), 'badge' => 'badge-employee'];
        $canEdit   = $u['id'] !== $myUserId || $myRole === 'super_admin';
        $canDelete = (int)$u['id'] !== $myUserId;
      ?>
      <tr data-name="<?= strtolower(htmlspecialchars($u['full_name'])) ?>" data-username="<?= strtolower(htmlspecialchars($u['username'])) ?>">
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:34px;height:34px;border-radius:9px;background:var(--blue-light);color:var(--blue);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0">
              <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
            </div>
            <div>
              <div style="font-weight:600;font-size:.875rem"><?= htmlspecialchars($u['full_name']) ?></div>
              <div style="font-size:.75rem;color:var(--text-muted)">@<?= htmlspecialchars($u['username']) ?></div>
            </div>
          </div>
        </td>
        <td><span class="badge-eg <?= $ri['badge'] ?>"><?= $ri['label'] ?></span></td>
        <td>
          <div style="font-size:.82rem"><?= htmlspecialchars($u['company_name'] ?? '—') ?></div>
          <div style="font-size:.75rem;color:var(--text-muted)"><?= htmlspecialchars($u['branch_name'] ?? 'No branch') ?></div>
        </td>
        <td>
          <span class="badge-eg <?= $u['is_active'] ? 'badge-on' : 'badge-off' ?>">
            <i class="bi <?= $u['is_active'] ? 'bi-check-circle' : 'bi-x-circle' ?>"></i>
            <?= $u['is_active'] ? 'Active' : 'Disabled' ?>
          </span>
        </td>
        <td style="font-size:.78rem;color:var(--text-muted)"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
        <td>
          <div style="display:flex;gap:6px;align-items:center">
            <?php if ($canEdit): ?>
            <button class="btn-eg btn-ghost-eg btn-xs-eg" onclick="openEditUser(<?= htmlspecialchars(json_encode($u)) ?>)" title="Edit">
              <i class="bi bi-pencil"></i>
            </button>
            <button class="btn-eg btn-ghost-eg btn-xs-eg" onclick="openPermissions(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['full_name']) ?>')" title="Permissions">
              <i class="bi bi-shield-check"></i>
            </button>
            <?php if ((int)$u['id'] !== $myUserId): ?>
            <button class="btn-eg btn-ghost-eg btn-xs-eg" onclick="toggleStatus(<?= (int)$u['id'] ?>, <?= $u['is_active'] ? 0 : 1 ?>)" title="<?= $u['is_active'] ? 'Disable' : 'Enable' ?>">
              <i class="bi <?= $u['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
            </button>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <button class="btn-eg btn-ghost-eg btn-xs-eg" onclick="deleteUser(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['full_name']) ?>')" title="Delete" style="color:var(--red)">
              <i class="bi bi-trash3"></i>
            </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>


<!-- ── Add/Edit User Modal (multi-step) ───────────────────── -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="userModalTitle">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <!-- Stepper -->
      <div class="modal-body" style="padding-bottom:0 !important">
        <div class="stepper" id="stepper">
          <div class="step-item active" id="step1Tab">
            <div class="step-num">1</div> Basic Info
          </div>
          <div class="step-item" id="step2Tab">
            <div class="step-num">2</div> Permissions
          </div>
        </div>
      </div>

      <!-- Step 1: Basic Info -->
      <div class="modal-body" id="step1">
        <form id="userForm">
          <input type="hidden" id="userId" name="id" value="">

          <div class="form-row">
            <div class="form-group">
              <label class="eg-label">Full Name *</label>
              <input type="text" id="fullName" name="full_name" class="eg-input" placeholder="Full name" required>
            </div>
            <div class="form-group">
              <label class="eg-label">Username *</label>
              <input type="text" id="username" name="username" class="eg-input" placeholder="Username" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="eg-label">Password <span id="pwHint" style="font-weight:400;text-transform:none">(leave blank to keep)</span></label>
              <input type="password" id="password" name="password" class="eg-input" placeholder="Password">
            </div>
            <div class="form-group">
              <label class="eg-label">Role *</label>
              <select id="role" name="role" class="eg-select" required>
                <option value="">Select role…</option>
                <?php foreach ($allowedNewRoles as $r): ?>
                <option value="<?= $r ?>"><?= $roleLabels[$r]['label'] ?? ucfirst($r) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="eg-label">Company *</label>
              <select id="companyId" name="company_id" class="eg-select" required <?= $myRole !== 'super_admin' ? 'disabled' : '' ?>>
                <option value="">Select company…</option>
                <?php foreach ($companiesList as $co): ?>
                <option value="<?= (int)$co['id'] ?>"><?= htmlspecialchars($co['company_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($myRole !== 'super_admin'): ?>
              <input type="hidden" name="company_id" value="<?= $myCompanyId ?>">
              <?php endif; ?>
            </div>
            <div class="form-group">
              <label class="eg-label">Branch</label>
              <select id="branchId" name="branch_id" class="eg-select" <?= $myRole === 'branch_manager' ? 'disabled' : '' ?>>
                <option value="">Select branch…</option>
                <?php foreach ($branchesList as $br): ?>
                <option value="<?= (int)$br['id'] ?>"><?= htmlspecialchars($br['branch_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($myRole === 'branch_manager'): ?>
              <input type="hidden" name="branch_id" value="<?= $myBranchId ?>">
              <?php endif; ?>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="eg-label">Email</label>
              <input type="email" id="email" name="email" class="eg-input" placeholder="Email (optional)">
            </div>
            <div class="form-group">
              <label class="eg-label">Phone</label>
              <input type="text" id="phone" name="phone" class="eg-input" placeholder="Phone (optional)">
            </div>
          </div>

          <div class="form-group">
            <label class="eg-label">Status</label>
            <select id="isActive" name="is_active" class="eg-select">
              <option value="1">Active</option>
              <option value="0">Disabled</option>
            </select>
          </div>
        </form>
      </div>

      <!-- Step 2: Permissions -->
      <div class="modal-body" id="step2" style="display:none">
        <div id="permissionsLoading" style="text-align:center;padding:32px;color:var(--text-muted)">
          <div class="spinner-border spinner-border-sm me-2"></div> Loading permissions…
        </div>
        <div id="permissionsMatrix" style="display:none"></div>
        <div style="background:var(--blue-light);color:#1d4ed8;border-radius:9px;padding:10px 14px;font-size:.8rem;margin-top:8px">
          <i class="bi bi-info-circle me-1"></i>
          Checked permissions will be explicitly granted. Unchecked permissions will use role defaults.
          You can only grant permissions you yourself hold.
        </div>
      </div>

      <div class="modal-footer" id="modalFooter">
        <button class="btn-eg btn-ghost-eg" data-bs-dismiss="modal">Cancel</button>
        <button class="btn-eg btn-ghost-eg" id="backBtn" onclick="goToStep(1)" style="display:none">
          <i class="bi bi-arrow-left"></i> Back
        </button>
        <button class="btn-eg btn-primary-eg" id="nextBtn" onclick="goToStep(2)">
          Next <i class="bi bi-arrow-right"></i>
        </button>
        <button class="btn-eg btn-primary-eg" id="saveBtn" onclick="saveUser()" style="display:none">
          <i class="bi bi-check-lg"></i> Save User
        </button>
      </div>
    </div>
  </div>
</div>


<!-- Permissions-only modal (for existing users) -->
<div class="modal fade" id="permModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="permModalTitle">Permissions</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="permModalLoading" style="text-align:center;padding:32px;color:var(--text-muted)">
          <div class="spinner-border spinner-border-sm me-2"></div> Loading…
        </div>
        <div id="permModalMatrix"></div>
      </div>
      <div class="modal-footer">
        <button class="btn-eg btn-ghost-eg" data-bs-dismiss="modal">Cancel</button>
        <button class="btn-eg btn-primary-eg" onclick="savePermissions()">
          <i class="bi bi-shield-check"></i> Save Permissions
        </button>
      </div>
    </div>
  </div>
</div>


<script>
let isEdit       = false;
let editUserId   = 0;
let permTargetId = 0;
let availablePerms = {}; // { module: [ {id, permission_key, permission_label} ] }

/* ── Open Add ── */
function openAddUser() {
  isEdit = false;
  editUserId = 0;
  document.getElementById('userModalTitle').textContent = 'Add User';
  document.getElementById('userForm').reset();
  document.getElementById('userId').value = '';
  document.getElementById('pwHint').style.display = 'none';
  goToStep(1);
  new bootstrap.Modal(document.getElementById('userModal')).show();
}

/* ── Open Edit ── */
function openEditUser(u) {
  isEdit     = true;
  editUserId = u.id;
  document.getElementById('userModalTitle').textContent = 'Edit User';
  document.getElementById('userId').value     = u.id;
  document.getElementById('fullName').value   = u.full_name || '';
  document.getElementById('username').value   = u.username  || '';
  document.getElementById('password').value   = '';
  document.getElementById('pwHint').style.display = 'inline';
  document.getElementById('email').value      = u.email || '';
  document.getElementById('phone').value      = u.phone || '';
  document.getElementById('role').value       = u.role  || '';
  document.getElementById('isActive').value   = u.is_active ? '1' : '0';
  const cSel = document.getElementById('companyId');
  if (cSel) cSel.value = u.company_id || '';
  const bSel = document.getElementById('branchId');
  if (bSel) bSel.value = u.branch_id || '';
  goToStep(1);
  new bootstrap.Modal(document.getElementById('userModal')).show();
}

/* ── Stepper navigation ── */
function goToStep(n) {
  document.getElementById('step1').style.display  = n === 1 ? '' : 'none';
  document.getElementById('step2').style.display  = n === 2 ? '' : 'none';
  document.getElementById('nextBtn').style.display = n === 1 ? '' : 'none';
  document.getElementById('backBtn').style.display = n === 2 ? '' : 'none';
  document.getElementById('saveBtn').style.display = n === 2 ? '' : 'none';
  document.getElementById('step1Tab').className = 'step-item' + (n === 1 ? ' active' : ' done');
  document.getElementById('step2Tab').className = 'step-item' + (n === 2 ? ' active' : '');

  if (n === 2) {
    // Basic form validation before going to step 2
    const form = document.getElementById('userForm');
    if (!form.reportValidity()) return;
    loadPermissionMatrix('permissionsLoading', 'permissionsMatrix');
  }
}

/* ── Load permission matrix ── */
async function loadPermissionMatrix(loadingId, matrixId) {
  const loadingEl = document.getElementById(loadingId);
  loadingEl.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div> Loading permissions…';
  loadingEl.style.display = '';
  document.getElementById(matrixId).style.display = 'none';

  try {
    const res  = await fetch('../api/get_permissions.php?_=' + Date.now());
    const json = await res.json();

    if (!json.success) {
      document.getElementById(loadingId).innerHTML =
        `<div style="color:var(--red)"><i class="bi bi-exclamation-circle me-1"></i>${json.message || 'Failed to load permissions'}</div>`;
      return;
    }

    availablePerms = json.data || {};

    // Load existing permissions if editing
    let existing = {};
    if (editUserId > 0 || permTargetId > 0) {
      try {
        const uid = editUserId > 0 ? editUserId : permTargetId;
        const r2  = await fetch(`../api/get_user_permissions.php?user_id=${uid}&_=${Date.now()}`);
        if (r2.ok) {
          const j2 = await r2.json();
          if (j2.success && Array.isArray(j2.data)) {
            j2.data.forEach(p => { if (p.is_override) existing[p.id] = p.is_allowed; });
          }
        }
      } catch (e) {
        // Non-critical — matrix loads with role defaults
      }
    }

    renderPermMatrix(matrixId, availablePerms, existing);
    document.getElementById(loadingId).style.display = 'none';
    document.getElementById(matrixId).style.display  = '';

  } catch (err) {
    document.getElementById(loadingId).innerHTML =
      `<div style="color:var(--red)"><i class="bi bi-exclamation-circle me-1"></i>Error: ${err.message}</div>`;
  }
}

function renderPermMatrix(containerId, modules, existing) {
  const container = document.getElementById(containerId);
  let html = '';
  for (const [mod, perms] of Object.entries(modules)) {
    html += `<div class="perm-module">
      <div class="perm-module-title">${mod}</div>
      <div class="perm-grid">`;
    perms.forEach(p => {
      const checked = existing[p.id] === 1;
      html += `<label class="perm-item ${checked ? 'checked' : ''}" id="pitem_${p.id}">
        <input type="checkbox" id="perm_${p.id}" value="${p.id}" ${checked ? 'checked' : ''}
          onchange="this.closest('.perm-item').classList.toggle('checked', this.checked)">
        <span>${p.permission_label}</span>
      </label>`;
    });
    html += `</div></div>`;
  }
  container.innerHTML = html || '<p style="color:var(--text-muted);font-size:.85rem">No permissions available to grant.</p>';
}

function collectPermissions(containerId) {
  const perms = [];
  document.querySelectorAll(`#${containerId} input[type=checkbox]`).forEach(cb => {
    perms.push({ permission_id: parseInt(cb.value), is_allowed: cb.checked ? 1 : 0 });
  });
  return perms;
}

/* ── Save user (step 2) ── */
async function saveUser() {
  const btn = document.getElementById('saveBtn');
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';

  const fd = new FormData(document.getElementById('userForm'));
  const endpoint = isEdit ? '../api/update_user.php' : '../api/add_user.php';

  try {
    const res  = await fetch(endpoint, { method: 'POST', body: fd });
    const json = await res.json();

    if (!json.success) {
      showToast(json.message || 'Failed to save user', 'err');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-check-lg"></i> Save User';
      return;
    }

    const newUserId = json.data?.user_id || editUserId;

    // Save permissions
    const perms = collectPermissions('permissionsMatrix');
    if (perms.length > 0 && newUserId > 0) {
      const pfd = new FormData();
      pfd.append('user_id', newUserId);
      pfd.append('permissions', JSON.stringify(perms));
      await fetch('../api/save_user_permissions.php', { method: 'POST', body: pfd });
    }

    showToast(isEdit ? 'User updated successfully' : 'User created successfully', 'ok');
    bootstrap.Modal.getInstance(document.getElementById('userModal'))?.hide();
    setTimeout(() => location.reload(), 800);
  } catch {
    showToast('Network error', 'err');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-lg"></i> Save User';
  }
}

/* ── Permissions for existing user ── */
function openPermissions(userId, name) {
  permTargetId = userId;
  editUserId   = 0;
  document.getElementById('permModalTitle').textContent = `Permissions — ${name}`;
  document.getElementById('permModalMatrix').innerHTML = '';
  loadPermissionMatrix('permModalLoading', 'permModalMatrix');
  new bootstrap.Modal(document.getElementById('permModal')).show();
}

async function savePermissions() {
  const perms = collectPermissions('permModalMatrix');
  const fd = new FormData();
  fd.append('user_id', permTargetId);
  fd.append('permissions', JSON.stringify(perms));
  try {
    const res  = await fetch('../api/save_user_permissions.php', { method: 'POST', body: fd });
    const json = await res.json();
    showToast(json.success ? 'Permissions saved' : (json.message || 'Failed'), json.success ? 'ok' : 'err');
    if (json.success) bootstrap.Modal.getInstance(document.getElementById('permModal'))?.hide();
  } catch { showToast('Network error', 'err'); }
}

/* ── Toggle status ── */
async function toggleStatus(userId, newStatus) {
  const fd = new FormData();
  fd.append('user_id', userId);
  fd.append('is_active', newStatus);
  const res  = await fetch('../api/update_user_status.php', { method: 'POST', body: fd });
  const json = await res.json();
  showToast(json.success ? 'Status updated' : (json.message || 'Failed'), json.success ? 'ok' : 'err');
  if (json.success) setTimeout(() => location.reload(), 600);
}

/* ── Delete user ── */
async function deleteUser(userId, name) {
  if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
  const fd = new FormData();
  fd.append('id', userId);
  const res  = await fetch('../api/delete_user.php', { method: 'POST', body: fd });
  const json = await res.json();
  showToast(json.success ? 'User deleted' : (json.message || 'Failed'), json.success ? 'ok' : 'err');
  if (json.success) setTimeout(() => location.reload(), 600);
}

/* ── Search ── */
function filterUsers() {
  const q = document.getElementById('userSearch').value.toLowerCase();
  document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
    const n = tr.dataset.name || '';
    const u = tr.dataset.username || '';
    tr.style.display = (!q || n.includes(q) || u.includes(q)) ? '' : 'none';
  });
}
</script>

<?php include 'layout_bottom.php'; ?>