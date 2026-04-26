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
<title>ExpiryGuard Pro — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.4/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo"><i class="bi bi-shield-lock-fill"></i></div>
    <h1 class="login-title">ExpiryGuard Pro</h1>
    <p class="login-sub">Sign in to your dashboard</p>

    <div id="loginMsg"></div>

    <form id="loginForm">
      <div class="form-group">
        <label class="eg-label">Username</label>
        <input type="text" name="username" class="eg-input" placeholder="Enter username" required autocomplete="username">
      </div>
      <div class="form-group" style="position:relative">
        <label class="eg-label">Password</label>
        <input type="password" name="password" id="passwordInput" class="eg-input" placeholder="Enter password" required autocomplete="current-password" style="padding-right:40px">
        <button type="button" onclick="togglePw()" style="position:absolute;right:10px;bottom:9px;background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0">
          <i class="bi bi-eye" id="pwEye"></i>
        </button>
      </div>
      <button type="submit" id="loginBtn" class="btn-eg btn-primary-eg w-100 justify-content-center" style="margin-top:8px">
        <i class="bi bi-box-arrow-in-right"></i>
        <span id="loginBtnText">Sign In</span>
      </button>
    </form>

    <p style="text-align:center;font-size:.72rem;color:var(--text-muted);margin-top:20px">
      ExpiryGuard Pro &copy; <?= date('Y') ?> — Secure product expiry management
    </p>
  </div>
</div>

<script>
function togglePw() {
  const inp = document.getElementById('passwordInput');
  const eye = document.getElementById('pwEye');
  if (inp.type === 'password') {
    inp.type = 'text'; eye.className = 'bi bi-eye-slash';
  } else {
    inp.type = 'password'; eye.className = 'bi bi-eye';
  }
}

document.getElementById('loginForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn     = document.getElementById('loginBtn');
  const btnText = document.getElementById('loginBtnText');
  const msg     = document.getElementById('loginMsg');

  btn.disabled = true;
  btnText.textContent = 'Signing in…';
  msg.innerHTML = '';

  try {
    const res  = await fetch('api/login.php', { method: 'POST', body: new FormData(this) });
    const data = await res.json();

    if (data.success) {
      btnText.textContent = 'Redirecting…';
      window.location.href = 'admin/dashboard.php';
    } else {
      msg.innerHTML = `<div style="background:var(--red-light);color:#991b1b;border-radius:9px;padding:10px 14px;font-size:.84rem;margin-bottom:14px"><i class="bi bi-exclamation-circle me-1"></i>${data.message}</div>`;
      btn.disabled = false;
      btnText.textContent = 'Sign In';
    }
  } catch {
    msg.innerHTML = `<div style="background:var(--red-light);color:#991b1b;border-radius:9px;padding:10px 14px;font-size:.84rem;margin-bottom:14px">Connection error. Please try again.</div>`;
    btn.disabled = false;
    btnText.textContent = 'Sign In';
  }
});
</script>
</body>
</html>