<?php
/**
 * profile.php
 * Halaman profil pembeli
 * 
 * @package FoodDelivery
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

// Cek login dan role
if (!isLoggedIn()) {
    redirect(BASE_URL . 'views/public/login.php');
}

if (getUserRole() !== 'buyer') {
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
    redirect(BASE_URL . 'views/public/index.php');
}

// Proses update profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid CSRF token');
        redirect(BASE_URL . 'views/buyer/profile.php');
    }
    
    if ($action === 'update_profile') {
        $name = sanitize($_POST['name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        
        if (empty($name)) {
            setFlash('danger', 'Nama wajib diisi');
        } else {
            $stmt = $db->prepare("
                UPDATE users 
                SET name = ?, phone = ?, address = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$name, $phone, $address, $userId]);
            setFlash('success', 'Profil berhasil diupdate!');
            
            // Update session
            $_SESSION['user']['name'] = $name;
            
            redirect(BASE_URL . 'views/buyer/profile.php');
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
        redirect(BASE_URL . 'views/buyer/profile.php');
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
    <title>Profil Saya - FoodDelivery</title>
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    
    <style>
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
        .nav-tabs .nav-link {
            color: #6c757d;
            font-weight: 500;
        }
        .nav-tabs .nav-link.active {
            color: #dc3545;
            border-bottom-color: #dc3545;
        }
        .nav-tabs .nav-link:hover {
            color: #dc3545;
        }
        @media (max-width: 768px) {
            .profile-avatar {
                width: 80px;
                height: 80px;
                font-size: 32px;
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

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-2">
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>views/public/menu.php">
                        <i class="bi bi-grid"></i> Menu
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link position-relative" href="<?= BASE_URL ?>views/buyer/cart.php">
                        <i class="bi bi-cart3 fs-5"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger cart-badge">0</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>views/buyer/orders.php">
                        <i class="bi bi-bag"></i> Pesanan
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= sanitize($user['name'] ?? 'User') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>views/buyer/profile.php"><i class="bi bi-person me-2"></i>Profil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="handleLogout(event)"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </ul>
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
<div class="container py-4">
    <div class="row g-4">
        
        <!-- ============ LEFT - AVATAR & INFO ============ -->
        <div class="col-lg-4">
            <div class="form-card text-center">
                <div class="profile-avatar">
                    <i class="bi bi-person"></i>
                </div>
                <h5 class="fw-bold"><?= sanitize($user['name']) ?></h5>
                <p class="text-muted small"><?= sanitize($user['email']) ?></p>
                <p class="text-muted small">
                    <span class="badge bg-success">Aktif</span>
                    <span class="badge bg-info">Terverifikasi</span>
                </p>
                <hr>
                <div class="text-start small">
                    <p><strong>Role:</strong> Pembeli</p>
                    <p><strong>Terdaftar:</strong> <?= date('d/m/Y', strtotime($user['created_at'])) ?></p>
                    <p><strong>HP:</strong> <?= $user['phone'] ?? '-' ?></p>
                    <p><strong>Alamat:</strong> <?= $user['address'] ?? '-' ?></p>
                </div>
            </div>
        </div>
        
        <!-- ============ RIGHT - FORM ============ -->
        <div class="col-lg-8">
            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3" id="profileTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="edit-tab" data-bs-toggle="tab" data-bs-target="#edit" type="button" role="tab">
                        <i class="bi bi-pencil me-1"></i> Edit Profil
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">
                        <i class="bi bi-key me-1"></i> Ganti Password
                    </button>
                </li>
            </ul>
            
            <div class="tab-content">
                <!-- Edit Profil -->
                <div class="tab-pane fade show active" id="edit" role="tabpanel">
                    <div class="form-card">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-pencil-square text-danger"></i> Edit Profil
                        </h6>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nama Lengkap</label>
                                <input type="text" name="name" class="form-control" 
                                       value="<?= sanitize($user['name']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" class="form-control" 
                                       value="<?= sanitize($user['email']) ?>" disabled>
                                <small class="text-muted">Email tidak dapat diubah</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nomor HP</label>
                                <input type="text" name="phone" class="form-control" 
                                       value="<?= sanitize($user['phone'] ?? '') ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Alamat</label>
                                <textarea name="address" class="form-control" rows="3"><?= sanitize($user['address'] ?? '') ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-save me-2"></i> Simpan Profil
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Ganti Password -->
                <div class="tab-pane fade" id="password" role="tabpanel">
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
<footer class="bg-dark text-white text-center py-4 mt-5">
    <div class="container">
        <div class="fw-bold mb-1"><span class="text-danger">Food</span>Delivery &copy; 2025</div>
        <small class="text-secondary">Tugas Besar Pemrograman Web - Universitas Bhayangkara Jakarta Raya</small>
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

// =============================================
// UPDATE CART BADGE
// =============================================
function updateCartBadge() {
    fetch(BASE_URL + 'controllers/CartController.php?action=get_count')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('.cart-badge').forEach(el => {
                el.textContent = data.cart_count;
                el.style.display = (data.cart_count > 0) ? '' : 'none';
            });
        }
    })
    .catch(err => console.error('Cart badge error:', err));
}

document.addEventListener('DOMContentLoaded', function() {
    updateCartBadge();
});
</script>

</body>
</html>