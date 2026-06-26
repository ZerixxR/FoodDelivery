<?php
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../config/database.php';

if (isLoggedIn()) redirect(BASE_URL . 'views/public/index.php');
$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - FoodDelivery</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body">

<div class="min-vh-100 d-flex align-items-center justify-content-center py-5">
  <div class="auth-card shadow-lg">

    <!-- Logo -->
    <div class="text-center mb-4">
      <div class="brand-icon mx-auto mb-3">
        <i class="bi bi-bicycle"></i>
      </div>
      <h2 class="fw-bold">Masuk ke FoodDelivery</h2>
      <p class="text-muted small">Pesan makanan favoritmu sekarang!</p>
    </div>

    <!-- Flash Message -->
    <?php $flash = getFlash(); if ($flash): ?>
    <div class="alert alert-<?= $flash['type']==='success'?'success':'danger' ?> alert-dismissible py-2 small">
      <i class="bi bi-<?= $flash['type']==='success'?'check-circle':'exclamation-triangle' ?> me-2"></i>
      <?= sanitize($flash['message']) ?>
      <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ===== PERBAIKAN: Hapus action & method, tambah ID ===== -->
    <form id="loginForm">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Email</label>
        <div class="input-group">
          <span class="input-group-text bg-light"><i class="bi bi-envelope text-muted"></i></span>
          <input type="email" name="email" id="email" class="form-control" required autofocus
                placeholder="email@contoh.com"
                value="<?= sanitize($_GET['email'] ?? '') ?>">
          <div class="invalid-feedback">Masukkan email yang valid.</div>
        </div>
      </div>

      <div class="mb-3">
        <div class="d-flex justify-content-between mb-1">
          <label class="form-label fw-semibold mb-0">Password</label>
          <a href="#" class="small text-danger">Lupa password?</a>
        </div>
        <div class="input-group">
          <span class="input-group-text bg-light"><i class="bi bi-lock text-muted"></i></span>
          <input type="password" name="password" id="pw" class="form-control"
                 required placeholder="••••••••">
          <button type="button" class="btn btn-light border" id="toggle-pw">
            <i class="bi bi-eye" id="eye-icon"></i>
          </button>
          <div class="invalid-feedback">Password wajib diisi.</div>
        </div>
      </div>

      <div class="d-flex align-items-center gap-2 mb-4">
        <input class="form-check-input mt-0" type="checkbox" name="remember_me" id="remember">
        <label class="form-check-label small" for="remember">Ingat saya 30 hari</label>
      </div>

      <button type="submit" class="btn btn-danger w-100 py-3 fw-bold">
        <i class="bi bi-box-arrow-in-right me-2"></i>Masuk
      </button>
    </form>

    <hr class="my-4">

    <!-- Akun Demo -->
    <div class="bg-light rounded-3 p-3 mb-4">
      <p class="small fw-bold text-muted mb-2">🔑 Akun Demo (untuk testing):</p>
      <div class="row g-1 small">
        <div class="col-6">
          <div class="fw-semibold text-danger">Admin</div>
          <div class="text-muted" style="font-size:11px">admin@food.com</div>
          <div class="text-muted" style="font-size:11px">admin123</div>
        </div>
        <div class="col-6">
          <div class="fw-semibold text-warning">Restoran</div>
          <div class="text-muted" style="font-size:11px">seller@food.com</div>
          <div class="text-muted" style="font-size:11px">seller123</div>
        </div>
        <div class="col-6 mt-2">
          <div class="fw-semibold text-success">Pembeli</div>
          <div class="text-muted" style="font-size:11px">buyer@food.com</div>
          <div class="text-muted" style="font-size:11px">buyer123</div>
        </div>
        <div class="col-6 mt-2">
          <div class="fw-semibold text-primary">Driver</div>
          <div class="text-muted" style="font-size:11px">driver@food.com</div>
          <div class="text-muted" style="font-size:11px">driver123</div>
        </div>
      </div>
    </div>

    <p class="text-center mb-0 small">
      Belum punya akun?
      <a href="<?= BASE_URL ?>views/public/register.php" class="text-danger fw-semibold">Daftar sekarang</a>
    </p>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- ============================================================ -->
<!-- ============ JAVASCRIPT LENGKAP ============ -->
<!-- ============================================================ -->
<script>
// =============================================
// 1. Toggle show/hide password
// =============================================
document.getElementById('toggle-pw').addEventListener('click', function() {
  const pw   = document.getElementById('pw');
  const icon = document.getElementById('eye-icon');
  if (pw.type === 'password') {
    pw.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    pw.type = 'password';
    icon.className = 'bi bi-eye';
  }
});

// =============================================
// 2. Bootstrap validation (support untuk fetch)
// =============================================
document.getElementById('loginForm').addEventListener('submit', function(e) {
  if (!this.checkValidity()) {
    e.preventDefault();
    e.stopPropagation();
  }
  this.classList.add('was-validated');
});

// =============================================
// 3. LOGIN - FETCH KE AUTH CONTROLLER
// =============================================
const CSRF_TOKEN = '<?= generateCsrfToken() ?>';
const BASE_URL = '<?= BASE_URL ?>';

document.getElementById('loginForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const form = this;
    const btn = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);
    
    // Disable button
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Memproses...';
    
    fetch(BASE_URL + 'controllers/AuthController.php?action=login', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.href = data.redirect;
        } else {
            alert('Login gagal: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-box-arrow-in-right me-2"></i>Masuk';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan. Silakan coba lagi.');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-box-arrow-in-right me-2"></i>Masuk';
    });
});

// =============================================
// 4. LOGOUT - Fungsi global untuk navbar
// =============================================
function handleLogout(event) {
    event.preventDefault();
    
    if (!confirm('Yakin ingin logout?')) return;
    
    fetch(BASE_URL + 'controllers/AuthController.php?action=logout')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.redirect;
        } else {
            alert('Gagal logout');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan');
    });
}
</script>

</body>
</html>