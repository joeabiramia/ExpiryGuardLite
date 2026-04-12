<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: admin/dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ExpiryGuard Lite - Admin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-5">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h2 class="mb-3 text-center">ExpiryGuard Lite</h2>
                    <p class="text-muted text-center">Admin dashboard login</p>
                    <div id="message"></div>
                    <form id="loginForm">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Login</button>
                    </form>
                    <div class="mt-3 small text-muted">
                       
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('loginForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const msg = document.getElementById('message');

    try {
        const res = await fetch('api/login.php', {
            method: 'POST',
            body: formData
        });

        const text = await res.text();
        const data = JSON.parse(text);

        msg.innerHTML = `<div class="alert alert-${data.success ? 'success' : 'danger'}">${data.message}</div>`;

        if (data.success) {
            window.location.href = 'admin/dashboard.php';
        }
    } catch (error) {
        msg.innerHTML = `<div class="alert alert-danger">Server error. Please try again.</div>`;
    }
});
</script>
</body>
</html>
