<?php
/**
 * users.php
 * Halaman kelola user untuk admin
 * 
 * @package FoodDelivery
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

// Cek login dan role
if (!isLoggedIn()) {
    redirect(BASE_URL . 'views/public/login.php');
}

if (getUserRole() !== 'admin') {
    redirect(BASE_URL . 'views/public/index.php');
}

$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);
    
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid CSRF token');
        redirect(BASE_URL . 'views/admin/users.php');
    }
    
    if ($userId <= 0) {
        setFlash('danger', 'User ID tidak valid');
        redirect(BASE_URL . 'views/admin/users.php');
    }
    
    // Ambil data user
    $stmt = $db->prepare("SELECT id, name, email, role, is_verified, is_active FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        setFlash('danger', 'User tidak ditemukan');
        redirect(BASE_URL . 'views/admin/users.php');
    }
    
    if ($action === 'verify') {
        $db->beginTransaction();
        try {
            // Update verifikasi
            $stmt = $db->prepare("UPDATE users SET is_verified = 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
            
            // Notifikasi ke user
            addNotification($userId, 'Akun Diverifikasi', 
                "Akun {$user['role']} Anda telah diverifikasi oleh admin. Anda sekarang dapat menggunakan platform.");
            
            $db->commit();
            setFlash('success', "User {$user['name']} berhasil diverifikasi!");
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('danger', 'Gagal verifikasi user');
        }
        redirect(BASE_URL . 'views/admin/users.php');
        
    } elseif ($action === 'reject') {
        $stmt = $db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
        
        addNotification($userId, 'Akun Ditolak', 
            "Akun {$user['role']} Anda ditolak oleh admin. Silakan hubungi support untuk informasi lebih lanjut.");
        
        setFlash('warning', "User {$user['name']} ditolak!");
        redirect(BASE_URL . 'views/admin/users.php');
        
    } elseif ($action === 'deactivate') {
        $stmt = $db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
        
        addNotification($userId, 'Akun Dinonaktifkan', 
            "Akun Anda telah dinonaktifkan oleh admin. Silakan hubungi support.");
        
        setFlash('warning', "User {$user['name']} dinonaktifkan!");
        redirect(BASE_URL . 'views/admin/users.php');
        
    } elseif ($action === 'activate') {
        $stmt = $db->prepare("UPDATE users SET is_active = 1, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
        
        addNotification($userId, 'Akun Diaktifkan', 
            "Akun Anda telah diaktifkan kembali oleh admin.");
        
        setFlash('success', "User {$user['name']} diaktifkan kembali!");
        redirect(BASE_URL . 'views/admin/users.php');
    }
}

// Get tab
$tab = isset($_GET['tab']) ? sanitize($_GET['tab']) : 'buyers';
$search = isset($_GET['q']) ? sanitize($_GET['q']) : '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query based on tab
$query = "SELECT id, name, email, phone, role, is_verified, is_active, created_at FROM users";
$where = "WHERE 1=1";
$params = [];

switch ($tab) {
    case 'buyers':
        $where .= " AND role = 'buyer'";
        break;
    case 'sellers_pending':
        $where .= " AND role = 'seller' AND is_verified = 0 AND is_active = 1";
        break;
    case 'sellers_active':
        $where .= " AND role = 'seller' AND is_verified = 1 AND is_active = 1";
        break;
    case 'drivers_pending':
        $where .= " AND role = 'driver' AND is_verified = 0 AND is_active = 1";
        break;
    case 'drivers_active':
        $where .= " AND role = 'driver' AND is_verified = 1 AND is_active = 1";
        break;
    default:
        $where .= " AND role = 'buyer'";
        $tab = 'buyers';
}

if (!empty($search)) {
    $where .= " AND (name LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Count total
$countStmt = $db->prepare("SELECT COUNT(*) as total FROM users $where");
$countStmt->execute($params);
$totalUsers = (int) $countStmt->fetch()['total'];
$totalPages = ceil($totalUsers / $limit);

// Get users
$stmt = $db->prepare("
    SELECT id, name, email, phone, role, is_verified, is_active, created_at 
    FROM users $where 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$limit, $offset]));
$users = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
$flash = getFlash();

// Tab config
$tabs = [
    'buyers' => ['label' => 'Pembeli', 'icon' => 'bi-person'],
    'sellers_pending' => ['label' => 'Seller (Pending)', 'icon' => 'bi-hourglass'],
    'sellers_active' => ['label' => 'Seller (Aktif)', 'icon' => 'bi-shop'],
    'drivers_pending' => ['label' => 'Driver (Pending)', 'icon' => 'bi-hourglass'],
    'drivers_active' => ['label' => 'Driver (Aktif)', 'icon' => 'bi-truck']
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola User - FoodDelivery</title>
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
            background: #f8f0ff;
            color: #6f42c1;
        }
        .sidebar .nav-link.active {
            background: #6f42c1;
            color: white;
        }
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        .tab-filter .nav-link {
            color: #6c757d;
            font-weight: 500;
            border-radius: 8px;
            padding: 8px 18px;
            font-size: 14px;
        }
        .tab-filter .nav-link.active {
            color: #6f42c1;
            background: #f8f0ff;
        }
        .tab-filter .nav-link:hover:not(.active) {
            background: #f8f9fa;
        }
        .status-badge {
            font-size: 11px;
            padding: 3px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        .btn-verify {
            padding: 2px 12px;
            font-size: 12px;
        }
        .pagination .page-link {
            color: #6f42c1;
        }
        .pagination .page-item.active .page-link {
            background-color: #6f42c1;
            border-color: #6f42c1;
            color: white;
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
                <i class="bi bi-shield-lock me-1"></i> Admin
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
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
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
                    <div class="fw-bold text-primary">Admin Panel</div>
                    <small class="text-muted">FoodDelivery</small>
                </div>
                <nav class="nav flex-column">
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/orders.php">
                        <i class="bi bi-bag"></i> Semua Pesanan
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/payments.php">
                        <i class="bi bi-credit-card"></i> Verifikasi Bayar
                    </a>
                    <a class="nav-link active" href="<?= BASE_URL ?>views/admin/users.php">
                        <i class="bi bi-people"></i> Kelola User
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/sellers.php">
                        <i class="bi bi-shop"></i> Kelola Seller
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/drivers.php">
                        <i class="bi bi-truck"></i> Kelola Driver
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/categories.php">
                        <i class="bi bi-tags"></i> Kategori
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/reviews.php">
                        <i class="bi bi-star"></i> Ulasan
                    </a>
                </nav>
            </div>
        </div>
        
        <!-- ============ CONTENT ============ -->
        <div class="col-lg-10 col-md-9 col-12 p-4">
            <h4 class="fw-bold mb-4">
                <i class="bi bi-people text-primary"></i> Kelola User
            </h4>
            
            <!-- ============ TABS ============ -->
            <ul class="nav nav-pills tab-filter mb-4 gap-1 flex-wrap">
                <?php foreach ($tabs as $key => $tabInfo): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" 
                       href="?tab=<?= $key ?><?= $search ? '&q=' . urlencode($search) : '' ?>">
                        <i class="<?= $tabInfo['icon'] ?> me-1"></i>
                        <?= $tabInfo['label'] ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            
            <!-- ============ SEARCH ============ -->
            <div class="bg-white rounded-3 border p-3 mb-4">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="tab" value="<?= $tab ?>">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold small">Cari User</label>
                        <div class="input-group">
                            <input type="text" name="q" class="form-control" 
                                   placeholder="Nama atau email..." value="<?= $search ?>">
                            <button class="btn btn-outline-secondary" type="submit">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <?php if (!empty($search)): ?>
                        <a href="?tab=<?= $tab ?>" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-eraser"></i> Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- ============ USER TABLE ============ -->
            <div class="bg-white rounded-3 border">
                <?php if (empty($users)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-people fs-1 text-muted d-block mb-3"></i>
                    <h6 class="fw-bold">Tidak ada user</h6>
                    <p class="text-muted small">Tidak ada data user untuk tab ini.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nama</th>
                                <th>Email</th>
                                <th>HP</th>
                                <th>Tanggal Daftar</th>
                                <th>Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): 
                                $isPending = $user['is_verified'] == 0;
                                $isActive = $user['is_active'] == 1;
                                
                                $statusLabel = $isPending ? 'Pending' : ($isActive ? 'Aktif' : 'Nonaktif');
                                $statusColor = $isPending ? 'warning' : ($isActive ? 'success' : 'secondary');
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= sanitize($user['name']) ?></td>
                                <td><?= sanitize($user['email']) ?></td>
                                <td><?= $user['phone'] ?? '-' ?></td>
                                <td class="small text-muted"><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                                <td>
                                    <span class="status-badge bg-<?= $statusColor ?> text-white">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <?php if ($isPending): ?>
                                        <!-- Verifikasi -->
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Verifikasi user <?= addslashes($user['name']) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="verify">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-verify">
                                                <i class="bi bi-check-circle"></i> Verifikasi
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Tolak user <?= addslashes($user['name']) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-verify">
                                                <i class="bi bi-x-circle"></i> Tolak
                                            </button>
                                        </form>
                                        <?php elseif ($isActive): ?>
                                        <!-- Nonaktifkan -->
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Nonaktifkan user <?= addslashes($user['name']) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-verify">
                                                <i class="bi bi-eye-slash"></i> Nonaktifkan
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <!-- Aktifkan kembali -->
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Aktifkan kembali user <?= addslashes($user['name']) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" class="btn btn-outline-success btn-verify">
                                                <i class="bi bi-eye"></i> Aktifkan
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ============ PAGINATION ============ -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?tab=<?= $tab ?>&page=<?= $page - 1 ?><?= $search ? '&q=' . urlencode($search) : '' ?>">
                            Sebelumnya
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?tab=<?= $tab ?>&page=<?= $i ?><?= $search ? '&q=' . urlencode($search) : '' ?>">
                            <?= $i ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?tab=<?= $tab ?>&page=<?= $page + 1 ?><?= $search ? '&q=' . urlencode($search) : '' ?>">
                            Selanjutnya
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
            
            <div class="text-muted small mt-2">
                Total <?= $totalUsers ?> user
            </div>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - Kelola User</small>
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