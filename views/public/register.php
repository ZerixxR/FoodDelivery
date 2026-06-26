<?php
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../config/database.php';

if (isLoggedIn()) redirect(BASE_URL . 'views/public/index.php');
$pageTitle = 'Daftar Akun';
$role = in_array($_GET['role'] ?? '', ['buyer','seller','driver']) ? $_GET['role'] : 'buyer';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daftar - FoodDelivery</title>
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
      <h2 class="fw-bold">Buat Akun Baru</h2>
      <p class="text-muted small">Bergabung dengan FoodDelivery sekarang</p>
    </div>

    <!-- Role Tabs -->
    <div class="d-flex gap-2 mb-4 p-1 bg-light rounded-3">
      <a href="?role=buyer"  class="btn flex-fill fw-semibold btn-sm <?= $role==='buyer'  ? 'btn-danger' : 'btn-light' ?>">
        <i class="bi bi-person me-1"></i>Pembeli
      </a>
      <a href="?role=seller" class="btn flex-fill fw-semibold btn-sm <?= $role==='seller' ? 'btn-danger' : 'btn-light' ?>">
        <i class="bi bi-shop me-1"></i>Restoran
      </a>
      <a href="?role=driver" class="btn flex-fill fw-semibold btn-sm <?= $role==='driver' ? 'btn-danger' : 'btn-light' ?>">
        <i class="bi bi-truck me-1"></i>Driver
      </a>
    </div>

    <?php if ($role !== 'buyer'): ?>
    <div class="alert alert-warning d-flex gap-2 py-2 small mb-3">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
      <span>Akun <strong><?= $role === 'seller' ? 'Restoran' : 'Driver' ?></strong> memerlukan verifikasi admin sebelum dapat digunakan.</span>
    </div>
    <?php endif; ?>

    <!-- ===== PERBAIKAN 1: Tambahkan ID dan hapus action/method ===== -->
    <form id="registerForm">
      <input type="hidden" name="action" value="register">
      <input type="hidden" name="role"   value="<?= $role ?>">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">
          <?= $role === 'seller' ? 'Nama Restoran' : 'Nama Lengkap' ?>
          <span class="text-danger">*</span>
        </label>
        <div class="input-group">
          <span class="input-group-text bg-light">
            <i class="bi bi-<?= $role === 'seller' ? 'shop' : 'person' ?> text-muted"></i>
          </span>
          <input type="text" name="name" class="form-control" required minlength="3"
                 placeholder="<?= $role === 'seller' ? 'Nama restoran kamu' : 'Nama lengkap kamu' ?>">
          <div class="invalid-feedback">Nama minimal 3 karakter.</div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
        <div class="input-group">
          <span class="input-group-text bg-light"><i class="bi bi-envelope text-muted"></i></span>
          <input type="email" name="email" class="form-control" required placeholder="email@contoh.com">
          <div class="invalid-feedback">Masukkan email yang valid.</div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Nomor HP <span class="text-danger">*</span></label>
        <div class="input-group">
          <span class="input-group-text bg-light"><i class="bi bi-telephone text-muted"></i></span>
          <input type="tel" name="phone" class="form-control" required placeholder="08xxxxxxxxxx">
          <div class="invalid-feedback">Nomor HP wajib diisi.</div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">
          <?= $role === 'seller' ? 'Alamat Restoran' : 'Alamat' ?>
        </label>
        <textarea name="address" class="form-control" rows="2"
                  placeholder="Alamat lengkap..."></textarea>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6">
          <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
          <input type="password" name="password" id="password" class="form-control"
                 required minlength="8" placeholder="Min. 8 karakter">
          <div class="invalid-feedback">Password minimal 8 karakter.</div>
        </div>
        <div class="col-6">
          <label class="form-label fw-semibold">Konfirmasi <span class="text-danger">*</span></label>
          <!-- ===== PERBAIKAN 2: Ganti name jadi password_confirm ===== -->
          <input type="password" name="password_confirm" id="password_confirmation"
                 class="form-control" required placeholder="Ulangi password">
          <div class="invalid-feedback">Password tidak cocok.</div>
        </div>
      </div>

      <!-- Indikator kekuatan password -->
      <div class="mb-3">
        <div class="d-flex gap-1 mb-1">
          <div class="flex-fill rounded-pill" id="s1" style="height:4px;background:#e2e8f0;transition:background .3s"></div>
          <div class="flex-fill rounded-pill" id="s2" style="height:4px;background:#e2e8f0;transition:background .3s"></div>
          <div class="flex-fill rounded-pill" id="s3" style="height:4px;background:#e2e8f0;transition:background .3s"></div>
          <div class="flex-fill rounded-pill" id="s4" style="height:4px;background:#e2e8f0;transition:background .3s"></div>
        </div>
        <small id="str-label" class="text-muted">Kekuatan password</small>
      </div>

      <button type="submit" class="btn btn-danger w-100 py-3 fw-bold">
        <i class="bi bi-person-check me-2"></i>Daftar sebagai
        <?= $role === 'seller' ? 'Restoran' : ($role === 'driver' ? 'Driver' : 'Pembeli') ?>
      </button>
    </form>

    <p class="text-center mt-4 mb-0 small">
      Sudah punya akun?
      <a href="<?= BASE_URL ?>views/public/login.php" class="text-danger fw-semibold">Login di sini</a>
    </p>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- ============================================================ -->
