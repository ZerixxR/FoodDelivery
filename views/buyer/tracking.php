<?php
/**
 * tracking.php
 * Halaman tracking pesanan real-time
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

// Get order_id
$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

if ($orderId <= 0) {
    setFlash('danger', 'Order ID tidak valid');
    redirect(BASE_URL . 'views/buyer/orders.php');
}

// Get order detail
$stmt = $db->prepare("
    SELECT 
        o.*,
        u.name as seller_name,
        u.phone as seller_phone,
        d.name as driver_name,
        d.phone as driver_phone,
        p.status as payment_status,
        p.proof as payment_proof
    FROM orders o
    JOIN users u ON o.seller_id = u.id
    LEFT JOIN users d ON o.driver_id = d.id
    LEFT JOIN payments p ON o.id = p.order_id
    WHERE o.id = ? AND o.buyer_id = ?
");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch();

if (!$order) {
    setFlash('danger', 'Pesanan tidak ditemukan');
    redirect(BASE_URL . 'views/buyer/orders.php');
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

// Status config
$statusConfig = [
    'pending' => ['label' => 'Menunggu Konfirmasi', 'icon' => 'bi-clock', 'color' => 'warning'],
    'confirmed' => ['label' => 'Dikonfirmasi', 'icon' => 'bi-check-circle', 'color' => 'info'],
    'cooking' => ['label' => 'Sedang Dimasak', 'icon' => 'bi-fire', 'color' => 'primary'],
    'on_delivery' => ['label' => 'Sedang Diantar', 'icon' => 'bi-truck', 'color' => 'success'],
    'delivered' => ['label' => 'Telah Sampai', 'icon' => 'bi-check-circle-fill', 'color' => 'success'],
    'cancelled' => ['label' => 'Dibatalkan', 'icon' => 'bi-x-circle', 'color' => 'danger']
];

// Steps untuk tracking
$steps = [
    ['key' => 'pending', 'label' => 'Pesanan Diterima', 'icon' => 'bi-inbox'],
    ['key' => 'confirmed', 'label' => 'Dikonfirmasi', 'icon' => 'bi-check-circle'],
    ['key' => 'cooking', 'label' => 'Sedang Dimasak', 'icon' => 'bi-fire'],
    ['key' => 'on_delivery', 'label' => 'Dalam Pengiriman', 'icon' => 'bi-truck'],
    ['key' => 'delivered', 'label' => 'Telah Sampai', 'icon' => 'bi-flag']
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracking Pesanan - FoodDelivery</title>
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <meta name="order-id" content="<?= $orderId ?>">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    
    <style>
        .tracking-container {
            background: white;
            border-radius: 16px;
            padding: 30px;
            border: 1px solid #e9ecef;
        }
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
        }
        .step .step-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: #e9ecef;
            color: #adb5bd;
            transition: all 0.3s;
            z-index: 2;
            border: 3px solid #e9ecef;
        }
        .step.active .step-icon {
            background: #dc3545;
            color: white;
            border-color: #dc3545;
            animation: pulse 1.5s infinite;
        }
        .step.done .step-icon {
            background: #28a745;
            color: white;
            border-color: #28a745;
        }
        .step.cancelled .step-icon {
            background: #dc3545;
            color: white;
            border-color: #dc3545;
        }
        .step .step-label {
            font-size: 12px;
            font-weight: 600;
            margin-top: 8px;
            text-align: center;
            color: #6c757d;
        }
        .step.active .step-label {
            color: #dc3545;
        }
        .step.done .step-label {
            color: #28a745;
        }
        .step .step-line {
            position: absolute;
            top: 24px;
            left: calc(50% + 24px);
            right: calc(-50% + 24px);
            height: 3px;
            background: #e9ecef;
            z-index: 1;
        }
        .step:last-child .step-line {
            display: none;
        }
        .step.done .step-line {
            background: #28a745;
        }
        .step.active .step-line {
            background: #dc3545;
        }
        .step.cancelled .step-line {
            background: #dc3545;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
        
        .map-simulator {
            height: 120px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            position: relative;
            overflow: hidden;
        }
        .map-dot {
            position: absolute;
            width: 12px;
            height: 12px;
            background: #dc3545;
            border-radius: 50%;
            animation: moveDot 3s infinite ease-in-out;
        }
        .map-dot::after {
            content: '';
            position: absolute;
            top: -4px;
            left: -4px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: rgba(220, 53, 69, 0.2);
            animation: ripple 2s infinite;
        }
        .map-dot:nth-child(2) {
            animation-delay: 0.8s;
            background: #ffc107;
        }
        .map-dot:nth-child(3) {
            animation-delay: 1.6s;
            background: #28a745;
        }
        
        @keyframes moveDot {
            0% { top: 20%; left: 10%; }
            25% { top: 60%; left: 30%; }
            50% { top: 30%; left: 60%; }
            75% { top: 70%; left: 80%; }
            100% { top: 20%; left: 10%; }
        }
        @keyframes ripple {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(2.5); opacity: 0; }
        }
        
        .driver-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 16px 20px;
            border-left: 4px solid #dc3545;
        }
        .status-badge {
            font-size: 14px;
            padding: 6px 18px;
            border-radius: 20px;
            font-weight: 600;
        }
        .order-item-mini {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #f1f3f5;
        }
        .order-item-mini:last-child {
            border-bottom: none;
        }
        .order-item-mini img {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 8px;
        }
        .order-item-mini .name {
            font-weight: 600;
            font-size: 14px;
        }
        .order-item-mini .qty {
            font-size: 13px;
            color: #6c757d;
        }
        .order-item-mini .price {
            font-weight: 700;
            color: #dc3545;
            font-size: 14px;
            margin-left: auto;
        }
        .tracking-refresh {
            font-size: 12px;
            color: #6c757d;
            animation: blink 1s infinite;
        }
        @keyframes blink {
            0% { opacity: 1; }
            50% { opacity: 0.3; }
            100% { opacity: 1; }
        }
        @media (max-width: 768px) {
            .tracking-container {
                padding: 16px;
            }
            .step .step-icon {
                width: 36px;
                height: 36px;
                font-size: 16px;
            }
            .step .step-label {
                font-size: 10px;
            }
            .step .step-line {
                top: 18px;
            }
            .map-simulator {
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
        <div class="d-flex align-items-center gap-2">
            <a href="<?= BASE_URL ?>views/buyer/orders.php" class="btn btn-outline-danger btn-sm">
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
<div class="container py-4">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="tracking-container">
                <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
                    <div>
                        <h5 class="fw-bold mb-1">
                            <i class="bi bi-map text-danger"></i> Tracking Pesanan
                        </h5>
                        <div class="text-muted small">
                            Kode: <span class="fw-bold">#<?= $order['order_code'] ?></span>
                            <span class="mx-2">|</span>
                            <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?>
                        </div>
                    </div>
                    <div>
                        <span class="status-badge bg-<?= $statusConfig[$order['status']]['color'] ?> text-white">
                            <i class="<?= $statusConfig[$order['status']]['icon'] ?> me-1"></i>
                            <?= $statusConfig[$order['status']]['label'] ?>
                        </span>
                        <span class="tracking-refresh ms-2" id="refreshStatus">
                            <i class="bi bi-arrow-repeat"></i> Live
                        </span>
                    </div>
                </div>
                
                <!-- ============ STEP TRACKER ============ -->
                <div id="stepTracker" class="position-relative">
                    <div class="d-flex justify-content-between">
                        <?php 
                        $currentStatus = $order['status'];
                        $isCancelled = $currentStatus === 'cancelled';
                        $currentIndex = array_search($currentStatus, array_column($steps, 'key'));
                        if ($currentIndex === false) $currentIndex = -1;
                        ?>
                        <?php foreach ($steps as $index => $step): 
                            $isDone = !$isCancelled && $index <= $currentIndex;
                            $isActive = !$isCancelled && $index === $currentIndex;
                            $stepClass = $isCancelled ? 'cancelled' : ($isActive ? 'active' : ($isDone ? 'done' : ''));
                        ?>
                        <div class="step <?= $stepClass ?>">
                            <div class="step-icon">
                                <i class="<?= $step['icon'] ?>"></i>
                            </div>
                            <div class="step-label"><?= $step['label'] ?></div>
                            <div class="step-line"></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($isCancelled): ?>
                    <div class="text-center mt-3">
                        <span class="badge bg-danger fs-6 py-2 px-4">
                            <i class="bi bi-x-circle me-2"></i> Pesanan Dibatalkan
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- ============ MAP SIMULATOR ============ -->
                <?php if ($currentStatus === 'on_delivery' || $currentStatus === 'delivered'): ?>
                <div class="mt-4">
                    <div class="map-simulator">
                        <div class="map-dot"></div>
                        <div class="map-dot"></div>
                        <div class="map-dot"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <small class="text-muted"><i class="bi bi-geo-alt text-danger"></i> Restoran</small>
                        <small class="text-muted"><i class="bi bi-house text-success"></i> Tujuan</small>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- ============ DRIVER INFO ============ -->
                <?php if ($order['driver_id']): ?>
                <div class="driver-card mt-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-danger text-white rounded-circle p-2" style="width: 44px; height: 44px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-person fs-5"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold"><?= sanitize($order['driver_name'] ?? 'Driver') ?></div>
                            <div class="small text-muted">
                                <i class="bi bi-telephone me-1"></i> <?= $order['driver_phone'] ?? '-' ?>
                            </div>
                        </div>
                        <div>
                            <span class="badge bg-success">
                                <i class="bi bi-check-circle me-1"></i> Aktif
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ============ ORDER DETAILS ============ -->
        <div class="col-lg-4">
            <div class="tracking-container">
                <h6 class="fw-bold mb-3">
                    <i class="bi bi-receipt text-danger"></i> Rincian Pesanan
                </h6>
                
                <?php foreach ($items as $item): ?>
                <div class="order-item-mini">
                    <img src="<?= isset($item['product_image']) && $item['product_image'] ? UPLOAD_URL . $item['product_image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                         alt="<?= sanitize($item['product_name'] ?? 'Item') ?>"
                         onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                    <div>
                        <div class="name"><?= sanitize($item['product_name'] ?? 'Item') ?></div>
                        <div class="qty">×<?= $item['quantity'] ?></div>
                    </div>
                    <div class="price"><?= formatRupiah($item['price'] * $item['quantity']) ?></div>
                </div>
                <?php endforeach; ?>
                
                <hr>
                
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Subtotal</span>
                    <span class="fw-bold"><?= formatRupiah($order['total_amount']) ?></span>
                </div>
                <div class="d-flex justify-content-between mt-1">
                    <span class="text-muted">Ongkir</span>
                    <span class="fw-bold"><?= formatRupiah(0) ?></span>
                </div>
                <hr>
                <div class="d-flex justify-content-between fw-bold fs-5">
                    <span>Total</span>
                    <span class="text-danger"><?= formatRupiah($order['total_amount']) ?></span>
                </div>
                
                <div class="mt-3">
                    <div class="small text-muted">
                        <i class="bi bi-shop me-1"></i> Restoran: <?= sanitize($order['seller_name']) ?>
                    </div>
                    <div class="small text-muted">
                        <i class="bi bi-geo-alt me-1"></i> Alamat: <?= sanitize($order['shipping_address']) ?>
                    </div>
                    <?php if ($order['payment_status']): ?>
                    <div class="small text-muted mt-1">
                        <i class="bi bi-credit-card me-1"></i> Status Bayar: 
                        <span class="badge bg-<?= $order['payment_status'] === 'verified' ? 'success' : ($order['payment_status'] === 'uploaded' ? 'info' : 'warning') ?>">
                            <?= $order['payment_status'] ?>
                        </span>
                    </div>
                    <?php endif; ?>
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
// =============================================
// KONFIGURASI
// =============================================
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const ORDER_ID = parseInt(document.querySelector('meta[name="order-id"]').getAttribute('content'));

// =============================================
// REAL-TIME TRACKING (Polling setiap 10 detik)
// =============================================
let trackingInterval = null;

function fetchTracking() {
    fetch(BASE_URL + 'controllers/TrackingController.php?action=get_tracking&order_id=' + ORDER_ID)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                updateTrackingUI(data);
                
                // Stop polling jika status sudah final
                if (data.order.status === 'delivered' || data.order.status === 'cancelled') {
                    if (trackingInterval) {
                        clearInterval(trackingInterval);
                        trackingInterval = null;
                        document.getElementById('refreshStatus').innerHTML = '<i class="bi bi-check-circle text-success"></i> Selesai';
                    }
                }
            } else {
                console.warn('Tracking error:', data.message);
            }
        })
        .catch(err => console.error('Tracking fetch error:', err));
}

function updateTrackingUI(data) {
    const order = data.order;
    const steps = data.steps;
    
    // Update status badge
    const statusBadge = document.querySelector('.status-badge');
    const statusConfig = {
        'pending': { label: 'Menunggu Konfirmasi', color: 'warning', icon: 'bi-clock' },
        'confirmed': { label: 'Dikonfirmasi', color: 'info', icon: 'bi-check-circle' },
        'cooking': { label: 'Sedang Dimasak', color: 'primary', icon: 'bi-fire' },
        'on_delivery': { label: 'Sedang Diantar', color: 'success', icon: 'bi-truck' },
        'delivered': { label: 'Telah Sampai', color: 'success', icon: 'bi-check-circle-fill' },
        'cancelled': { label: 'Dibatalkan', color: 'danger', icon: 'bi-x-circle' }
    };
    const config = statusConfig[order.status] || statusConfig['pending'];
    statusBadge.className = `status-badge bg-${config.color} text-white`;
    statusBadge.innerHTML = `<i class="${config.icon} me-1"></i>${config.label}`;
    
    // Update steps
    const stepElements = document.querySelectorAll('.step');
    stepElements.forEach((el, index) => {
        const isDone = steps[index] && steps[index].is_done;
        const isCurrent = steps[index] && steps[index].is_current;
        const isCancelled = data.is_cancelled;
        
        el.className = 'step';
        if (isCancelled) {
            el.classList.add('cancelled');
        } else if (isCurrent) {
            el.classList.add('active');
        } else if (isDone) {
            el.classList.add('done');
        }
    });
    
    // Update driver info
    const driverSection = document.querySelector('.driver-card');
    if (driverSection) {
        if (order.driver_name) {
            driverSection.style.display = 'block';
            driverSection.querySelector('.fw-bold').textContent = order.driver_name;
            const phone = driverSection.querySelector('.text-muted');
            if (phone) phone.innerHTML = `<i class="bi bi-telephone me-1"></i> ${order.driver_phone || '-'}`;
        } else {
            driverSection.style.display = 'none';
        }
    }
    
    // Update refresh time
    const refreshEl = document.getElementById('refreshStatus');
    if (refreshEl && !refreshEl.innerHTML.includes('Selesai')) {
        refreshEl.innerHTML = `<i class="bi bi-arrow-repeat"></i> ${new Date().toLocaleTimeString()}`;
    }
}

// =============================================
// START TRACKING
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    // Fetch pertama kali
    fetchTracking();
    
    // Start polling setiap 10 detik
    if (trackingInterval) clearInterval(trackingInterval);
    trackingInterval = setInterval(fetchTracking, 10000);
});

// =============================================
// LOGOUT
// =============================================
function handleLogout(event) {
    event.preventDefault();
    if (!confirm('Yakin ingin logout?')) return;
    
    if (trackingInterval) clearInterval(trackingInterval);
    
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