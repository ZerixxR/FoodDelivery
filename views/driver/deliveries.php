<?php
/**
 * deliveries.php
 * Halaman daftar pengiriman aktif untuk driver
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

// Ambil semua pengiriman driver (aktif dan selesai)
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
        s.address as seller_address
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    JOIN users s ON o.seller_id = s.id
    WHERE o.driver_id = ?
    ORDER BY 
        CASE o.status 
            WHEN 'on_delivery' THEN 0
            WHEN 'cooking' THEN 1
            WHEN 'confirmed' THEN 2
            WHEN 'pending' THEN 3
            WHEN 'delivered' THEN 4
            WHEN 'cancelled' THEN 5
        END,
        o.created_at DESC
");
$stmt->execute([$userId]);
$deliveries = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
$flash = getFlash();

// Status config
$statusConfig = [
    'pending' => ['label' => 'Menunggu', 'color' => 'warning', 'icon' => 'bi-clock'],
    'confirmed' => ['label' => 'Dikonfirmasi', 'color' => 'info', 'icon' => 'bi-check-circle'],
    'cooking' => ['label' => 'Dimasak', 'color' => 'primary', 'icon' => 'bi-fire'],
    'on_delivery' => ['label' => 'Sedang Diantar', 'color' => 'success', 'icon' => 'bi-truck'],
    'delivered' => ['label' => 'Selesai', 'color' => 'success', 'icon' => 'bi-check-circle-fill'],
    'cancelled' => ['label' => 'Dibatalkan', 'color' => 'danger', 'icon' => 'bi-x-circle']
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengiriman Aktif - FoodDelivery</title>
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
        .delivery-card .status-badge {
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
        .tab-filter .nav-link {
            color: #6c757d;
            font-weight: 500;
            border-radius: 8px;
            padding: 6px 16px;
            font-size: 13px;
        }
        .tab-filter .nav-link.active {
            color: #0d6efd;
            background: #f0f7ff;
        }
        .tab-filter .nav-link:hover:not(.active) {
            background: #f8f9fa;
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
                    <a class="nav-link" href="<?= BASE_URL ?>views/driver/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link active" href="<?= BASE_URL ?>views/driver/deliveries.php">
                        <i class="bi bi-truck"></i> Pengiriman Aktif
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/driver/history.php">
                        <i class="bi bi-clock-history"></i> Riwayat
                    </a>
                </nav>
            </div>
        </div>
        
        <!-- ============ CONTENT ============ -->
        <div class="col-lg-10 col-md-9 col-12 p-4">
            <h4 class="fw-bold mb-4">
                <i class="bi bi-truck text-primary"></i> Semua Pengiriman
            </h4>
            
            <?php if (empty($deliveries)): ?>
            <div class="text-center py-5 bg-white rounded-3 border">
                <div style="font-size: 64px; color: #dee2e6;">
                    <i class="bi bi-inbox"></i>
                </div>
                <h5 class="fw-bold mt-3">Belum ada pengiriman</h5>
                <p class="text-muted">Anda belum menerima tugas pengiriman.</p>
            </div>
            <?php else: ?>
            <div class="row g-3">
                <?php foreach ($deliveries as $delivery): 
                    $status = $statusConfig[$delivery['status']] ?? ['label' => $delivery['status'], 'color' => 'secondary', 'icon' => 'bi-circle'];
                    $isActive = in_array($delivery['status'], ['on_delivery', 'cooking', 'confirmed']);
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="delivery-card p-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <span class="fw-bold text-primary">#<?= $delivery['order_code'] ?></span>
                                <br><small class="text-muted"><?= date('d/m/Y H:i', strtotime($delivery['created_at'])) ?></small>
                            </div>
                            <span class="status-badge bg-<?= $status['color'] ?> text-white">
                                <?php if ($isActive): ?>
                                <span class="status-indicator active me-1"></span>
                                <?php endif; ?>
                                <i class="<?= $status['icon'] ?> me-1"></i>
                                <?= $status['label'] ?>
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
                                <?php if ($delivery['status'] === 'on_delivery'): ?>
                                <a href="<?= BASE_URL ?>views/driver/delivery.php?id=<?= $delivery['id'] ?>" 
                                   class="btn btn-primary btn-sm">
                                    <i class="bi bi-eye me-1"></i> Detail
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - Pengiriman</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');

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