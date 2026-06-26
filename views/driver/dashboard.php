<?php
/**
 * dashboard.php
 * Halaman dashboard driver
 * 
 * @package FoodDelivery
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

// Cek login dan role
if (!isLoggedIn()) {
    redirect(BASE_URL . 'views/public/login.php');
}

if (getUserRole() !== 'driver') {
    redirect(BASE_URL . 'views/public/index.php');
}

$userId = (int) $_SESSION['user_id'];
$user = getUser();
$db = getDB();

// ============ STATS ============

// Pengiriman Hari Ini
$stmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM orders 
    WHERE driver_id = ? AND DATE(created_at) = CURDATE()
");
$stmt->execute([$userId]);
$deliveriesToday = (int) $stmt->fetch()['total'];

// Pengiriman Selesai Bulan Ini
$stmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM orders 
    WHERE driver_id = ? 
    AND status = 'delivered'
    AND MONTH(created_at) = MONTH(CURDATE()) 
    AND YEAR(created_at) = YEAR(CURDATE())
");
$stmt->execute([$userId]);
$deliveriesCompleted = (int) $stmt->fetch()['total'];

// Rating Driver (simulasi)
$rating = 4.8; // Default, bisa diambil dari tabel ratings nanti

// ============ PENGIRIMAN AKTIF ============
$stmt = $db->prepare("
    SELECT 
        o.id,
        o.order_code,
        o.status,
        o.created_at,
        o.shipping_address,
        o.total_amount,
        u.name as buyer_name,
        u.phone as buyer_phone,
        s.name as seller_name,
        s.phone as seller_phone,
        s.address as seller_address
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    JOIN users s ON o.seller_id = s.id
    WHERE o.driver_id = ? AND o.status = 'on_delivery'
    ORDER BY o.created_at ASC
");
$stmt->execute([$userId]);
$activeDeliveries = $stmt->fetchAll();

// ============ RIWAYAT PENGIRIMAN ============
$stmt = $db->prepare("
    SELECT 
        o.id,
        o.order_code,
        o.status,
        o.created_at,
        o.total_amount,
        u.name as buyer_name
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    WHERE o.driver_id = ? 
    AND o.status IN ('delivered', 'cancelled')
    ORDER BY o.created_at DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$deliveryHistory = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Driver - FoodDelivery</title>
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
            background: #f0f7ff;
            color: #0d6efd;
        }
        .sidebar .nav-link.active {
            background: #0d6efd;
            color: white;
        }
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        .stat-card:hover {
            border-color: #0d6efd;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .stat-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-card .number {
            font-size: 28px;
            font-weight: 700;
        }
        .stat-card .label {
            color: #6c757d;
            font-size: 14px;
        }
        .delivery-card {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            transition: all 0.2s;
            background: white;
        }
        .delivery-card:hover {
            border-color: #0d6efd;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
        }
        .delivery-card .badge-status {
            font-size: 12px;
            padding: 4px 14px;
            border-radius: 20px;
            font-weight: 600;
        }
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            animation: pulse-dot 1.5s infinite;
        }
        .status-indicator.active {
            background: #28a745;
        }
        @keyframes pulse-dot {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
            100% { opacity: 1; transform: scale(1); }
        }
        .history-item {
            padding: 10px 0;
            border-bottom: 1px solid #f1f3f5;
        }
        .history-item:last-child {
            border-bottom: none;
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
                <i class="bi bi-truck me-1"></i> <?= sanitize($user['name'] ?? 'Driver') ?>
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
                    <div class="fw-bold text-primary"><?= sanitize($user['name'] ?? 'Driver') ?></div>
                    <small class="text-muted">Driver</small>
                </div>
                <nav class="nav flex-column">
                    <a class="nav-link active" href="<?= BASE_URL ?>views/driver/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/driver/deliveries.php">
                        <i class="bi bi-truck"></i> Pengiriman Aktif
                        <?php if (count($activeDeliveries) > 0): ?>
                        <span class="badge bg-primary rounded-pill ms-2"><?= count($activeDeliveries) ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/driver/history.php">
                        <i class="bi bi-clock-history"></i> Riwayat
                    </a>
                </nav>
            </div>
        </div>
        
        <!-- ============ CONTENT ============ -->
        <div class="col-lg-10 col-md-9 col-12 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="fw-bold">
                    <i class="bi bi-speedometer2 text-primary"></i> Dashboard Driver
                </h4>
                <span class="text-muted small">
                    <i class="bi bi-calendar me-1"></i> <?= date('d F Y') ?>
                </span>
            </div>
            
            <!-- ============ STATS CARDS ============ -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-4">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="number text-primary"><?= $deliveriesToday ?></div>
                                <div class="label">Pengiriman Hari Ini</div>
                            </div>
                            <div class="icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="number text-success"><?= $deliveriesCompleted ?></div>
                                <div class="label">Selesai Bulan Ini</div>
                            </div>
                            <div class="icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="number text-warning"><?= number_format($rating, 1) ?></div>
                                <div class="label">Rating Driver</div>
                            </div>
                            <div class="icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-star-fill"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============ PENGIRIMAN AKTIF ============ -->
            <div class="bg-white rounded-3 border p-3 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">
                        <i class="bi bi-truck text-primary"></i> Pengiriman Aktif
                        <?php if (!empty($activeDeliveries)): ?>
                        <span class="badge bg-primary ms-2"><?= count($activeDeliveries) ?></span>
                        <?php endif; ?>
                    </h6>
                    <span class="text-muted small" id="lastUpdate">
                        <i class="bi bi-arrow-repeat me-1"></i> Live
                    </span>
                </div>
                
                <?php if (empty($activeDeliveries)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-check-circle fs-2 text-success d-block mb-2"></i>
                    <span>Tidak ada pengiriman aktif saat ini</span>
                    <br><small>Santai dulu, nanti ada pesanan masuk</small>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($activeDeliveries as $delivery): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="delivery-card p-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="fw-bold text-primary">#<?= $delivery['order_code'] ?></span>
                                </div>
                                <span class="badge-status bg-success text-white">
                                    <span class="status-indicator active me-1"></span>
                                    Sedang Diantar
                                </span>
                            </div>
                            
                            <div class="small">
                                <div class="mb-1">
                                    <i class="bi bi-person text-muted me-1"></i>
                                    <strong><?= sanitize($delivery['buyer_name']) ?></strong>
                                </div>
                                <div class="mb-1">
                                    <i class="bi bi-geo-alt text-muted me-1"></i>
                                    <?= sanitize($delivery['shipping_address']) ?>
                                </div>
                                <div class="mb-2">
                                    <i class="bi bi-shop text-muted me-1"></i>
                                    <?= sanitize($delivery['seller_name']) ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-primary"><?= formatRupiah($delivery['total_amount']) ?></span>
                                    <a href="<?= BASE_URL ?>views/driver/delivery.php?id=<?= $delivery['id'] ?>" 
                                       class="btn btn-primary btn-sm">
                                        <i class="bi bi-eye me-1"></i> Detail
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ============ RIWAYAT PENGIRIMAN ============ -->
            <div class="bg-white rounded-3 border p-3">
                <h6 class="fw-bold mb-3">
                    <i class="bi bi-clock-history text-muted"></i> Riwayat Pengiriman
                </h6>
                
                <?php if (empty($deliveryHistory)): ?>
                <div class="text-center py-3 text-muted">
                    <small>Belum ada riwayat pengiriman</small>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Kode</th>
                                <th>Pembeli</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveryHistory as $history): 
                                $statusLabel = $history['status'] === 'delivered' ? 'Selesai' : 'Dibatalkan';
                                $statusColor = $history['status'] === 'delivered' ? 'success' : 'secondary';
                            ?>
                            <tr>
                                <td><span class="fw-bold">#<?= $history['order_code'] ?></span></td>
                                <td><?= sanitize($history['buyer_name']) ?></td>
                                <td><?= formatRupiah($history['total_amount']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $statusColor ?>">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                                <td class="text-muted small"><?= date('d/m/Y H:i', strtotime($history['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - Dashboard Driver</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');

// =============================================
// AUTO REFRESH (untuk pengiriman baru)
// =============================================
setTimeout(function() {
    location.reload();
}, 30000); // Refresh setiap 30 detik

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