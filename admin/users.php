<?php include 'layout_top.php'; ?>
<?php requireAdmin(); require_once '../config/db.php'; ?>
<?php
$users = $conn->query('SELECT * FROM users ORDER BY id DESC');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $full_name = $_POST['full_name'];
        $username = $_POST['username'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $stmt = $conn->prepare('INSERT INTO users (full_name, username, password, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->bind_param('ssssi', $full_name, $username, $password, $role, $is_active);
        $stmt->execute();
        header('Location: users.php'); exit();
    }

    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $full_name = $_POST['full_name'];
        $username = $_POST['username'];
        $role = $_POST['role'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET full_name=?, username=?, password=?, role=?, is_active=? WHERE id=?');
            $stmt->bind_param('ssssii', $full_name, $username, $password, $role, $is_active, $id);
        } else {
            $stmt = $conn->prepare('UPDATE users SET full_name=?, username=?, role=?, is_active=? WHERE id=?');
            $stmt->bind_param('sssii', $full_name, $username, $role, $is_active, $id);
        }
        $stmt->execute();
        header('Location: users.php'); exit();
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare('DELETE FROM users WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        header('Location: users.php'); exit();
    }
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Users</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">Add User</button>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>ID</th><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($user = $users->fetch_assoc()): ?>
                <tr>
                    <td><?= $user['id'] ?></td>
                    <td><?= htmlspecialchars($user['full_name']) ?></td>
                    <td><?= htmlspecialchars($user['username']) ?></td>
                    <td><span class="badge bg-<?= $user['role'] === 'admin' ? 'danger' : 'secondary' ?>"><?= htmlspecialchars($user['role']) ?></span></td>
                    <td><span class="badge bg-<?= $user['is_active'] ? 'success' : 'warning text-dark' ?>"><?= $user['is_active'] ? 'Active' : 'Disabled' ?></span></td>
                    <td><?= $user['created_at'] ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?= $user['id'] ?>">Edit</button>
                        <?php if ((int)$user['id'] !== (int)$_SESSION['user_id']): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $user['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>

                <div class="modal fade" id="editUserModal<?= $user['id'] ?>" tabindex="-1">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <form method="POST">
                        <div class="modal-header"><h5 class="modal-title">Edit User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?= $user['id'] ?>">
                            <div class="mb-3"><label class="form-label">Full Name</label><input class="form-control" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required></div>
                            <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" value="<?= htmlspecialchars($user['username']) ?>" required></div>
                            <div class="mb-3"><label class="form-label">New Password (optional)</label><input type="password" class="form-control" name="password"></div>
                            <div class="mb-3"><label class="form-label">Role</label>
                                <select class="form-select" name="role">
                                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    <option value="employee" <?= $user['role'] === 'employee' ? 'selected' : '' ?>>Employee</option>
                                </select>
                            </div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" <?= $user['is_active'] ? 'checked' : '' ?>><label class="form-check-label">Active</label></div>
                        </div>
                        <div class="modal-footer"><button class="btn btn-primary">Save Changes</button></div>
                      </form>
                    </div>
                  </div>
                </div>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header"><h5 class="modal-title">Add User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input type="hidden" name="action" value="add">
            <div class="mb-3"><label class="form-label">Full Name</label><input class="form-control" name="full_name" required></div>
            <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
            <div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control" name="password" required></div>
            <div class="mb-3"><label class="form-label">Role</label><select class="form-select" name="role"><option value="employee">Employee</option><option value="admin">Admin</option></select></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" checked><label class="form-check-label">Active</label></div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">Add User</button></div>
      </form>
    </div>
  </div>
</div>
<?php include 'layout_bottom.php'; ?>
