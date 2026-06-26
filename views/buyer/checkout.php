<?php
/**
 * checkout.php
 * Halaman checkout untuk buyer
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
$user = getUser();
$db = getDB();

// Ambil cart items
$stmt = $db->prepare("
    SELECT 
        c.id as cart_id,
        c.quantity,
        p.id as product_id,
        p.name,
        p.price,
        p.image,
        p.stock,
        p.is_active,
        u.id as seller_id,
        u.name as seller_name
    FROM cart c
    JOIN products p ON c.product_id = p.id
    JOIN users u ON p.seller_id = u.id
    WHERE c.user_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$userId]);
$cartItems = $stmt->fetchAll();

// Cek cart kosong
if (empty($cartItems)) {
    setFlash('warning', 'Keranjang belanja kosong. Silakan tambahkan menu terlebih dahulu.');
    redirect(BASE_URL . 'views/public/menu.php');
}

// Cek stok
$stockIssues = [];
foreach ($cartItems as $item) {
    if ($item['is_active'] == 0 || $item['stock'] <= 0) {
        $stockIssues[] = $item['name'];
    }
    if ($item['quantity'] > $item['stock']) {
        $stockIssues[] = $item['name'] . ' (stok tersisa ' . $item['stock'] . ')';
    }
}

if (!empty($stockIssues)) {
    setFlash('danger', 'Ada masalah dengan stok: ' . implode(', ', $stockIssues) . '. Silakan periksa kembali keranjang Anda.');
    redirect(BASE_URL . 'views/buyer/cart.php');
}

// Hitung subtotal
$subtotal = 0;
foreach ($cartItems as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

// CSRF Token
$csrfToken = generateCsrfToken();

// Flash message
$flash = getFlash();

// Data user untuk form
$userName = $user['name'] ?? '';
$userEmail = $user['email'] ?? '';
$userPhone = $user['phone'] ?? '';
$userAddress = $user['address'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - FoodDelivery</title>
    
    <!-- Meta untuk JS -->
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    
    <style>
        .checkout-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f1f3f5;
        }
        .checkout-item:last-child {
            border-bottom: none;
        }
        .checkout-item-img {
            width: 56px;
            height: 56px;
            object-fit: cover;
            border-radius: 8px;
            flex-shrink: 0;
        }
        .checkout-item-info {
            flex: 1;
        }
        .checkout-item-info .name {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }
        .checkout-item-info .qty {
            font-size: 13px;
            color: #6c757d;
        }
        .checkout-item-price {
            font-weight: 700;
            color: #dc3545;
            font-size: 14px;
            white-space: nowrap;
        }
        .order-summary-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            position: sticky;
            top: 80px;
        }
        .order-summary-card .divider {
            border-top: 1px dashed #dee2e6;
            margin: 16px 0;
        }
        .payment-option {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 14px 18px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .payment-option:hover {
            border-color: #dc3545;
        }
        .payment-option.active {
            border-color: #dc3545;
            background: #fff5f5;
        }
        .payment-option input[type="radio"] {
            display: none;
        }
        .payment-option .icon {
            font-size: 28px;
            width: 40px;
            text-align: center;
        }
        .payment-option .label {
            font-weight: 600;
            font-size: 14px;
        }
        .payment-option .desc {
            font-size: 12px;
            color: #6c757d;
        }
        .shipping-option {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 16px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .shipping-option:hover {
            border-color: #dc3545;
        }
        .shipping-option.active {
            border-color: #dc3545;
            background: #fff5f5;
        }
        .shipping-option input[type="radio"] {
            display: none;
        }
        .shipping-option .name {
            font-weight: 600;
            font-size: 14px;
        }
        .shipping-option .price {
            font-weight: 700;
            color: #dc3545;
        }
        .shipping-option .eta {
            font-size: 12px;
            color: #6c757d;
        }
        .btn-place-order {
            padding: 14px;
            font-weight: 700;
            font-size: 16px;
            border-radius: 12px;
            transition: all 0.3s;
        }
        .btn-place-order:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.3);
        }
        .btn-place-order:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .shipping-loader {
            display: none;
            align-items: center;
            gap: 8px;
            color: #6c757d;
        }
        .shipping-loader.active {
            display: flex;
        }
        .city-input-wrapper {
            position: relative;
        }
        .city-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        .city-suggestions.show {
            display: block;
        }
        .city-suggestions .suggestion-item {
            padding: 8px 16px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .city-suggestions .suggestion-item:hover {
            background: #f8f9fa;
        }
        @media (max-width: 768px) {
            .order-summary-card {
                position: relative;
                top: 0;
                margin-top: 20px;
            }
            .checkout-item {
                flex-wrap: wrap;
            }
            .checkout-item-price {
                margin-left: auto;
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
                    <a class="nav-link active" href="<?= BASE_URL ?>views/buyer/cart.php">
                        <i class="bi bi-arrow-left"></i> Kembali
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= sanitize($userName) ?>
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
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<!-- ============ MAIN CONTENT ============ -->
<div class="container py-4">
    <div class="row g-4">
        
        <!-- ============ CHECKOUT FORM ============ -->
        <div class="col-lg-8">
            <h4 class="fw-bold mb-4">
                <i class="bi bi-clipboard-check text-danger"></i> Detail Pengiriman
            </h4>
            
            <form id="checkoutForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="shipping_cost" id="shippingCost" value="0">
                <input type="hidden" name="shipping_method" id="shippingMethod" value="">
                
                <!-- === FORM PENGIRIMAN === -->
                <div class="bg-white p-4 rounded-4 border mb-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-person me-2"></i>Data Penerima</h6>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" 
                                   value="<?= sanitize($userName) ?>" required>
                            <div class="invalid-feedback">Nama wajib diisi</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?= sanitize($userEmail) ?>" required>
                            <div class="invalid-feedback">Email wajib diisi</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nomor HP <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" 
                                   value="<?= sanitize($userPhone) ?>" required>
                            <div class="invalid-feedback">Nomor HP wajib diisi</div>
                        </div>
                    </div>
                </div>
                
                <!-- === ALAMAT === -->
                <div class="bg-white p-4 rounded-4 border mb-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-geo-alt me-2"></i>Alamat Pengiriman</h6>
                    
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Alamat Lengkap <span class="text-danger">*</span></label>
                            <textarea name="shipping_address" class="form-control" rows="2" required><?= sanitize($userAddress) ?></textarea>
                            <div class="invalid-feedback">Alamat wajib diisi</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Kota <span class="text-danger">*</span></label>
                            <div class="city-input-wrapper">
                                <input type="text" name="city" id="cityInput" class="form-control" 
                                       placeholder="Cth: Jakarta, Bandung" required>
                                <div class="city-suggestions" id="citySuggestions"></div>
                            </div>
                            <div class="invalid-feedback">Kota wajib diisi</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Provinsi</label>
                            <input type="text" name="province" class="form-control" placeholder="Cth: DKI Jakarta">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Kode Pos</label>
                            <input type="text" name="postal_code" class="form-control" placeholder="12345">
                        </div>
                    </div>
                    
                    <!-- ============ SHIPPING OPTIONS ============ -->
                    <div class="mt-3" id="shippingOptionsContainer">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="fw-semibold">Metode Pengiriman</label>
                            <div class="shipping-loader" id="shippingLoader">
                                <span class="spinner-border spinner-border-sm" role="status"></span>
                                Menghitung ongkir...
                            </div>
                        </div>
                        <div id="shippingOptions" class="d-flex flex-column gap-2">
                            <div class="text-muted small">
                                <i class="bi bi-info-circle me-1"></i>
                                Masukkan kota untuk melihat pilihan pengiriman
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ============================================================ -->
                <!-- ============ METODE PEMBAYARAN ============ -->
                <!-- ============================================================ -->
                <div class="bg-white p-4 rounded-4 border mb-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-credit-card me-2"></i>Metode Pembayaran</h6>
                    
                    <div class="row g-3">
                        <!-- COD -->
                        <div class="col-md-4">
                            <label class="payment-option active" id="payment-cod" onclick="selectPayment('cod')">
                                <input type="radio" name="payment_method" value="cod" checked>
                                <span class="icon"><i class="bi bi-cash"></i></span>
                                <div>
                                    <div class="label">COD</div>
                                    <div class="desc">Bayar saat terima</div>
                                </div>
                            </label>
                        </div>
                        
                        <!-- Transfer Bank -->
                        <div class="col-md-4">
                            <label class="payment-option" id="payment-bank" onclick="selectPayment('bank_transfer')">
                                <input type="radio" name="payment_method" value="bank_transfer">
                                <span class="icon"><i class="bi bi-bank"></i></span>
                                <div>
                                    <div class="label">Transfer Bank</div>
                                    <div class="desc">BCA, Mandiri, BRI</div>
                                </div>
                            </label>
                        </div>
                        
                        <!-- E-Wallet -->
                        <div class="col-md-4">
                            <label class="payment-option" id="payment-ewallet" onclick="selectPayment('ewallet')">
                                <input type="radio" name="payment_method" value="ewallet">
                                <span class="icon"><i class="bi bi-wallet2"></i></span>
                                <div>
                                    <div class="label">E-Wallet</div>
                                    <div class="desc">Gopay, OVO, DANA</div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- === TERMS === -->
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="terms" required>
                    <label class="form-check-label small" for="terms">
                        Saya menyetujui <a href="#" class="text-danger">syarat dan ketentuan</a> yang berlaku
                    </label>
                    <div class="invalid-feedback">Anda harus menyetujui syarat dan ketentuan</div>
                </div>
                
                <button type="submit" class="btn btn-danger btn-place-order w-100" id="placeOrderBtn">
                    <i class="bi bi-check-circle me-2"></i> Buat Pesanan
                </button>
            </form>
        </div>
        
        <!-- ============ ORDER SUMMARY ============ -->
        <div class="col-lg-4">
            <div class="order-summary-card">
                <h5 class="fw-bold mb-3">
                    <i class="bi bi-receipt text-danger"></i> Ringkasan Pesanan
                </h5>
                
                <!-- Items -->
                <div class="checkout-items mb-3" id="checkoutItems">
                    <?php foreach ($cartItems as $item): ?>
                    <div class="checkout-item">
                        <img src="<?= $item['image'] ? UPLOAD_URL . $item['image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                             alt="<?= sanitize($item['name']) ?>"
                             class="checkout-item-img"
                             onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                        <div class="checkout-item-info">
                            <div class="name"><?= sanitize($item['name']) ?></div>
                            <div class="qty"><?= $item['quantity'] ?>x</div>
                        </div>
                        <div class="checkout-item-price"><?= formatRupiah($item['price'] * $item['quantity']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="divider"></div>
                
                <!-- Subtotal -->
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Subtotal</span>
                    <span class="fw-bold" id="summarySubtotal"><?= formatRupiah($subtotal) ?></span>
                </div>
                
                <!-- Ongkir -->
                <div class="d-flex justify-content-between mt-2">
                    <span class="text-muted">Ongkir</span>
                    <span class="fw-bold" id="summaryShipping">Rp 0</span>
                </div>
                
                <div class="divider"></div>
                
                <!-- Total -->
                <div class="d-flex justify-content-between fw-bold fs-5">
                    <span>Total</span>
                    <span class="text-danger" id="summaryTotal"><?= formatRupiah($subtotal) ?></span>
                </div>
                
                <!-- Estimasi -->
                <div class="mt-2 text-end">
                    <small class="text-muted" id="summaryEta">-</small>
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

<!-- ============ SCRIPTS ============ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// =============================================
// KONFIGURASI
// =============================================
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const subtotal = <?= $subtotal ?>;

// =============================================
// CITY AUTO-COMPLETE
// =============================================
const cities = ['Jakarta', 'Bandung', 'Surabaya', 'Yogyakarta', 'Bali', 'Semarang', 'Medan', 'Makassar', 'Palembang', 'Tangerang', 'Bekasi', 'Depok'];

// =============================================
// SELECT PAYMENT METHOD
// =============================================
function selectPayment(method) {
    document.querySelectorAll('.payment-option').forEach(el => {
        el.classList.remove('active');
    });
    
    const target = document.getElementById('payment-' + method);
    if (target) {
        target.classList.add('active');
        target.querySelector('input[type="radio"]').checked = true;
    }
}

// =============================================
// SHIPPING CALCULATION
// =============================================
const shippingData = {
    'jakarta': { options: [
        { name: 'GoSend', cost: 12000, eta: '30-45 menit' },
        { name: 'GrabExpress', cost: 14000, eta: '25-40 menit' },
        { name: 'Reguler', cost: 8000, eta: '1-2 jam' }
    ]},
    'bandung': { options: [
        { name: 'GoSend', cost: 18000, eta: '1-2 jam' },
        { name: 'GrabExpress', cost: 20000, eta: '45-60 menit' },
        { name: 'Reguler', cost: 12000, eta: '2-3 jam' }
    ]},
    'surabaya': { options: [
        { name: 'GoSend', cost: 22000, eta: '2-3 jam' },
        { name: 'GrabExpress', cost: 25000, eta: '1.5-2 jam' },
        { name: 'Reguler', cost: 15000, eta: '3-4 jam' }
    ]},
    'yogyakarta': { options: [
        { name: 'GoSend', cost: 16000, eta: '1-1.5 jam' },
        { name: 'GrabExpress', cost: 18000, eta: '45-60 menit' },
        { name: 'Reguler', cost: 10000, eta: '2-3 jam' }
    ]},
    'bali': { options: [
        { name: 'GoSend', cost: 25000, eta: '3-4 jam' },
        { name: 'GrabExpress', cost: 28000, eta: '2-3 jam' },
        { name: 'Reguler', cost: 18000, eta: '4-5 jam' }
    ]}
};

const defaultShipping = {
    options: [
        { name: 'GoSend', cost: 15000, eta: '1-2 jam' },
        { name: 'GrabExpress', cost: 17000, eta: '45-60 menit' },
        { name: 'Reguler', cost: 10000, eta: '2-3 jam' }
    ]
};

let selectedShippingCost = 0;
let selectedShippingMethod = '';

function calculateShipping(city) {
    console.log('calculateShipping dipanggil dengan:', city);
    
    const loader = document.getElementById('shippingLoader');
    const container = document.getElementById('shippingOptions');
    
    if (!city || city.length < 3) {
        container.innerHTML = `
            <div class="text-muted small">
                <i class="bi bi-info-circle me-1"></i>
                Masukkan minimal 3 karakter kota
            </div>
        `;
        return;
    }
    
    loader.classList.add('active');
    container.innerHTML = '';
    
    setTimeout(function() {
        const cityKey = city.toLowerCase();
        let data = defaultShipping;
        
        for (const [key, val] of Object.entries(shippingData)) {
            if (cityKey.includes(key) || key.includes(cityKey)) {
                data = val;
                break;
            }
        }
        
        container.innerHTML = data.options.map(function(opt, index) {
            return `
                <label class="shipping-option ${index === 0 ? 'active' : ''}" 
                       onclick="selectShipping(this, '${opt.name}', ${opt.cost}, '${opt.eta}')">
                    <input type="radio" name="shipping_option" value="${opt.name}" ${index === 0 ? 'checked' : ''}>
                    <div>
                        <div class="name">${opt.name}</div>
                        <div class="eta">Estimasi: ${opt.eta}</div>
                    </div>
                    <div class="price">${formatRupiah(opt.cost)}</div>
                </label>
            `;
        }).join('');
        
        const firstOption = data.options[0];
        if (firstOption) {
            selectedShippingCost = firstOption.cost;
            selectedShippingMethod = firstOption.name;
            updateSummary(firstOption.cost);
            document.getElementById('shippingCost').value = firstOption.cost;
            document.getElementById('shippingMethod').value = firstOption.name;
            document.getElementById('summaryEta').textContent = 'Estimasi: ' + firstOption.eta;
        }
        
        loader.classList.remove('active');
    }, 500);
}

function selectShipping(el, name, cost, eta) {
    document.querySelectorAll('.shipping-option').forEach(function(opt) {
        opt.classList.remove('active');
    });
    el.classList.add('active');
    
    selectedShippingCost = cost;
    selectedShippingMethod = name;
    
    document.getElementById('shippingCost').value = cost;
    document.getElementById('shippingMethod').value = name;
    document.getElementById('summaryEta').textContent = 'Estimasi: ' + eta;
    
    updateSummary(cost);
}

function updateSummary(shippingCost) {
    const total = subtotal + shippingCost;
    document.getElementById('summaryShipping').textContent = formatRupiah(shippingCost);
    document.getElementById('summaryTotal').textContent = formatRupiah(total);
}

// =============================================
// TRIGGER SHIPPING MANUAL
// =============================================
function triggerShipping() {
    const cityInput = document.getElementById('cityInput');
    const city = cityInput.value.trim();
    if (city && city.length >= 3) {
        calculateShipping(city);
    }
}

// =============================================
// CITY AUTO-COMPLETE EVENTS
// =============================================
document.getElementById('cityInput').addEventListener('input', function() {
    const value = this.value;
    const suggestions = document.getElementById('citySuggestions');
    
    if (value.length < 2) {
        suggestions.classList.remove('show');
        return;
    }
    
    const matches = cities.filter(function(city) {
        return city.toLowerCase().includes(value.toLowerCase());
    });
    
    if (matches.length === 0) {
        suggestions.classList.remove('show');
        return;
    }
    
    suggestions.innerHTML = matches.map(function(city) {
        return '<div class="suggestion-item" onclick="selectCity(\'' + city + '\')">' + city + '</div>';
    }).join('');
    suggestions.classList.add('show');
});

document.getElementById('cityInput').addEventListener('blur', function() {
    triggerShipping();
});

document.getElementById('cityInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        triggerShipping();
    }
});

function selectCity(city) {
    document.getElementById('cityInput').value = city;
    document.getElementById('citySuggestions').classList.remove('show');
    calculateShipping(city);
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.city-input-wrapper')) {
        document.getElementById('citySuggestions').classList.remove('show');
    }
});

// =============================================
// FORM SUBMIT
// =============================================
document.getElementById('checkoutForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (!this.checkValidity()) {
        this.classList.add('was-validated');
        return;
    }
    
    if (!selectedShippingMethod) {
        showToast('❌ Silakan pilih metode pengiriman terlebih dahulu', 'danger');
        return;
    }
    
    if (!document.getElementById('terms').checked) {
        showToast('❌ Anda harus menyetujui syarat dan ketentuan', 'danger');
        return;
    }
    
    const btn = document.getElementById('placeOrderBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Memproses...';
    
    const formData = new FormData(this);
    
    fetch(BASE_URL + 'controllers/OrderController.php?action=checkout', {
        method: 'POST',
        body: formData
    })
    .then(function(res) {
        return res.json();
    })
    .then(function(data) {
        if (data.success) {
            showToast('✅ ' + data.message, 'success');
            setTimeout(function() {
                window.location.href = data.redirect;
            }, 1500);
        } else {
            showToast('❌ ' + data.message, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-2"></i> Buat Pesanan';
        }
    })
    .catch(function(err) {
        console.error('Error:', err);
        showToast('❌ Terjadi kesalahan. Silakan coba lagi.', 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-2"></i> Buat Pesanan';
    });
});

// =============================================
// TOAST NOTIFICATION
// =============================================
function showToast(msg, type) {
    if (type === undefined) type = 'success';
    var wrap = document.getElementById('toast-wrap');
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'toast-wrap';
        wrap.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;max-width:380px;';
        document.body.appendChild(wrap);
    }
    var t = document.createElement('div');
    var bgColor = type === 'success' ? 'text-bg-success' : 
                  type === 'warning' ? 'text-bg-warning' : 
                  'text-bg-danger';
    t.className = 'toast align-items-center ' + bgColor + ' border-0 show mb-2';
    t.style.borderRadius = '12px';
    t.innerHTML = '<div class="d-flex"><div class="toast-body fw-semibold">' + msg + '</div><button class="btn-close btn-close-white me-2 m-auto" onclick="this.closest(\'.toast\').remove()"></button></div>';
    wrap.appendChild(t);
    setTimeout(function() {
        t.remove();
    }, 5000);
}

// =============================================
// FORMAT RUPIAH
// =============================================
function formatRupiah(number) {
    if (typeof number === 'string') {
        number = parseFloat(number) || 0;
    }
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(number);
}

// =============================================
// LOGOUT
// =============================================
function handleLogout(event) {
    event.preventDefault();
    if (!confirm('Yakin ingin logout?')) return;
    
    fetch(BASE_URL + 'controllers/AuthController.php?action=logout')
    .then(function(res) {
        return res.json();
    })
    .then(function(data) {
        if (data.success) {
            window.location.href = data.redirect;
        } else {
            alert('Gagal logout');
        }
    })
    .catch(function(err) {
        console.error('Error:', err);
        alert('Terjadi kesalahan');
    });
}

// =============================================
// INIT - AUTO DETECT CITY FROM ADDRESS
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    // Default payment method (COD)
    selectPayment('cod');
    
    var cityInput = document.getElementById('cityInput');
    var address = '<?= addslashes($userAddress) ?>';
    
    if (address) {
        var parts = address.split(',').map(function(s) { return s.trim(); });
        var foundCity = false;
        
        for (var i = 0; i < parts.length; i++) {
            for (var j = 0; j < cities.length; j++) {
                if (parts[i].toLowerCase().includes(cities[j].toLowerCase())) {
                    cityInput.value = cities[j];
                    foundCity = true;
                    setTimeout(function() {
                        calculateShipping(cities[j]);
                    }, 600);
                    break;
                }
            }
            if (foundCity) break;
        }
        
        if (!foundCity) {
            var words = address.split(' ');
            for (var k = 0; k < words.length; k++) {
                for (var l = 0; l < cities.length; l++) {
                    if (words[k].toLowerCase().includes(cities[l].toLowerCase())) {
                        cityInput.value = cities[l];
                        foundCity = true;
                        setTimeout(function() {
                            calculateShipping(cities[l]);
                        }, 600);
                        break;
                    }
                }
                if (foundCity) break;
            }
        }
    }
    
    // === TAMBAHAN: Jika input kota sudah terisi, panggil shipping ===
    setTimeout(function() {
        if (cityInput.value && cityInput.value.length >= 3) {
            calculateShipping(cityInput.value);
        }
    }, 1000);
});
</script>

</body>
</html>