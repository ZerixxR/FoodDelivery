<?php
/**
 * dashboard.php
 * Halaman dashboard admin
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

// ============ STATS ============

// Total Pembeli
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role = 'buyer' AND is_active = 1");
$totalBuyers = (int) $stmt->fetch()['total'];

// Total Seller Aktif
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role = 'seller' AND is_active = 1 AND is_verified = 1");
$totalSellers = (int) $stmt->fetch()['total'];

// Total Driver Aktif
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role = 'driver' AND is_active = 1 AND is_verified = 1");
$totalDrivers = (int) $stmt->fetch()['total'];

// Pesanan Hari Ini
$stmt = $db->query("SELECT COUNT(*) as total FROM orders WHERE DATE(created_at) = CURDATE()");
$ordersToday = (int) $stmt->fetch()['total'];

// Menunggu Verifikasi Bayar
$stmt = $db->query("SELECT COUNT(*) as total FROM payments WHERE status = 'uploaded'");
$pendingPayments = (int) $stmt->fetch()['total'];

// Total Revenue Bulan Ini
$stmt = $db->query("
    SELECT COALESCE(SUM(total_amount), 0) as total 
    FROM orders 
    WHERE status = 'delivered' 
    AND MONTH(created_at) = MONTH(CURDATE()) 
    AND YEAR(created_at) = YEAR(CURDATE())
");
$monthlyRevenue = (float) $stmt->fetch()['total'];

// ============ PENDING VERIFICATIONS ============
$stmt = $db->query("
    SELECT id, name, email, role, created_at 
    FROM users 
    WHERE is_verified = 0 AND is_active = 1 AND role IN ('seller', 'driver')
    ORDER BY created_at ASC
    LIMIT 10
");
$pendingUsers = $stmt->fetchAll();

// ============ PENDING PAYMENTS ============
$stmt = $db->query("
    SELECT p.id as payment_id, p.created_at, p.amount, 
           o.order_code, o.id as order_id,
           u.name as buyer_name
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    JOIN users u ON o.buyer_id = u.id
    WHERE p.status = 'uploaded'
    ORDER BY p.created_at ASC
    LIMIT 10
");
$pendingPaymentList = $stmt->fetchAll();

// ============ RECENT ORDERS ============
$stmt = $db->query("
    SELECT 
        o.id, o.order_code, o.total_amount, o.status, o.created_at,
        u.name as buyer_name
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    ORDER BY o.created_at DESC
    LIMIT 8
");
$recentOrders = $stmt->fetchAll();

// ============ REVENUE 7 HARI ============
$stmt = $db->query("
    SELECT 
        DATE(created_at) as date,
        COALESCE(SUM(total_amount), 0) as total
    FROM orders 
    WHERE status = 'delivered' 
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$revenueData = $stmt->fetchAll();

// Isi hari yang kosong
$revenueMap = [];
foreach ($revenueData as $row) {
    $revenueMap[$row['date']] = (float) $row['total'];
}

$dates = [];
$values = [];
$maxRevenue = 0;
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dates[] = date('d/m', strtotime($date));
    $val = $revenueMap[$date] ?? 0;
    $values[] = $val;
    if ($val > $maxRevenue) $maxRevenue = $val;
}
$maxRevenue = max($maxRevenue, 1); // Hindari division by zero

// ============ TOP 5 MENU ============
$stmt = $db->query("
    SELECT 
        p.id,
        p.name,
        u.name as seller_name,
        COALESCE(SUM(oi.quantity), 0) as total_sold
    FROM products p
    JOIN users u ON p.seller_id = u.id
    LEFT JOIN order_items oi ON p.id = oi.product_id
    LEFT JOIN orders o ON oi.order_id = o.id AND o.status = 'delivered'
    WHERE p.is_active = 1
    GROUP BY p.id
    ORDER BY total_sold DESC
    LIMIT 5
");
$topProducts = $stmt->fetchAll();

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
    <title>Dashboard Admin - FoodDelivery</title>
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
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        .stat-card:hover {
            border-color: #6f42c1;
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
        .bar-chart {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            height: 120px;
            padding: 10px 0;
        }
        .bar-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }
        .bar {
            width: 100%;
            max-width: 40px;
            border-radius: 4px 4px 0 0;
            background: #6f42c1;
            transition: height 0.5s ease;
            min-height: 4px;
        }
        .bar-label {
            font-size: 10px;
            color: #6c757d;
        }
        .alert-warning-custom {
            background: #fff8e1;
            border-left: 4px solid #ffc107;
            border-radius: 8px;
        }
        .alert-danger-custom {
            background: #fce4ec;
            border-left: 4px solid #dc3545;
            border-radius: 8px;
        }
        .status-badge {
            font-size: 11px;
            padding: 3px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid #e9ecef;
            }
            .bar-chart {
                height: 80px;
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
                    <a class="nav-link active" href="<?= BASE_URL ?>views/admin/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/orders.php">
                        <i class="bi bi-bag"></i> Semua Pesanan
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/payments.php">
                        <i class="bi bi-credit-card"></i> Verifikasi Bayar
                        <?php if ($pendingPayments > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-2"><?= $pendingPayments ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/users.php">
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="fw-bold">
                    <i class="bi bi-speedometer2 text-primary"></i> Dashboard Admin
                </h4>
                <span class="text-muted small">
                    <i class="bi bi-calendar me-1"></i> <?= date('d F Y') ?>
                </span>
            </div>
            
            <!-- ============ STATS CARDS ============ -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="number text-primary"><?= $totalBuyers ?></div>
                            <div class="label">Pembeli</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="number text-success"><?= $totalSellers ?></div>
                            <div class="label">Seller Aktif</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="number text-info"><?= $totalDrivers ?></div>
                            <div class="label">Driver Aktif</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="number text-warning"><?= $ordersToday ?></div>
                            <div class="label">Pesanan Hari Ini</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="number text-danger"><?= $pendingPayments ?></div>
                            <div class="label">Verifikasi Bayar</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="number text-success"><?= formatRupiah($monthlyRevenue) ?></div>
                            <div class="label">Revenue Bulan Ini</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============ ALERTS ============ -->
            <?php if (!empty($pendingUsers)): ?>
            <div class="alert alert-warning-custom p-3 mb-3">
                <div class="d-flex align-items-start gap-3">
                    <i class="bi bi-person-plus fs-4 text-warning"></i>
                    <div class="flex-grow-1">
                        <strong class="text-warning">⚠️ Ada <?= count($pendingUsers) ?> user menunggu verifikasi!</strong>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php foreach ($pendingUsers as $user): ?>
                            <a href="<?= BASE_URL ?>views/admin/users.php?tab=<?= $user['role'] ?>_pending" 
                               class="badge bg-warning text-dark text-decoration-none p-2">
                                <?= sanitize($user['name']) ?> (<?= $user['role'] ?>)
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a href="<?= BASE_URL ?>views/admin/users.php" class="btn btn-warning btn-sm">
                        Verifikasi
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($pendingPaymentList)): ?>
            <div class="alert alert-danger-custom p-3 mb-3">
                <div class="d-flex align-items-start gap-3">
                    <i class="bi bi-exclamation-triangle fs-4 text-danger"></i>
                    <div class="flex-grow-1">
                        <strong class="text-danger">⚠️ Ada <?= count($pendingPaymentList) ?> pembayaran menunggu verifikasi!</strong>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php foreach ($pendingPaymentList as $pay): ?>
                            <a href="<?= BASE_URL ?>views/admin/payments.php" 
                               class="badge bg-danger text-white text-decoration-none p-2">
                                #<?= $pay['order_code'] ?> - <?= sanitize($pay['buyer_name']) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a href="<?= BASE_URL ?>views/admin/payments.php" class="btn btn-danger btn-sm">
                        Verifikasi
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="row g-4">
                <!-- ============ REVENUE CHART ============ -->
                <div class="col-lg-6">
                    <div class="bg-white rounded-3 border p-3">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-graph-up text-primary"></i> Revenue 7 Hari Terakhir
                        </h6>
                        <div class="bar-chart">
                            <?php foreach ($values as $index => $value): 
                                $height = ($value / $maxRevenue) * 100;
                                $height = max($height, 4);
                            ?>
                            <div class="bar-item">
                                <div class="bar" style="height: <?= $height ?>%; background: <?= $value > 0 ? '#6f42c1' : '#e9ecef' ?>;"></div>
                                <span class="bar-label"><?= $dates[$index] ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center text-muted small">
                            Tertinggi: <?= formatRupiah($maxRevenue) ?>
                        </div>
                    </div>
                </div>
                
                <!-- ============ TOP 5 MENU ============ -->
                <div class="col-lg-6">
                    <div class="bg-white rounded-3 border p-3">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-trophy text-warning"></i> Top 5 Menu Terlaris
                        </h6>
                        <?php if (empty($topProducts)): ?>
                        <div class="text-center py-3 text-muted">
                            <small>Belum ada data penjualan</small>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Menu</th>
                                        <th>Restoran</th>
                                        <th class="text-end">Terjual</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $rank = 1; foreach ($topProducts as $product): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-<?= $rank <= 1 ? 'warning' : ($rank <= 3 ? 'info' : 'secondary') ?>">
                                                <?= $rank ?>
                                            </span>
                                        </td>
                                        <td><?= sanitize($product['name']) ?></td>
                                        <td><?= sanitize($product['seller_name']) ?></td>
                                        <td class="text-end fw-bold"><?= $product['total_sold'] ?>x</td>
                                    </tr>
                                    <?php $rank++; endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- ============ RECENT ORDERS ============ -->
            <div class="bg-white rounded-3 border p-3 mt-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">
                        <i class="bi bi-clock-history text-primary"></i> Pesanan Terbaru
                    </h6>
                    <a href="<?= BASE_URL ?>views/admin/orders.php" class="btn btn-link btn-sm text-primary">
                        Lihat Semua <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
                
                <?php if (empty($recentOrders)): ?>
                <div class="text-center py-3 text-muted">
                    <small>Belum ada pesanan</small>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Kode</th>
                                <th>Pembeli</th>
                                <th class="text-end">Total</th>
                                <th>Status</th>
                                <th>Tanggal</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): 
                                $status = $statusConfig[$order['status']] ?? ['label' => $order['status'], 'color' => 'secondary'];
                            ?>
                            <tr>
                                <td><span class="fw-bold">#<?= $order['order_code'] ?></span></td>
                                <td><?= sanitize($order['buyer_name']) ?></td>
                                <td class="text-end fw-bold"><?= formatRupiah($order['total_amount']) ?></td>
                                <td>
                                    <span class="status-badge bg-<?= $status['color'] ?> text-white">
                                        <?= $status['label'] ?>
                                    </span>
                                </td>
                                <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></td>
                                <td class="text-center">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                data-bs-toggle="dropdown">
                                            Ubah Status
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#" onclick="updateStatus(<?= $order['id'] ?>, 'confirmed')">Dikonfirmasi</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="updateStatus(<?= $order['id'] ?>, 'cooking')">Dimasak</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="updateStatus(<?= $order['id'] ?>, 'on_delivery')">Diantar</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="updateStatus(<?= $order['id'] ?>, 'delivered')">Selesai</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick="updateStatus(<?= $order['id'] ?>, 'cancelled')">Batalkan</a></li>
                                        </ul>
                                    </div>
                                </td>
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
        <small class="text-secondary">FoodDelivery &copy; 2025 - Admin Dashboard</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// =============================================
// UPDATE STATUS
// =============================================
function updateStatus(orderId, status) {
    if (!confirm(`Ubah status pesanan ini menjadi ${status}?`)) return;
    
    fetch(BASE_URL + 'controllers/OrderController.php?action=update_status', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            order_id: orderId,
            status: status,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + data.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('❌ ' + data.message, 'danger');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showToast('❌ Terjadi kesalahan', 'danger');
    });
}

// =============================================
// TOAST
// =============================================
function showToast(msg, type = 'success') {
    let wrap = document.getElementById('toast-wrap');
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'toast-wrap';
        wrap.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;max-width:380px;';
        document.body.appendChild(wrap);
    }
    const t = document.createElement('div');
    const bgColor = type === 'success' ? 'text-bg-success' : 'text-bg-danger';
    t.className = `toast align-items-center ${bgColor} border-0 show mb-2`;
    t.style.borderRadius = '12px';
    t.innerHTML = `
        <div class="d-flex">
            <div class="toast-body fw-semibold">${msg}</div>
            <button class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button>
        </div>
    `;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

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