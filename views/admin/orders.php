<?php
/**
 * orders.php
 * Halaman kelola semua pesanan untuk admin
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

// Filter
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$dateFrom = isset($_GET['date_from']) ? sanitize($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? sanitize($_GET['date_to']) : '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query
$where = "WHERE 1=1";
$params = [];

if ($status !== 'all') {
    $where .= " AND o.status = ?";
    $params[] = $status;
}

if (!empty($dateFrom)) {
    $where .= " AND DATE(o.created_at) >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $where .= " AND DATE(o.created_at) <= ?";
    $params[] = $dateTo;
}

// Count total
$countStmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM orders o 
    $where
");
$countStmt->execute($params);
$totalOrders = (int) $countStmt->fetch()['total'];
$totalPages = ceil($totalOrders / $limit);

// Get orders
$stmt = $db->prepare("
    SELECT 
        o.id,
        o.order_code,
        o.total_amount,
        o.status,
        o.created_at,
        o.shipping_address,
        u.name as buyer_name,
        s.name as seller_name,
        d.name as driver_name,
        d.id as driver_id,
        p.status as payment_status,
        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    JOIN users s ON o.seller_id = s.id
    LEFT JOIN users d ON o.driver_id = d.id
    LEFT JOIN payments p ON o.id = p.order_id
    $where
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$limit, $offset]));
$orders = $stmt->fetchAll();

// Get drivers for dropdown
$drivers = $db->query("
    SELECT id, name FROM users 
    WHERE role = 'driver' AND is_verified = 1 AND is_active = 1 
    ORDER BY name
")->fetchAll();

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

$paymentStatusConfig = [
    'pending' => ['label' => 'Menunggu Bayar', 'color' => 'secondary'],
    'uploaded' => ['label' => 'Menunggu Verifikasi', 'color' => 'warning'],
    'verified' => ['label' => 'Terverifikasi', 'color' => 'success'],
    'rejected' => ['label' => 'Ditolak', 'color' => 'danger']
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semua Pesanan - FoodDelivery</title>
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
            padding: 6px 16px;
            font-size: 13px;
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
        .pagination .page-link {
            color: #6f42c1;
        }
        .pagination .page-item.active .page-link {
            background-color: #6f42c1;
            border-color: #6f42c1;
            color: white;
        }
        .table-order td {
            vertical-align: middle;
        }
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
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
                    <a class="nav-link active" href="<?= BASE_URL ?>views/admin/orders.php">
                        <i class="bi bi-bag"></i> Semua Pesanan
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/payments.php">
                        <i class="bi bi-credit-card"></i> Verifikasi Bayar
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
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <h4 class="fw-bold">
                    <i class="bi bi-bag text-primary"></i> Semua Pesanan
                    <span class="badge bg-secondary ms-2"><?= $totalOrders ?></span>
                </h4>
                <a href="<?= BASE_URL ?>views/admin/orders.php?export=csv<?= !empty($status) && $status !== 'all' ? '&status=' . $status : '' ?><?= !empty($dateFrom) ? '&date_from=' . $dateFrom : '' ?><?= !empty($dateTo) ? '&date_to=' . $dateTo : '' ?>" 
                   class="btn btn-success">
                    <i class="bi bi-download me-2"></i> Export CSV
                </a>
            </div>
            
            <!-- ============ FILTERS ============ -->
            <div class="filter-section mb-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Status</label>
                        <select name="status" class="form-select" id="statusFilter" onchange="applyFilters()">
                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Semua</option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Menunggu</option>
                            <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Dikonfirmasi</option>
                            <option value="cooking" <?= $status === 'cooking' ? 'selected' : '' ?>>Dimasak</option>
                            <option value="on_delivery" <?= $status === 'on_delivery' ? 'selected' : '' ?>>Diantar</option>
                            <option value="delivered" <?= $status === 'delivered' ? 'selected' : '' ?>>Selesai</option>
                            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Batal</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Tanggal Dari</label>
                        <input type="date" name="date_from" class="form-control" id="dateFrom" 
                               value="<?= $dateFrom ?>" onchange="applyFilters()">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Tanggal Sampai</label>
                        <input type="date" name="date_to" class="form-control" id="dateTo" 
                               value="<?= $dateTo ?>" onchange="applyFilters()">
                    </div>
                    <div class="col-md-3">
                        <a href="<?= BASE_URL ?>views/admin/orders.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-eraser me-1"></i> Reset Filter
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- ============ ORDER TABLE ============ -->
            <?php if (empty($orders)): ?>
            <div class="text-center py-5 bg-white rounded-3 border">
                <div style="font-size: 64px; color: #dee2e6;">
                    <i class="bi bi-inbox"></i>
                </div>
                <h5 class="fw-bold mt-3">Tidak ada pesanan</h5>
                <p class="text-muted">Tidak ada pesanan dengan filter yang dipilih.</p>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-3 border">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-order">
                        <thead class="table-light">
                            <tr>
                                <th>Kode</th>
                                <th>Pembeli</th>
                                <th>Penjual</th>
                                <th>Driver</th>
                                <th class="text-center">Item</th>
                                <th class="text-end">Total</th>
                                <th>Pembayaran</th>
                                <th>Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): 
                                $status = $statusConfig[$order['status']] ?? ['label' => $order['status'], 'color' => 'secondary'];
                                $payStatus = $paymentStatusConfig[$order['payment_status']] ?? ['label' => $order['payment_status'] ?? '-', 'color' => 'secondary'];
                            ?>
                            <tr>
                                <td>
                                    <span class="fw-bold text-primary">#<?= $order['order_code'] ?></span>
                                    <br><small class="text-muted"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></small>
                                </td>
                                <td><?= sanitize($order['buyer_name']) ?></td>
                                <td><?= sanitize($order['seller_name']) ?></td>
                                <td>
                                    <?php if ($order['driver_name']): ?>
                                        <?= sanitize($order['driver_name']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= $order['item_count'] ?></td>
                                <td class="text-end fw-bold"><?= formatRupiah($order['total_amount']) ?></td>
                                <td>
                                    <span class="status-badge bg-<?= $payStatus['color'] ?> text-white">
                                        <?= $payStatus['label'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge bg-<?= $status['color'] ?> text-white">
                                        <?= $status['label'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex flex-column gap-1">
                                        <!-- Update Status -->
                                        <select class="form-select form-select-sm" 
                                                onchange="updateStatus(<?= $order['id'] ?>, this.value)"
                                                style="width: 130px; font-size: 11px;">
                                            <option value="">Ubah Status</option>
                                            <option value="confirmed">Dikonfirmasi</option>
                                            <option value="cooking">Dimasak</option>
                                            <option value="on_delivery">Diantar</option>
                                            <option value="delivered">Selesai</option>
                                            <option value="cancelled">Batal</option>
                                        </select>
                                        
                                        <!-- Assign Driver -->
                                        <select class="form-select form-select-sm" 
                                                onchange="assignDriver(<?= $order['id'] ?>, this.value)"
                                                style="width: 130px; font-size: 11px;">
                                            <option value="">Assign Driver</option>
                                            <?php foreach ($drivers as $driver): ?>
                                            <option value="<?= $driver['id'] ?>" <?= $order['driver_id'] == $driver['id'] ? 'selected' : '' ?>>
                                                <?= sanitize($driver['name']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ============ PAGINATION ============ -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&status=<?= $status ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>">
                            Sebelumnya
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&status=<?= $status ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>">
                            <?= $i ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&status=<?= $status ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>">
                            Selanjutnya
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - Semua Pesanan</small>
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
// APPLY FILTERS
// =============================================
function applyFilters() {
    const status = document.getElementById('statusFilter').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    
    let url = BASE_URL + 'views/admin/orders.php?';
    url += 'status=' + status;
    if (dateFrom) url += '&date_from=' + dateFrom;
    if (dateTo) url += '&date_to=' + dateTo;
    window.location.href = url;
}

// =============================================
// UPDATE STATUS
// =============================================
function updateStatus(orderId, status) {
    if (!status) {
        event.target.value = '';
        return;
    }
    
    if (!confirm('Ubah status pesanan ini?')) {
        event.target.value = '';
        return;
    }
    
    // Disable select
    const select = event.target;
    select.disabled = true;
    
    fetch(BASE_URL + 'controllers/OrderController.php?action=update_status', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            order_id: orderId,
            status: status,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + data.message, 'success');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showToast('❌ ' + data.message, 'danger');
            select.disabled = false;
            select.value = '';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('❌ Terjadi kesalahan. Silakan coba lagi.', 'danger');
        select.disabled = false;
        select.value = '';
    });
}

// =============================================
// ASSIGN DRIVER - PERBAIKAN
// =============================================
function assignDriver(orderId, driverId) {
    if (!driverId) {
        showToast('❌ Silakan pilih driver terlebih dahulu', 'warning');
        event.target.value = '';
        return;
    }
    
    if (!confirm('Assign driver ke pesanan ini?')) {
        event.target.value = '';
        return;
    }
    
    // Disable select
    const select = event.target;
    select.disabled = true;
    
    fetch(BASE_URL + 'controllers/TrackingController.php?action=assign_driver', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            order_id: orderId,
            driver_id: driverId,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + data.message, 'success');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showToast('❌ ' + data.message, 'danger');
            select.disabled = false;
            select.value = '';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('❌ Terjadi kesalahan. Silakan coba lagi.', 'danger');
        select.disabled = false;
        select.value = '';
    });
}

// =============================================
// TOAST NOTIFICATION
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
    const bgColor = type === 'success' ? 'text-bg-success' : 
                    type === 'warning' ? 'text-bg-warning' : 
                    'text-bg-danger';
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