<?php
/**
 * profile.php
 * Halaman profil toko untuk seller
 * 
 * @package FoodDelivery
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

// Cek login dan role
if (!isLoggedIn()) {
    redirect(BASE_URL . 'views/public/login.php');
}

if (getUserRole() !== 'seller') {
    redirect(BASE_URL . 'views/public/index.php');
}

$userId = (int) $_SESSION['user_id'];
$db = getDB();

// Ambil data user
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('danger', 'User tidak ditemukan');
    redirect(BASE_URL . 'views/seller/dashboard.php');
}

// Proses update profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid CSRF token');
        redirect(BASE_URL . 'views/seller/profile.php');
    }
    
    if ($action === 'update_profile') {
        $name = sanitize($_POST['name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        
        if (empty($name)) {
            setFlash('danger', 'Nama toko wajib diisi');
        } else {
            $stmt = $db->prepare("
                UPDATE users 
                SET name = ?, phone = ?, address = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$name, $phone, $address, $userId]);
            setFlash('success', 'Profil toko berhasil diupdate!');
            
            // Update session
            $_SESSION['user']['name'] = $name;
            
            redirect(BASE_URL . 'views/seller/profile.php');
        }
    }
    
    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            setFlash('danger', 'Semua field password harus diisi');
        } elseif (strlen($newPassword) < 6) {
            setFlash('danger', 'Password baru minimal 6 karakter');
        } elseif ($newPassword !== $confirmPassword) {
            setFlash('danger', 'Konfirmasi password tidak cocok');
        } else {
            // Verifikasi password lama
            if (!verifyPassword($currentPassword, $user['password'])) {
                setFlash('danger', 'Password saat ini salah');
            } else {
                $newHash = hashPassword($newPassword);
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$newHash, $userId]);
                setFlash('success', 'Password berhasil diubah!');
            }
        }
        redirect(BASE_URL . 'views/seller/profile.php');
    }
}

$csrfToken = generateCsrfToken();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Toko - FoodDelivery</title>
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    
    <style>
        .sidebar {
            background: white;
            border-right: 1px solid #e9ecef;
            min-height: calc(100vh - 56px);
            padding: 20px 0;
        }
        .sidebar .nav-link {
            color: #6c757d;
            border-radius: 8px;
            padding: 10px 20px;
            margin: 2px 12px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .sidebar .nav-link:hover {
            background: #fff5f5;
            color: #dc3545;
        }
        .sidebar .nav-link.active {
            background: #dc3545;
            color: white;
        }
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #dc3545, #fd7e14);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
            margin: 0 auto 16px;
        }
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #e9ecef;
        }
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid #e9ecef;
            }
        }
    </style>
</head>
<body>

<!-- ============ NAVBAR ============ -->
<nav class="navbar navbar-expand-lg bg-white shadow-sm sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="<?= BASE_URL ?>views/public/index.php">
            <div class="brand-icon brand-icon-sm"><i class="bi bi-bicycle"></i></div>
            <span class="text-danger">Food</span><span>Delivery</span>
        </a>
        <div class="d-flex align-items-center gap-3">
            <span class="small text-muted d-none d-sm-inline">
                <i class="bi bi-shop me-1"></i> <?= sanitize($user['name'] ?? '') ?>
            </span>
            <a href="<?= BASE_URL ?>controllers/AuthController.php?action=logout" 
               class="btn btn-outline-danger btn-sm"
               onclick="event.preventDefault(); handleLogout(event)">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</nav>

<!-- ============ FLASH MESSAGE ============ -->
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible rounded-0 mb-0 py-2">
    <div class="container small">
        <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
        <?= sanitize($flash['message']) ?>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close" 
                style="position: absolute; top: 50%; right: 8px; transform: translateY(-50%); z-index: 2; padding: 0.5rem; font-size: 12px;"></button>
    </div>
</div>
<?php endif; ?>

<!-- ============ MAIN CONTENT ============ -->
<div class="container-fluid">
    <div class="row">
        
        <!-- ============ SIDEBAR ============ -->
        <div class="col-lg-2 col-md-3 d-none d-md-block p-0">
            <div class="sidebar">
                <div class="px-3 mb-3">
                    <div class="fw-bold text-danger"><?= sanitize($user['name'] ?? 'Restoran') ?></div>
                    <small class="text-muted">Penjual</small>
                </div>
                <nav class="nav flex-column">
                    <a class="nav-link" href="<?= BASE_URL ?>views/seller/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/seller/menu.php">
                        <i class="bi bi-grid"></i> Kelola Menu
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/seller/orders.php">
                        <i class="bi bi-bag"></i> Pesanan Masuk
                    </a>
                    <a class="nav-link active" href="<?= BASE_URL ?>views/seller/profile.php">
                        <i class="bi bi-shop"></i> Profil Toko
                    </a>
                </nav>
            </div>
        </div>
        
        <!-- ============ CONTENT ============ -->
        <div class="col-lg-10 col-md-9 col-12 p-4">
            <h4 class="fw-bold mb-4">
                <i class="bi bi-shop text-danger"></i> Profil Toko
            </h4>
            
            <div class="row g-4">
                <!-- ============ LEFT - AVATAR & INFO ============ -->
                <div class="col-lg-4">
                    <div class="form-card text-center">
                        <div class="profile-avatar">
                            <i class="bi bi-shop"></i>
                        </div>
                        <h5 class="fw-bold"><?= sanitize($user['name']) ?></h5>
                        <p class="text-muted small"><?= sanitize($user['email']) ?></p>
                        <p class="text-muted small">
                            <span class="badge bg-success">Aktif</span>
                            <span class="badge bg-info">Terverifikasi</span>
                        </p>
                        <hr>
                        <div class="text-start small">
                            <p><strong>Terdaftar:</strong> <?= date('d/m/Y', strtotime($user['created_at'])) ?></p>
                            <p><strong>Role:</strong> Penjual</p>
                        </div>
                    </div>
                </div>
                
                <!-- ============ RIGHT - FORM ============ -->
                <div class="col-lg-8">
                    <!-- Update Profil -->
                    <div class="form-card mb-4">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-pencil-square text-danger"></i> Edit Profil Toko
                        </h6>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nama Toko</label>
                                <input type="text" name="name" class="form-control" 
                                       value="<?= sanitize($user['name']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nomor HP</label>
                                <input type="text" name="phone" class="form-control" 
                                       value="<?= sanitize($user['phone'] ?? '') ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Alamat Toko</label>
                                <textarea name="address" class="form-control" rows="2"><?= sanitize($user['address'] ?? '') ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-save me-2"></i> Simpan Profil
                            </button>
                        </form>
                    </div>
                    
                    <!-- Ganti Password -->
                    <div class="form-card">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-key text-danger"></i> Ganti Password
                        </h6>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Password Saat Ini</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Password Baru</label>
                                <input type="password" name="new_password" class="form-control" required minlength="6">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Konfirmasi Password Baru</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>
                            
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="bi bi-key me-2"></i> Ganti Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - Profil Toko</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');

// =============================================
// LOGOUT
// =============================================
function handleLogout(event) {
    event.preventDefault();
    if (!confirm('Yakin ingin logout?')) return;
    
    fetch(BASE_URL + 'controllers/AuthController.php?action=logout')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.redirect;
        } else {
            alert('Gagal logout');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('Terjadi kesalahan');
    });
}
</script>

</body>
</html>