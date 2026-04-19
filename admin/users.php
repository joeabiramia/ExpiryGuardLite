<?php include 'layout_top.php'; ?>
<?php
requireAdmin();
require_once '../config/db.php';
require_once '../config/helpers.php';

/*
|--------------------------------------------------------------------------
| Users List Only
|--------------------------------------------------------------------------
|
| IMPORTANT:
| users.php should be UI only
| Backend logic must stay inside:
|
| api/add_user.php
| api/update_user.php
| api/delete_user.php
|
*/

$users = $conn->query("
    SELECT *
    FROM users
    ORDER BY id DESC
");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Users Management</h2>
    <button
        class="btn btn-primary"
        data-bs-toggle="modal"
        data-bs-target="#addUserModal"
    >
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
                /*
                |--------------------------------------------------------------------------
                | Role Badge Color
                |--------------------------------------------------------------------------
                */

                $roleColor = 'secondary';

                switch ($user['role']) {
                    case 'super_admin':
                        $roleColor = 'danger';
                        break;

                    case 'company_admin':
                        $roleColor = 'primary';
                        break;

                    case 'branch_manager':
                        $roleColor = 'warning';
                        break;

                    case 'employee':
                        $roleColor = 'success';
                        break;

                    case 'viewer':
                        $roleColor = 'dark';
                        break;
                }

                /*
                |--------------------------------------------------------------------------
                | Company Name
                |--------------------------------------------------------------------------
                */

                $companyName = '-';

                if (!empty($user['company_id'])) {
                    $companyStmt = $conn->prepare("
                        SELECT company_name
                        FROM companies
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $companyStmt->bind_param("i", $user['company_id']);
                    $companyStmt->execute();

                    $companyResult = $companyStmt->get_result();

                    if ($companyResult->num_rows > 0) {
                        $companyData = $companyResult->fetch_assoc();
                        $companyName = $companyData['company_name'];
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Branch Name
                |--------------------------------------------------------------------------
                */

                $branchName = '-';

                if (!empty($user['branch_id'])) {
                    $branchStmt = $conn->prepare("
                        SELECT branch_name
                        FROM branches
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $branchStmt->bind_param("i", $user['branch_id']);
                    $branchStmt->execute();

                    $branchResult = $branchStmt->get_result();

                    if ($branchResult->num_rows > 0) {
                        $branchData = $branchResult->fetch_assoc();
                        $branchName = $branchData['branch_name'];
                    }
                }
                ?>

                <tr>
                    <td><?= $user['id'] ?></td>

                    <td><?= htmlspecialchars($user['full_name']) ?></td>

                    <td><?= htmlspecialchars($user['username']) ?></td>

                    <td>
                        <span class="badge bg-<?= $roleColor ?>">
                            <?= ucwords(str_replace('_', ' ', $user['role'])) ?>
                        </span>
                    </td>

                    <td><?= htmlspecialchars($companyName) ?></td>

                    <td><?= htmlspecialchars($branchName) ?></td>

                    <td>
                        <span class="badge bg-<?= $user['is_active'] ? 'success' : 'secondary' ?>">
                            <?= $user['is_active'] ? 'Active' : 'Disabled' ?>
                        </span>
                    </td>

                    <td><?= $user['created_at'] ?></td>

                    <td>
                        <button
                            class="btn btn-sm btn-outline-primary"
                            disabled
                        >
                            Edit
                        </button>

                        <?php if ((int)$user['id'] !== (int)$_SESSION['user_id']): ?>
                            <button
                                class="btn btn-sm btn-outline-danger"
                                disabled
                            >
                                Delete
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>

            <?php endwhile; ?>

            </tbody>
        </table>

    </div>
</div>


<!-- ===================================================== -->
<!-- Add User Modal -->
<!-- ===================================================== -->

<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">

            <form method="POST" action="../api/add_user.php">

                <div class="modal-header">
                    <h5 class="modal-title">
                        Add New User
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>
                </div>

                <div class="modal-body">

                    <!-- Full Name -->

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Full Name
                        </label>

                        <input
                            type="text"
                            name="full_name"
                            class="form-control"
                            required
                        >
                    </div>


                    <!-- Username -->

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Username
                        </label>

                        <input
                            type="text"
                            name="username"
                            class="form-control"
                            required
                        >
                    </div>


                    <!-- Password -->

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Password
                        </label>

                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            required
                        >
                    </div>


                    <!-- Company -->

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Company
                        </label>

                        <select
                            name="company_id"
                            class="form-select"
                            required
                        >
                            <option value="">
                                Select Company
                            </option>

                            <?php
                            $companies = $conn->query("
                                SELECT id, company_name
                                FROM companies
                                WHERE is_active = 1
                                ORDER BY company_name ASC
                            ");

                            while ($company = $companies->fetch_assoc()):
                            ?>
                                <option value="<?= $company['id'] ?>">
                                    <?= htmlspecialchars($company['company_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>


                    <!-- Branch -->

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Branch
                        </label>

                        <select
                            name="branch_id"
                            class="form-select"
                        >
                            <option value="">
                                Select Branch
                            </option>

                            <?php
                            $branches = $conn->query("
                                SELECT id, branch_name
                                FROM branches
                                WHERE is_active = 1
                                ORDER BY branch_name ASC
                            ");

                            while ($branch = $branches->fetch_assoc()):
                            ?>
                                <option value="<?= $branch['id'] ?>">
                                    <?= htmlspecialchars($branch['branch_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>


                    <!-- Role -->

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            User Role
                        </label>

                        <select
                            name="role"
                            class="form-select"
                            required
                        >
                            <option value="">
                                Select Role
                            </option>

                            <option value="super_admin">
                                Super Admin
                            </option>

                            <option value="company_admin">
                                Company Admin
                            </option>

                            <option value="branch_manager">
                                Branch Manager
                            </option>

                            <option value="employee">
                                Employee
                            </option>

                            <option value="viewer">
                                Viewer
                            </option>
                        </select>
                    </div>


                    <!-- Active -->

                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="is_active"
                            checked
                        >

                        <label class="form-check-label">
                            Active User
                        </label>
                    </div>

                </div>


                <div class="modal-footer">
                    <button class="btn btn-primary">
                        Add User
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<?php include 'layout_bottom.php'; ?>