<?php
/**
 * dashboard.php
 * Halaman dashboard seller
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
$user = getUser();
$db = getDB();

// ============ STATS ============

// Total Menu Aktif
$stmt = $db->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ? AND is_active = 1");
$stmt->execute([$userId]);
$totalMenu = (int) $stmt->fetch()['total'];

// Pesanan Hari Ini
$stmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM orders 
    WHERE seller_id = ? AND DATE(created_at) = CURDATE()
");
$stmt->execute([$userId]);
$ordersToday = (int) $stmt->fetch()['total'];

// Pesanan Perlu Diproses (confirmed)
$stmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM orders 
    WHERE seller_id = ? AND status = 'confirmed'
");
$stmt->execute([$userId]);
$ordersToProcess = (int) $stmt->fetch()['total'];

// Total Pendapatan Bulan Ini
$stmt = $db->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as total 
    FROM orders 
    WHERE seller_id = ? 
    AND status = 'delivered' 
    AND MONTH(created_at) = MONTH(CURDATE()) 
    AND YEAR(created_at) = YEAR(CURDATE())
");
$stmt->execute([$userId]);
$monthlyRevenue = (float) $stmt->fetch()['total'];

// ============ ALERT: Pesanan Perlu Dimasak ============
$stmt = $db->prepare("
    SELECT o.id, o.order_code, u.name as buyer_name, o.created_at
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    WHERE o.seller_id = ? AND o.status = 'confirmed'
    ORDER BY o.created_at ASC
    LIMIT 5
");
$stmt->execute([$userId]);
$pendingOrders = $stmt->fetchAll();

// ============ Pesanan Terbaru ============
$stmt = $db->prepare("
    SELECT 
        o.id, o.order_code, o.total_amount, o.status, o.created_at,
        u.name as buyer_name,
        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    WHERE o.seller_id = ?
    ORDER BY o.created_at DESC
    LIMIT 6
");
$stmt->execute([$userId]);
$recentOrders = $stmt->fetchAll();

// ============ Stok Menipis ============
$stmt = $db->prepare("
    SELECT id, name, price, stock, image, is_active
    FROM products
    WHERE seller_id = ? AND stock <= 5 AND is_active = 1
    ORDER BY stock ASC
    LIMIT 10
");
$stmt->execute([$userId]);
$lowStockProducts = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
$flash = getFlash();

// Status config
$statusConfig = [
    'pending' => ['label' => 'Menunggu', 'color' => 'warning'],
    'confirmed' => ['label' => 'Dikonfirmasi', 'color' => 'info'],
    'cooking' => ['label' => 'Dimasak', 'color' => 'primary'],
    'on_delivery' => ['label' => 'Diantar', 'color' => 'success'],
    'delivered' => ['label' => 'Selesai', 'color' => 'success'],
    'cancelled' => ['label' => 'Batal', 'color' => 'danger']
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - FoodDelivery</title>
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
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        .stat-card:hover {
            border-color: #dc3545;
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
        .alert-order {
            background: #fff5f5;
            border-left: 4px solid #dc3545;
            border-radius: 8px;
            padding: 12px 16px;
        }
        .product-thumb {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 8px;
        }
        .status-badge {
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        .stock-badge {
            font-size: 11px;
            padding: 2px 10px;
            border-radius: 20px;
            font-weight: 600;
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
                    <div class="fw-bold text-danger"><?= sanitize($user['name'] ?? 'Restoran') ?></div>
                    <small class="text-muted">Penjual</small>
                </div>
                <nav class="nav flex-column">
                    <a class="nav-link active" href="<?= BASE_URL ?>views/seller/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/seller/menu.php">
                        <i class="bi bi-grid"></i> Kelola Menu
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/seller/orders.php">
                        <i class="bi bi-bag"></i> Pesanan Masuk
                        <?php if ($ordersToProcess > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-2"><?= $ordersToProcess ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/seller/profile.php">
                        <i class="bi bi-shop"></i> Profil Toko
                    </a>
                </nav>
            </div>
        </div>
        
        <!-- ============ CONTENT ============ -->
        <div class="col-lg-10 col-md-9 col-12 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="fw-bold">
                    <i class="bi bi-speedometer2 text-danger"></i> Dashboard
                </h4>
                <span class="text-muted small">
                    <i class="bi bi-calendar me-1"></i> <?= date('d F Y') ?>
                </span>
            </div>
            
            <!-- ============ STATS CARDS ============ -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="number text-primary"><?= $totalMenu ?></div>
                                <div class="label">Total Menu Aktif</div>
                            </div>
                            <div class="icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-grid"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="number text-warning"><?= $ordersToday ?></div>
                                <div class="label">Pesanan Hari Ini</div>
                            </div>
                            <div class="icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-calendar"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="number text-danger"><?= $ordersToProcess ?></div>
                                <div class="label">Perlu Diproses</div>
                            </div>
                            <div class="icon bg-danger bg-opacity-10 text-danger">
                                <i class="bi bi-clock"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="number text-success"><?= formatRupiah($monthlyRevenue) ?></div>
                                <div class="label">Pendapatan Bulan Ini</div>
                            </div>
                            <div class="icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-coin"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============ ALERT: Pesanan Perlu Dimasak ============ -->
            <?php if (!empty($pendingOrders)): ?>
            <div class="alert alert-danger border-0 alert-order mb-4">
                <div class="d-flex align-items-start gap-3">
                    <i class="bi bi-exclamation-triangle-fill fs-4 text-danger"></i>
                    <div class="flex-grow-1">
                        <strong class="text-danger">⚠️ Ada <?= count($pendingOrders) ?> pesanan yang perlu segera dimasak!</strong>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php foreach ($pendingOrders as $order): ?>
                            <a href="<?= BASE_URL ?>views/seller/orders.php?status=confirmed" 
                               class="badge bg-danger text-decoration-none p-2">
                                #<?= $order['order_code'] ?> - <?= sanitize($order['buyer_name']) ?>
                                <small class="text-white-50"><?= date('H:i', strtotime($order['created_at'])) ?></small>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a href="<?= BASE_URL ?>views/seller/orders.php?status=confirmed" class="btn btn-danger btn-sm">
                        Lihat Semua
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="row g-4">
                <!-- ============ PESANAN TERBARU ============ -->
                <div class="col-lg-7">
                    <div class="bg-white rounded-3 border p-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0">
                                <i class="bi bi-clock-history text-danger"></i> Pesanan Terbaru
                            </h6>
                            <a href="<?= BASE_URL ?>views/seller/orders.php" class="btn btn-link btn-sm text-danger">
                                Lihat Semua <i class="bi bi-chevron-right"></i>
                            </a>
                        </div>
                        
                        <?php if (empty($recentOrders)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            <small>Belum ada pesanan</small>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kode</th>
                                        <th>Pembeli</th>
                                        <th class="text-center">Item</th>
                                        <th class="text-end">Total</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): 
                                        $status = $statusConfig[$order['status']] ?? ['label' => $order['status'], 'color' => 'secondary'];
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="<?= BASE_URL ?>views/seller/order-detail.php?id=<?= $order['id'] ?>" 
                                               class="text-decoration-none fw-bold text-danger">
                                                #<?= $order['order_code'] ?>
                                            </a>
                                            <br><small class="text-muted"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></small>
                                        </td>
                                        <td><?= sanitize($order['buyer_name']) ?></td>
                                        <td class="text-center"><?= $order['item_count'] ?></td>
                                        <td class="text-end fw-bold"><?= formatRupiah($order['total_amount']) ?></td>
                                        <td>
                                            <span class="status-badge bg-<?= $status['color'] ?> text-white">
                                                <?= $status['label'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- ============ STOK MENIPIS ============ -->
                <div class="col-lg-5">
                    <div class="bg-white rounded-3 border p-3">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-exclamation-triangle text-warning"></i> Stok Menipis
                            <?php if (!empty($lowStockProducts)): ?>
                            <span class="badge bg-warning text-dark ms-2"><?= count($lowStockProducts) ?></span>
                            <?php endif; ?>
                        </h6>
                        
                        <?php if (empty($lowStockProducts)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-check-circle fs-2 text-success d-block mb-2"></i>
                            <small>Semua stok aman</small>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Menu</th>
                                        <th class="text-end">Stok</th>
                                        <th class="text-end">Harga</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lowStockProducts as $product): 
                                        $stockClass = $product['stock'] <= 0 ? 'bg-danger' : ($product['stock'] <= 3 ? 'bg-warning' : 'bg-info');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <img src="<?= $product['image'] ? UPLOAD_URL . $product['image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                                                     class="product-thumb"
                                                     onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                                                <span class="small fw-semibold"><?= sanitize($product['name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <span class="stock-badge <?= $stockClass ?> text-white">
                                                <?= $product['stock'] ?>
                                            </span>
                                        </td>
                                        <td class="text-end small"><?= formatRupiah($product['price']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-2 text-center">
                            <a href="<?= BASE_URL ?>views/seller/menu.php" class="btn btn-sm btn-outline-danger">
                                Kelola Menu
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - Dashboard Penjual</small>
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
// AUTO REFRESH (untuk pesanan baru)
// =============================================
setTimeout(function() {
    location.reload();
}, 60000); // Refresh setiap 60 detik
</script>

</body>
</html>