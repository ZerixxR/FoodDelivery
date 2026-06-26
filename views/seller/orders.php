<?php
/**
 * orders.php
 * Halaman daftar pesanan masuk seller
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

// Filter status
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query - hanya pesanan yang mengandung produk milik seller
$where = "WHERE EXISTS (
    SELECT 1 FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = o.id AND p.seller_id = ?
)";
$params = [$userId];

if ($status !== 'all') {
    $where .= " AND o.status = ?";
    $params[] = $status;
}

// Count total
$countStmt = $db->prepare("
    SELECT COUNT(DISTINCT o.id) as total 
    FROM orders o 
    $where
");
$countStmt->execute($params);
$totalOrders = (int) $countStmt->fetch()['total'];
$totalPages = ceil($totalOrders / $limit);

// Get orders
$stmt = $db->prepare("
    SELECT DISTINCT 
        o.id, o.order_code, o.total_amount, o.status, o.created_at,
        u.name as buyer_name, u.phone as buyer_phone,
        o.shipping_address,
        p.status as payment_status
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    LEFT JOIN payments p ON o.id = p.order_id
    $where
    ORDER BY 
        CASE o.status 
            WHEN 'confirmed' THEN 1 
            WHEN 'pending' THEN 2 
            WHEN 'cooking' THEN 3 
            WHEN 'on_delivery' THEN 4 
            WHEN 'delivered' THEN 5 
            WHEN 'cancelled' THEN 6 
        END,
        o.created_at ASC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$limit, $offset]));
$orders = $stmt->fetchAll();

// Get items for each order (only seller's items)
foreach ($orders as &$order) {
    $stmt = $db->prepare("
        SELECT oi.*, p.name as product_name, p.image as product_image
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ? AND p.seller_id = ?
    ");
    $stmt->execute([$order['id'], $userId]);
    $order['items'] = $stmt->fetchAll();
    
    // Calculate subtotal khusus seller
    $order['seller_subtotal'] = 0;
    foreach ($order['items'] as $item) {
        $order['seller_subtotal'] += $item['price'] * $item['quantity'];
    }
}

$csrfToken = generateCsrfToken();
$flash = getFlash();

// Status config
$statusConfig = [
    'pending' => ['label' => 'Menunggu Pembayaran', 'color' => 'secondary', 'icon' => 'bi-clock'],
    'confirmed' => ['label' => 'Perlu Dimasak', 'color' => 'danger', 'icon' => 'bi-exclamation-triangle'],
    'cooking' => ['label' => 'Sedang Dimasak', 'color' => 'primary', 'icon' => 'bi-fire'],
    'on_delivery' => ['label' => 'Sedang Diantar', 'color' => 'success', 'icon' => 'bi-truck'],
    'delivered' => ['label' => 'Selesai', 'color' => 'success', 'icon' => 'bi-check-circle'],
    'cancelled' => ['label' => 'Dibatalkan', 'color' => 'secondary', 'icon' => 'bi-x-circle']
];

// Count pesanan confirmed (perlu dimasak)
$countStmt = $db->prepare("
    SELECT COUNT(DISTINCT o.id) as total 
    FROM orders o
    WHERE EXISTS (
        SELECT 1 FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = o.id AND p.seller_id = ?
    ) AND o.status = 'confirmed'
");
$countStmt->execute([$userId]);
$needCookCount = (int) $countStmt->fetch()['total'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesanan Masuk - FoodDelivery</title>
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
        .tab-filter .nav-link {
            color: #6c757d;
            font-weight: 500;
            border-radius: 8px;
            padding: 6px 16px;
            font-size: 14px;
        }
        .tab-filter .nav-link.active {
            color: #dc3545;
            background: #fff5f5;
        }
        .tab-filter .nav-link:hover:not(.active) {
            background: #f8f9fa;
        }
        .status-badge {
            font-size: 12px;
            padding: 4px 14px;
            border-radius: 20px;
            font-weight: 600;
        }
        .order-item-mini {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8f9fa;
            padding: 2px 10px 2px 4px;
            border-radius: 6px;
            font-size: 13px;
            margin: 2px 4px 2px 0;
        }
        .order-item-mini img {
            width: 28px;
            height: 28px;
            object-fit: cover;
            border-radius: 4px;
        }
        .order-row {
            transition: all 0.2s;
        }
        .order-row:hover {
            background: #fff8f8;
        }
        .order-row.confirmed {
            border-left: 3px solid #dc3545;
        }
        .btn-update {
            font-size: 12px;
            padding: 4px 14px;
            border-radius: 8px;
            font-weight: 600;
        }
        .badge-count {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 20px;
        }
        .pagination .page-link {
            color: #dc3545;
        }
        .pagination .page-item.active .page-link {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }
        .status-action {
            display: flex;
            flex-direction: column;
            gap: 4px;
            align-items: flex-start;
        }
        .status-action .badge-payment {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 12px;
        }
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid #e9ecef;
            }
            .order-row .row > div {
                padding: 4px 0;
            }
            .order-row .row > div:not(:last-child) {
                border-bottom: 1px solid #f1f3f5;
            }
            .status-action {
                flex-direction: row;
                flex-wrap: wrap;
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
                <i class="bi bi-shop me-1"></i> <?= sanitize($_SESSION['user']['name'] ?? '') ?>
            </span>
            <a href="<?= BASE_URL ?>controllers/AuthController.php?action=logout_redirect" 
               class="btn btn-outline-danger btn-sm">
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
                    <div class="fw-bold text-danger"><?= sanitize($_SESSION['user']['name'] ?? 'Restoran') ?></div>
                    <small class="text-muted">Penjual</small>
                </div>
                <nav class="nav flex-column">
                    <a class="nav-link" href="<?= BASE_URL ?>views/seller/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/seller/menu.php">
                        <i class="bi bi-grid"></i> Kelola Menu
                    </a>
                    <a class="nav-link active" href="<?= BASE_URL ?>views/seller/orders.php">
                        <i class="bi bi-bag"></i> Pesanan Masuk
                        <?php if ($needCookCount > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-2" id="needCookBadge"><?= $needCookCount ?></span>
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
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <h4 class="fw-bold">
                    <i class="bi bi-bag text-danger"></i> Pesanan Masuk
                    <span class="badge bg-secondary ms-2" id="orderCount"><?= $totalOrders ?></span>
                </h4>
                <span class="text-muted small" id="lastUpdate">
                    <i class="bi bi-arrow-repeat me-1"></i> Live
                </span>
            </div>
            
            <!-- ============ TABS ============ -->
            <ul class="nav nav-pills tab-filter mb-4 gap-1 flex-wrap">
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'all' ? 'active' : '' ?>" href="?status=all">
                        Semua <span class="badge bg-secondary ms-1"><?= $totalOrders ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'confirmed' ? 'active' : '' ?> position-relative" href="?status=confirmed">
                        🔥 Perlu Dimasak
                        <?php if ($needCookCount > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-1"><?= $needCookCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'cooking' ? 'active' : '' ?>" href="?status=cooking">
                        👨‍🍳 Dimasak
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'on_delivery' ? 'active' : '' ?>" href="?status=on_delivery">
                        🛵 Diantar
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'delivered' ? 'active' : '' ?>" href="?status=delivered">
                        ✅ Selesai
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'pending' ? 'active' : '' ?>" href="?status=pending">
                        ⏳ Menunggu
                    </a>
                </li>
            </ul>
            
            <!-- ============ ORDER LIST ============ -->
            <?php if (empty($orders)): ?>
            <div class="text-center py-5 bg-white rounded-4 border">
                <div style="font-size: 64px; color: #dee2e6;">
                    <i class="bi bi-inbox"></i>
                </div>
                <h5 class="fw-bold mt-3">Tidak ada pesanan</h5>
                <p class="text-muted">Belum ada pesanan dengan status ini.</p>
            </div>
            <?php else: ?>
            
            <div class="bg-white rounded-3 border">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Kode</th>
                                <th>Pembeli</th>
                                <th>Item</th>
                                <th class="text-end">Subtotal</th>
                                <th>Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): 
                                $statusInfo = $statusConfig[$order['status']] ?? ['label' => $order['status'], 'color' => 'secondary', 'icon' => 'bi-circle'];
                                $isConfirmed = $order['status'] === 'confirmed';
                                $isCooking = $order['status'] === 'cooking';
                                $isPending = $order['status'] === 'pending';
                                $isOnDelivery = $order['status'] === 'on_delivery';
                                $isDelivered = $order['status'] === 'delivered';
                                $isCancelled = $order['status'] === 'cancelled';
                                $isPaymentVerified = $order['payment_status'] === 'verified';
                            ?>
                            <tr class="order-row <?= $isConfirmed ? 'confirmed' : '' ?>">
                                <td>
                                    <span class="fw-bold text-danger">#<?= $order['order_code'] ?></span>
                                    <br><small class="text-muted"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></small>
                                    <?php if ($order['payment_status'] === 'uploaded'): ?>
                                    <br><span class="badge bg-warning text-dark badge-payment">Menunggu Verifikasi</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= sanitize($order['buyer_name']) ?></div>
                                    <small class="text-muted"><?= $order['buyer_phone'] ?? '-' ?></small>
                                </td>
                                <td>
                                    <?php foreach ($order['items'] as $item): ?>
                                    <div class="order-item-mini">
                                        <img src="<?= $item['product_image'] ? UPLOAD_URL . $item['product_image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                                             onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                                        <?= sanitize($item['product_name']) ?> ×<?= $item['quantity'] ?>
                                    </div>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-end fw-bold text-danger">
                                    <?= formatRupiah($order['seller_subtotal']) ?>
                                </td>
                                <td>
                                    <span class="status-badge bg-<?= $statusInfo['color'] ?> text-white">
                                        <i class="<?= $statusInfo['icon'] ?> me-1"></i>
                                        <?= $statusInfo['label'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <!-- ============================================================ -->
                                    <!-- ============ ACTION UNTUK SELLER ============ -->
                                    <!-- ============================================================ -->
                                    <div class="status-action">
                                        
                                        <!-- STATUS: CONFIRMED → Mulai Masak -->
                                        <?php if ($isConfirmed): ?>
                                        <button class="btn btn-primary btn-update" 
                                                onclick="updateStatus(<?= $order['id'] ?>, 'cooking')">
                                            <i class="bi bi-fire me-1"></i> Mulai Masak
                                        </button>
                                        <?php endif; ?>
                                        
                                        <!-- STATUS: COOKING → Siap Diantar -->
                                        <?php if ($isCooking): ?>
                                        <button class="btn btn-success btn-update" 
                                                onclick="updateStatus(<?= $order['id'] ?>, 'on_delivery')">
                                            <i class="bi bi-truck me-1"></i> Siap Diantar
                                        </button>
                                        <?php endif; ?>
                                        
                                        <!-- STATUS: PENDING → Menunggu Pembayaran -->
                                        <?php if ($isPending): ?>
                                        <span class="text-muted small">
                                            <i class="bi bi-clock me-1"></i> Menunggu pembayaran
                                        </span>
                                        <?php if ($order['payment_status'] === 'uploaded'): ?>
                                        <span class="badge bg-warning text-dark badge-payment">
                                            <i class="bi bi-hourglass-split me-1"></i> Menunggu Verifikasi Admin
                                        </span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <!-- STATUS: ON_DELIVERY → Sedang Diantar -->
                                        <?php if ($isOnDelivery): ?>
                                        <span class="text-muted small">
                                            <i class="bi bi-truck me-1"></i> Sedang diantar driver
                                        </span>
                                        <?php endif; ?>
                                        
                                        <!-- STATUS: DELIVERED → Selesai -->
                                        <?php if ($isDelivered): ?>
                                        <span class="text-success small">
                                            <i class="bi bi-check-circle me-1"></i> Pesanan selesai
                                        </span>
                                        <?php endif; ?>
                                        
                                        <!-- STATUS: CANCELLED → Dibatalkan -->
                                        <?php if ($isCancelled): ?>
                                        <span class="text-danger small">
                                            <i class="bi bi-x-circle me-1"></i> Dibatalkan
                                        </span>
                                        <?php endif; ?>
                                        
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- ============ PAGINATION ============ -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?status=<?= $status ?>&page=<?= $page - 1 ?>">Sebelumnya</a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?status=<?= $status ?>&page=<?= $i ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?status=<?= $status ?>&page=<?= $page + 1 ?>">Selanjutnya</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - Pesanan Masuk</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// =============================================
// KONFIGURASI
// =============================================
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// =============================================
// UPDATE ORDER STATUS
// =============================================
function updateStatus(orderId, status) {
    const statusLabels = {
        'cooking': 'Mulai memasak',
        'on_delivery': 'Siap diantar'
    };
    
    if (!confirm(`Yakin ingin ${statusLabels[status] || 'update'} pesanan ini?`)) return;
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
    
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
            btn.disabled = false;
            btn.innerHTML = btn.dataset.originalText || 'Coba Lagi';
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showToast('❌ Terjadi kesalahan', 'danger');
        btn.disabled = false;
        btn.innerHTML = btn.dataset.originalText || 'Coba Lagi';
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
    window.location.href = BASE_URL + 'controllers/AuthController.php?action=logout_redirect';
}

// =============================================
// POLLING REAL-TIME (30 detik)
// =============================================
function checkNewOrders() {
    fetch(BASE_URL + 'controllers/OrderController.php?action=count_confirmed')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.count !== undefined) {
                const badge = document.getElementById('needCookBadge');
                if (badge) {
                    badge.textContent = data.count;
                    badge.style.display = data.count > 0 ? 'inline' : 'none';
                }
            }
        })
        .catch(err => console.error('Polling error:', err));
}

setInterval(checkNewOrders, 30000);
</script>

</body>
</html>