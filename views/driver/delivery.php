<?php
/**
 * delivery.php
 * Halaman detail pengiriman untuk driver
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
$db = getDB();

// Get order_id
$orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($orderId <= 0) {
    setFlash('danger', 'Order ID tidak valid');
    redirect(BASE_URL . 'views/driver/dashboard.php');
}

// Get order detail - validasi driver_id = user login
$stmt = $db->prepare("
    SELECT 
        o.*,
        u.name as buyer_name,
        u.phone as buyer_phone,
        s.name as seller_name,
        s.phone as seller_phone,
        s.address as seller_address
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    JOIN users s ON o.seller_id = s.id
    WHERE o.id = ? AND o.driver_id = ?
");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch();

if (!$order) {
    setFlash('danger', 'Pengiriman tidak ditemukan atau bukan milik Anda');
    redirect(BASE_URL . 'views/driver/dashboard.php');
}

// Get order items
$stmt = $db->prepare("
    SELECT oi.*, p.image as product_image
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
$flash = getFlash();

$isDelivered = $order['status'] === 'delivered';
$isOnDelivery = $order['status'] === 'on_delivery';
$isCancelled = $order['status'] === 'cancelled';
$isCompleted = $isDelivered || $isCancelled;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Pengiriman - FoodDelivery</title>
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <meta name="order-id" content="<?= $orderId ?>">
    
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
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e9ecef;
        }
        .info-card .label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .info-card .value {
            font-size: 16px;
            font-weight: 600;
        }
        .map-container {
            height: 200px;
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            border-radius: 12px;
            position: relative;
            overflow: hidden;
        }
        .map-dot {
            position: absolute;
            width: 16px;
            height: 16px;
            background: #dc3545;
            border-radius: 50%;
            animation: moveDot 4s infinite ease-in-out;
            z-index: 2;
        }
        .map-dot::after {
            content: '';
            position: absolute;
            top: -6px;
            left: -6px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(220, 53, 69, 0.2);
            animation: ripple 2s infinite;
        }
        .map-dot:nth-child(2) {
            animation-delay: 1.3s;
            background: #ffc107;
        }
        .map-dot:nth-child(2)::after {
            background: rgba(255, 193, 7, 0.2);
        }
        .map-dot:nth-child(3) {
            animation-delay: 2.6s;
            background: #28a745;
        }
        .map-dot:nth-child(3)::after {
            background: rgba(40, 167, 69, 0.2);
        }
        @keyframes moveDot {
            0% { top: 15%; left: 10%; }
            25% { top: 55%; left: 25%; }
            50% { top: 25%; left: 55%; }
            75% { top: 65%; left: 75%; }
            100% { top: 15%; left: 10%; }
        }
        @keyframes ripple {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(2.5); opacity: 0; }
        }
        .map-label {
            position: absolute;
            bottom: 12px;
            left: 12px;
            right: 12px;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #495057;
            background: rgba(255,255,255,0.8);
            padding: 6px 14px;
            border-radius: 8px;
            z-index: 3;
        }
        .item-mini {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #f1f3f5;
        }
        .item-mini:last-child {
            border-bottom: none;
        }
        .item-mini img {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 8px;
        }
        .item-mini .name {
            font-weight: 600;
            font-size: 14px;
        }
        .item-mini .qty {
            font-size: 13px;
            color: #6c757d;
        }
        .item-mini .price {
            font-weight: 700;
            color: #dc3545;
            font-size: 14px;
            margin-left: auto;
        }
        .btn-deliver {
            padding: 14px;
            font-weight: 700;
            font-size: 18px;
            border-radius: 12px;
            transition: all 0.3s;
        }
        .btn-deliver:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.3);
        }
        .btn-deliver:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .status-badge {
            font-size: 14px;
            padding: 6px 20px;
            border-radius: 20px;
            font-weight: 600;
        }
        .delivery-status {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 10px;
        }
        .delivery-status .icon {
            font-size: 24px;
        }
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid #e9ecef;
            }
            .map-container {
                height: 150px;
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
            <a href="<?= BASE_URL ?>views/driver/dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Kembali
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
                    <div class="fw-bold text-primary"><?= sanitize($_SESSION['user']['name'] ?? 'Driver') ?></div>
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
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <h4 class="fw-bold">
                    <i class="bi bi-truck text-primary"></i> Detail Pengiriman
                    <span class="fw-bold text-primary">#<?= $order['order_code'] ?></span>
                </h4>
                <div>
                    <span class="status-badge bg-<?= $isCompleted ? ($isDelivered ? 'success' : 'secondary') : 'primary' ?> text-white">
                        <i class="bi bi-<?= $isDelivered ? 'check-circle' : ($isCancelled ? 'x-circle' : 'truck') ?> me-1"></i>
                        <?= $isDelivered ? 'Telah Sampai' : ($isCancelled ? 'Dibatalkan' : 'Sedang Diantar') ?>
                    </span>
                </div>
            </div>
            
            <div class="row g-4">
                <!-- ============ LEFT COLUMN ============ -->
                <div class="col-lg-8">
                    
                    <!-- ============ MAP ============ -->
                    <div class="info-card mb-4">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-map text-primary"></i> Lokasi Pengiriman
                        </h6>
                        <div class="map-container" id="mapContainer">
                            <div class="map-dot"></div>
                            <div class="map-dot"></div>
                            <div class="map-dot"></div>
                            <div class="map-label">
                                <span><i class="bi bi-shop text-danger"></i> Restoran</span>
                                <span><i class="bi bi-arrow-right"></i> <span id="progressText">Dalam Perjalanan</span></span>
                                <span><i class="bi bi-house text-success"></i> Tujuan</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ============ ORDER ITEMS ============ -->
                    <div class="info-card mb-4">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-receipt text-primary"></i> Item yang Diantar
                        </h6>
                        <?php foreach ($items as $item): ?>
                        <div class="item-mini">
                            <img src="<?= $item['product_image'] ? UPLOAD_URL . $item['product_image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                                 onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                            <div>
                                <div class="name"><?= sanitize($item['product_name'] ?? 'Item') ?></div>
                                <div class="qty">×<?= $item['quantity'] ?></div>
                            </div>
                            <div class="price"><?= formatRupiah($item['price'] * $item['quantity']) ?></div>
                        </div>
                        <?php endforeach; ?>
                        
                        <hr>
                        <div class="d-flex justify-content-between fw-bold fs-5">
                            <span>Total Transaksi</span>
                            <span class="text-danger"><?= formatRupiah($order['total_amount']) ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- ============ RIGHT COLUMN ============ -->
                <div class="col-lg-4">
                    
                    <!-- ============ BUYER INFO ============ -->
                    <div class="info-card mb-3">
                        <h6 class="fw-bold mb-2">
                            <i class="bi bi-person text-primary"></i> Info Pembeli
                        </h6>
                        <div class="mb-1">
                            <div class="label">Nama</div>
                            <div class="value"><?= sanitize($order['buyer_name']) ?></div>
                        </div>
                        <div>
                            <div class="label">Telepon</div>
                            <div class="value"><?= $order['buyer_phone'] ?? '-' ?></div>
                        </div>
                        <div class="mt-2">
                            <div class="label">Alamat Tujuan</div>
                            <div class="value small"><?= sanitize($order['shipping_address']) ?></div>
                        </div>
                    </div>
                    
                    <!-- ============ SELLER INFO ============ -->
                    <div class="info-card mb-3">
                        <h6 class="fw-bold mb-2">
                            <i class="bi bi-shop text-primary"></i> Info Restoran
                        </h6>
                        <div class="mb-1">
                            <div class="label">Nama</div>
                            <div class="value"><?= sanitize($order['seller_name']) ?></div>
                        </div>
                        <div>
                            <div class="label">Telepon</div>
                            <div class="value"><?= $order['seller_phone'] ?? '-' ?></div>
                        </div>
                        <div class="mt-2">
                            <div class="label">Alamat Pick-up</div>
                            <div class="value small"><?= sanitize($order['seller_address'] ?? '-') ?></div>
                        </div>
                    </div>
                    
                    <!-- ============ ACTION BUTTON ============ -->
                    <?php if ($isOnDelivery): ?>
                    <div class="info-card border-success border-2">
                        <div class="text-center">
                            <div class="delivery-status bg-success bg-opacity-10 text-success mb-3 justify-content-center">
                                <span class="icon"><i class="bi bi-truck"></i></span>
                                <span class="fw-bold">Sedang dalam perjalanan</span>
                            </div>
                            <button class="btn btn-success btn-deliver w-100" id="deliverBtn" onclick="confirmDelivery()">
                                <i class="bi bi-check-circle me-2"></i> ✅ Konfirmasi Sudah Terkirim
                            </button>
                            <div class="form-text mt-2">
                                <i class="bi bi-info-circle me-1"></i>
                                Pastikan pesanan sudah sampai ke pembeli
                            </div>
                        </div>
                    </div>
                    <?php elseif ($isDelivered): ?>
                    <div class="info-card border-success">
                        <div class="text-center py-3">
                            <div class="delivery-status bg-success bg-opacity-10 text-success justify-content-center">
                                <span class="icon"><i class="bi bi-check-circle-fill"></i></span>
                                <span class="fw-bold">Pesanan telah sampai</span>
                            </div>
                            <small class="text-muted">Terima kasih telah mengantar!</small>
                        </div>
                    </div>
                    <?php elseif ($isCancelled): ?>
                    <div class="info-card border-danger">
                        <div class="text-center py-3">
                            <div class="delivery-status bg-danger bg-opacity-10 text-danger justify-content-center">
                                <span class="icon"><i class="bi bi-x-circle-fill"></i></span>
                                <span class="fw-bold">Pesanan dibatalkan</span>
                            </div>
                            <small class="text-muted">Pengiriman tidak dilanjutkan</small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - Detail Pengiriman</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const ORDER_ID = parseInt(document.querySelector('meta[name="order-id"]').getAttribute('content'));
const isCompleted = <?= $isCompleted ? 'true' : 'false' ?>;

// =============================================
// CONFIRM DELIVERY
// =============================================
function confirmDelivery() {
    if (!confirm('✅ Konfirmasi bahwa pesanan sudah sampai ke pembeli?')) return;
    
    const btn = document.getElementById('deliverBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Memproses...';
    
    fetch(BASE_URL + 'controllers/TrackingController.php?action=update_delivery', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            order_id: ORDER_ID,
            status: 'delivered',
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + data.message, 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showToast('❌ ' + data.message, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-2"></i> ✅ Konfirmasi Sudah Terkirim';
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showToast('❌ Terjadi kesalahan. Silakan coba lagi.', 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-2"></i> ✅ Konfirmasi Sudah Terkirim';
    });
}

// =============================================
// POLLING STATUS (15 detik)
// =============================================
let pollInterval = null;

function checkOrderStatus() {
    if (isCompleted) {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
        return;
    }
    
    fetch(BASE_URL + 'controllers/OrderController.php?action=get_order_detail&order_id=' + ORDER_ID)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.order.status === 'delivered') {
                // Order sudah selesai, refresh halaman
                showToast('✅ Pesanan telah sampai!', 'success');
                setTimeout(() => window.location.reload(), 2000);
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            }
        })
        .catch(err => console.error('Polling error:', err));
}

// Start polling jika belum selesai
if (!isCompleted) {
    pollInterval = setInterval(checkOrderStatus, 15000);
}

// =============================================
// PROGRESS TEXT ANIMATION
// =============================================
const progressTexts = ['Dalam Perjalanan', 'Menuju Lokasi', 'Hampir Sampai', 'Tiba Sebentar Lagi'];
let progressIndex = 0;

if (!isCompleted) {
    setInterval(() => {
        progressIndex = (progressIndex + 1) % progressTexts.length;
        const el = document.getElementById('progressText');
        if (el) {
            el.textContent = progressTexts[progressIndex];
            el.style.transition = 'opacity 0.3s';
            el.style.opacity = '0';
            setTimeout(() => {
                el.style.opacity = '1';
            }, 300);
        }
    }, 4000);
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
</script>

</body>
</html>