<!-- ============ JAVASCRIPT ============ -->
<!-- ============================================================ -->
<script>
// =============================================
// 1. Validasi konfirmasi password
// =============================================
document.getElementById('password_confirmation').addEventListener('input', function() {
  this.setCustomValidity(
    this.value !== document.getElementById('password').value ? 'Password tidak cocok' : ''
  );
});

// =============================================
// 2. Indikator kekuatan password
// =============================================
document.getElementById('password').addEventListener('input', function() {
  const v = this.value;
  let score = 0;
  if (v.length >= 8)           score++;
  if (/[A-Z]/.test(v))         score++;
  if (/[0-9]/.test(v))         score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;

  const colors = ['#ef4444','#f97316','#eab308','#22c55e'];
  const labels = ['Lemah','Sedang','Kuat','Sangat Kuat'];
  for (let i = 1; i <= 4; i++) {
    document.getElementById('s'+i).style.background = i <= score ? colors[score-1] : '#e2e8f0';
  }
  const lbl = document.getElementById('str-label');
  lbl.textContent = score > 0 ? labels[score-1] : 'Kekuatan password';
  lbl.style.color  = score > 0 ? colors[score-1] : '#94a3b8';
});

// =============================================
// 3. REGISTER - INI YANG UTAMA
// =============================================
const CSRF_TOKEN = '<?= generateCsrfToken() ?>';
const BASE_URL = '<?= BASE_URL ?>';

document.getElementById('registerForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const form = this;
    const btn = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);
    
    // Disable button
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Memproses...';
    
    fetch(BASE_URL + 'controllers/AuthController.php?action=register', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            if (data.auto_login) {
                window.location.href = data.redirect;
            } else {
                window.location.href = data.redirect;
            }
        } else {
            alert('Gagal: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-person-check me-2"></i>Daftar sebagai <?= $role === 'seller' ? 'Restoran' : ($role === 'driver' ? 'Driver' : 'Pembeli') ?>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan. Silakan coba lagi.');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-person-check me-2"></i>Daftar sebagai <?= $role === 'seller' ? 'Restoran' : ($role === 'driver' ? 'Driver' : 'Pembeli') ?>';
    });
});

// =============================================
// 4. Bootstrap validation (tidak conflict karena form pakai ID)
// =============================================
document.getElementById('registerForm').addEventListener('submit', function(e) {
  if (!this.checkValidity()) {
    e.preventDefault();
    e.stopPropagation();
  }
  this.classList.add('was-validated');
});
</script>

</body>
</html>