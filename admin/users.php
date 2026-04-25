<?php include 'layout_top.php'; ?>
<?php
requireAdmin();
require_once '../config/db.php';
require_once '../config/helpers.php';

$users = $conn->query("
    SELECT 
        u.*,
        c.company_name,
        b.branch_name
    FROM users u
    LEFT JOIN companies c ON c.id = u.company_id
    LEFT JOIN branches b ON b.id = u.branch_id
    ORDER BY u.id DESC
");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Users Management</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        Add User
    </button>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Company</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php while ($user = $users->fetch_assoc()): ?>
                <?php
                $roleColor = 'secondary';

                switch ($user['role']) {
                    case 'super_admin':
                        $roleColor = 'danger';
                        break;
                    case 'company_admin':
                        $roleColor = 'primary';
                        break;
                    case 'branch_manager':
                        $roleColor = 'warning text-dark';
                        break;
                    case 'employee':
                        $roleColor = 'success';
                        break;
                    case 'viewer':
                        $roleColor = 'dark';
                        break;
                }
                ?>

                <tr>
                    <td><?= (int)$user['id'] ?></td>
                    <td><?= htmlspecialchars($user['full_name']) ?></td>
                    <td><?= htmlspecialchars($user['username']) ?></td>
                    <td>
                        <span class="badge bg-<?= $roleColor ?>">
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($user['company_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($user['branch_name'] ?? '-') ?></td>
                    <td>
                        <span class="badge bg-<?= !empty($user['is_active']) ? 'success' : 'secondary' ?>">
                            <?= !empty($user['is_active']) ? 'Active' : 'Disabled' ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($user['created_at']) ?></td>
                    <td>
                        <button
                            class="btn btn-sm btn-outline-primary"
                            data-bs-toggle="modal"
                            data-bs-target="#editUserModal<?= (int)$user['id'] ?>"
                        >
                            Edit
                        </button>

                        <?php if ((int)$user['id'] !== (int)$_SESSION['user_id']): ?>
                            <form
                                method="POST"
                                action="../api/delete_user.php"
                                class="d-inline"
                                onsubmit="return confirm('Delete this user?');"
                            >
                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger">
                                    Delete
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Edit User Modal -->
                <div class="modal fade" id="editUserModal<?= (int)$user['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="../api/update_user.php">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit User</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>

                                <div class="modal-body">
                                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Full Name</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            name="full_name"
                                            value="<?= htmlspecialchars($user['full_name']) ?>"
                                            required
                                        >
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Username</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            name="username"
                                            value="<?= htmlspecialchars($user['username']) ?>"
                                            required
                                        >
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">New Password (optional)</label>
                                        <input
                                            type="password"
                                            class="form-control"
                                            name="password"
                                        >
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Company</label>
                                        <select name="company_id" class="form-select" required>
                                            <option value="">Select Company</option>
                                            <?php
                                            $companiesEdit = $conn->query("
                                                SELECT id, company_name
                                                FROM companies
                                                WHERE is_active = 1
                                                ORDER BY company_name ASC
                                            ");
                                            while ($company = $companiesEdit->fetch_assoc()):
                                            ?>
                                                <option
                                                    value="<?= (int)$company['id'] ?>"
                                                    <?= ((int)$user['company_id'] === (int)$company['id']) ? 'selected' : '' ?>
                                                >
                                                    <?= htmlspecialchars($company['company_name']) ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Branch</label>
                                        <select name="branch_id" class="form-select">
                                            <option value="">Select Branch</option>
                                            <?php
                                            $branchesEdit = $conn->query("
                                                SELECT id, branch_name
                                                FROM branches
                                                WHERE is_active = 1
                                                ORDER BY branch_name ASC
                                            ");
                                            while ($branch = $branchesEdit->fetch_assoc()):
                                            ?>
                                                <option
                                                    value="<?= (int)$branch['id'] ?>"
                                                    <?= ((int)$user['branch_id'] === (int)$branch['id']) ? 'selected' : '' ?>
                                                >
                                                    <?= htmlspecialchars($branch['branch_name']) ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">User Role</label>
                                        <select name="role" class="form-select" required>
                                            <option value="">Select Role</option>
                                            <option value="super_admin" <?= $user['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                                            <option value="company_admin" <?= $user['role'] === 'company_admin' ? 'selected' : '' ?>>Company Admin</option>
                                            <option value="branch_manager" <?= $user['role'] === 'branch_manager' ? 'selected' : '' ?>>Branch Manager</option>
                                            <option value="employee" <?= $user['role'] === 'employee' ? 'selected' : '' ?>>Employee</option>
                                            <option value="viewer" <?= $user['role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                                        </select>
                                    </div>

                                  <div class="mb-3">
    <label class="form-label fw-semibold">User Status</label>
    <select name="is_active" class="form-select" required>
        <option value="1" <?= !empty($user['is_active']) ? 'selected' : '' ?>>
            Active
        </option>
        <option value="0" <?= empty($user['is_active']) ? 'selected' : '' ?>>
            Disabled
        </option>
    </select>
</div>
                                </div>

                                <div class="modal-footer">
                                    <button class="btn btn-primary">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="../api/add_user.php">
                <div class="modal-header">
                    <h5 class="modal-title">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company</label>
                        <select name="company_id" class="form-select" required>
                            <option value="">Select Company</option>
                            <?php
                            $companies = $conn->query("
                                SELECT id, company_name
                                FROM companies
                                WHERE is_active = 1
                                ORDER BY company_name ASC
                            ");
                            while ($company = $companies->fetch_assoc()):
                            ?>
                                <option value="<?= (int)$company['id'] ?>">
                                    <?= htmlspecialchars($company['company_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">Select Branch</option>
                            <?php
                            $branches = $conn->query("
                                SELECT id, branch_name
                                FROM branches
                                WHERE is_active = 1
                                ORDER BY branch_name ASC
                            ");
                            while ($branch = $branches->fetch_assoc()):
                            ?>
                                <option value="<?= (int)$branch['id'] ?>">
                                    <?= htmlspecialchars($branch['branch_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">User Role</label>
                        <select name="role" class="form-select" required>
                            <option value="">Select Role</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="company_admin">Company Admin</option>
                            <option value="branch_manager">Branch Manager</option>
                            <option value="employee">Employee</option>
                            <option value="viewer">Viewer</option>
                        </select>
                    </div>

                   <div class="mb-3">
    <label class="form-label fw-semibold">User Status</label>
    <select name="is_active" class="form-select" required>
        <option value="1" selected>Active</option>
        <option value="0">Disabled</option>
    </select>
</div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'layout_bottom.php'; ?>