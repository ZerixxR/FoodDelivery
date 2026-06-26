<?php
/**
 * orders.php
 * Halaman daftar pesanan buyer
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

// Filter status
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query
$where = "WHERE o.buyer_id = ?";
$params = [$userId];

if ($status !== 'all') {
    $where .= " AND o.status = ?";
    $params[] = $status;
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
        o.*,
        u.name as seller_name,
        u.phone as seller_phone,
        p.status as payment_status,
        p.proof as payment_proof
    FROM orders o
    JOIN users u ON o.seller_id = u.id
    LEFT JOIN payments p ON o.id = p.order_id
    $where
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$limit, $offset]));
$orders = $stmt->fetchAll();

// Get order items for each order
foreach ($orders as &$order) {
    $stmt = $db->prepare("
        SELECT oi.*, p.image as product_image
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll();
}

$csrfToken = generateCsrfToken();
$flash = getFlash();

// Status labels & colors
$statusConfig = [
    'pending' => ['label' => 'Menunggu Pembayaran', 'color' => 'warning'],
    'confirmed' => ['label' => 'Dikonfirmasi', 'color' => 'info'],
    'cooking' => ['label' => 'Sedang Dimasak', 'color' => 'primary'],
    'on_delivery' => ['label' => 'Sedang Diantar', 'color' => 'success'],
    'delivered' => ['label' => 'Selesai', 'color' => 'success'],
    'cancelled' => ['label' => 'Dibatalkan', 'color' => 'danger']
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesanan Saya - FoodDelivery</title>
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    
    <style>
        .order-card {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            transition: all 0.2s;
            background: white;
        }
        .order-card:hover {
            border-color: #dc3545;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
        }
        .status-badge {
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        .order-item-img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 6px;
        }
        .tab-filter .nav-link {
            color: #6c757d;
            font-weight: 500;
            border-radius: 8px;
            padding: 6px 16px;
        }
        .tab-filter .nav-link.active {
            color: #dc3545;
            background: #fff5f5;
        }
        .tab-filter .nav-link:hover:not(.active) {
            background: #f8f9fa;
        }
        .order-more-items {
            font-size: 12px;
            color: #6c757d;
            background: #f8f9fa;
            padding: 2px 10px;
            border-radius: 12px;
        }
        .pagination .page-link {
            color: #dc3545;
        }
        .pagination .page-item.active .page-link {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }
        .pagination .page-link:hover {
            color: #b02a37;
        }
        @media (max-width: 768px) {
            .order-card .row > div {
                padding: 8px 0;
            }
            .order-card .row > div:not(:last-child) {
                border-bottom: 1px solid #f1f3f5;
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
                    <a class="nav-link active" href="<?= BASE_URL ?>views/buyer/orders.php">
                        <i class="bi bi-bag"></i> Pesanan
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link position-relative" href="<?= BASE_URL ?>views/buyer/cart.php">
                        <i class="bi bi-cart3 fs-5"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger cart-badge" id="cartBadge">0</span>
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= sanitize(getUser()['name'] ?? 'User') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="#" onclick="handleLogout(event)">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </a>
                        </li>
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
    <h4 class="fw-bold mb-4">
        <i class="bi bi-bag text-danger"></i> Pesanan Saya
    </h4>
    
    <!-- ============ TABS ============ -->
    <ul class="nav nav-pills tab-filter mb-4 gap-1 flex-wrap">
        <li class="nav-item">
            <a class="nav-link <?= $status === 'all' ? 'active' : '' ?>" href="?status=all">
                Semua <span class="badge bg-secondary ms-1"><?= $totalOrders ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $status === 'pending' ? 'active' : '' ?>" href="?status=pending">Menunggu</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= in_array($status, ['confirmed', 'cooking']) ? 'active' : '' ?>" href="?status=confirmed">Diproses</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $status === 'on_delivery' ? 'active' : '' ?>" href="?status=on_delivery">Dikirim</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $status === 'delivered' ? 'active' : '' ?>" href="?status=delivered">Selesai</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $status === 'cancelled' ? 'active' : '' ?>" href="?status=cancelled">Batal</a>
        </li>
    </ul>
    
    <!-- ============ ORDER LIST ============ -->
    <?php if (empty($orders)): ?>
    <div class="text-center py-5 bg-white rounded-4 border">
        <div style="font-size: 64px; color: #dee2e6;">
            <i class="bi bi-inbox"></i>
        </div>
        <h5 class="fw-bold mt-3">Tidak ada pesanan</h5>
        <p class="text-muted">Anda belum memiliki pesanan dengan status ini.</p>
        <a href="<?= BASE_URL ?>views/public/menu.php" class="btn btn-danger mt-2">
            <i class="bi bi-arrow-left me-2"></i> Mulai Belanja
        </a>
    </div>
    <?php else: ?>
    
    <div class="row g-3">
        <?php foreach ($orders as $order): 
            $statusInfo = $statusConfig[$order['status']] ?? ['label' => $order['status'], 'color' => 'secondary'];
            $items = $order['items'] ?? [];
            $itemCount = count($items);
            $displayItems = array_slice($items, 0, 2);
            $remainingItems = $itemCount - 2;
        ?>
        <div class="col-12">
            <div class="order-card p-3">
                <div class="row g-3 align-items-center">
                    <!-- Order Info -->
                    <div class="col-md-4">
                        <div class="d-flex align-items-start gap-3">
                            <div class="flex-grow-1">
                                <div class="fw-bold text-danger">#<?= $order['order_code'] ?></div>
                                <div class="small text-muted">
                                    <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?>
                                </div>
                                <div class="mt-1">
                                    <span class="status-badge bg-<?= $statusInfo['color'] ?> text-white">
                                        <?= $statusInfo['label'] ?>
                                    </span>
                                    <?php if ($order['payment_status'] === 'uploaded'): ?>
                                    <span class="status-badge bg-info text-white ms-1">Menunggu Verifikasi</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Items -->
                    <div class="col-md-4">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <?php foreach ($displayItems as $item): ?>
                            <div class="d-flex align-items-center gap-2 bg-light rounded-3 px-2 py-1">
                                <img src="<?= isset($item['product_image']) && $item['product_image'] ? UPLOAD_URL . $item['product_image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                                     alt="<?= sanitize($item['product_name'] ?? 'Item') ?>"
                                     class="order-item-img"
                                     onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                                <span class="small"><?= sanitize($item['product_name'] ?? 'Item') ?> ×<?= $item['quantity'] ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($remainingItems > 0): ?>
                            <span class="order-more-items">+<?= $remainingItems ?> lainnya</span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted mt-1">
                            <i class="bi bi-shop me-1"></i><?= sanitize($order['seller_name']) ?>
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div class="col-md-4">
                        <div class="d-flex flex-wrap align-items-center justify-content-md-end gap-2">
                            <div class="fw-bold text-danger me-2">
                                <?= formatRupiah($order['total_amount']) ?>
                            </div>
                            
                            <!-- Detail & Tracking -->
                            <a href="<?= BASE_URL ?>views/buyer/tracking.php?order_id=<?= $order['id'] ?>" 
                               class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-eye me-1"></i> Detail
                            </a>
                            
                            <!-- Upload Bukti (pending & belum upload) -->
                            <?php if ($order['status'] === 'pending' && $order['payment_status'] !== 'uploaded'): ?>
                            <button class="btn btn-danger btn-sm" onclick="showUploadProof(<?= $order['id'] ?>)">
                                <i class="bi bi-upload me-1"></i> Upload Bukti
                            </button>
                            <?php endif; ?>
                            
                            <!-- Review (delivered & belum review) -->
                            <?php if ($order['status'] === 'delivered'): ?>
                            <a href="<?= BASE_URL ?>views/buyer/review.php?order_id=<?= $order['id'] ?>" 
                               class="btn btn-warning btn-sm">
                                <i class="bi bi-star me-1"></i> Ulasan
                            </a>
                            <?php endif; ?>
                            
                            <!-- Cancel (pending) -->
                            <?php if ($order['status'] === 'pending'): ?>
                            <button class="btn btn-outline-secondary btn-sm" onclick="cancelOrder(<?= $order['id'] ?>)">
                                <i class="bi bi-x-circle me-1"></i> Batal
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
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

<!-- ============ MODAL UPLOAD BUKTI ============ -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload text-danger"></i> Upload Bukti Pembayaran</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="uploadForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="order_id" id="uploadOrderId">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="upload_proof">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Pilih File Bukti</label>
                        <input type="file" name="proof" class="form-control" accept="image/*" required>
                        <div class="form-text">Format JPG, PNG, WEBP. Maks 2MB.</div>
                    </div>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-2"></i>
                        Upload bukti transfer atau screenshot pembayaran Anda.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-cloud-upload me-2"></i> Upload
                    </button>
                </div>
            </form>
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
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// =============================================
// SHOW UPLOAD MODAL
// =============================================
function showUploadProof(orderId) {
    document.getElementById('uploadOrderId').value = orderId;
    const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
    modal.show();
}

// =============================================
// UPLOAD PROOF
// =============================================
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Mengupload...';
    
    const formData = new FormData(this);
    
    fetch(BASE_URL + 'controllers/PaymentController.php?action=upload_proof', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + data.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('❌ ' + data.message, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-upload me-2"></i> Upload';
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showToast('❌ Terjadi kesalahan', 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-2"></i> Upload';
    });
});

// =============================================
// CANCEL ORDER
// =============================================
function cancelOrder(orderId) {
    if (!confirm('Batalkan pesanan ini?')) return;
    
    fetch(BASE_URL + 'controllers/OrderController.php?action=update_status', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            order_id: orderId,
            status: 'cancelled',
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ Pesanan dibatalkan', 'success');
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