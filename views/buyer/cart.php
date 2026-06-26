<?php
/**
 * cart.php
 * Halaman keranjang belanja untuk buyer
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

// Hitung subtotal
$subtotal = 0;
foreach ($cartItems as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

// Ambil flash message
$flash = getFlash();

// CSRF Token untuk JS
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keranjang Belanja - FoodDelivery</title>
    
    <!-- Meta untuk JS -->
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    
    <style>
        .cart-item {
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            overflow: hidden;
        }
        .cart-item:hover {
            border-color: #dc3545;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.08);
        }
        .cart-item-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
        }
        .cart-item-unavailable {
            opacity: 0.6;
            background-color: #f8f9fa;
        }
        .quantity-control {
            display: inline-flex;
            align-items: center;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
        .quantity-control button {
            width: 36px;
            height: 36px;
            border: none;
            background: #f8f9fa;
            font-size: 18px;
            font-weight: bold;
            color: #333;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .quantity-control button:hover:not(:disabled) {
            background: #dc3545;
            color: white;
        }
        .quantity-control button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .quantity-control input {
            width: 50px;
            height: 36px;
            border: none;
            border-left: 1px solid #dee2e6;
            border-right: 1px solid #dee2e6;
            text-align: center;
            font-weight: 600;
            font-size: 16px;
            background: white;
        }
        .quantity-control input:focus {
            outline: none;
        }
        /* Hapus spinner di input number */
        .quantity-control input::-webkit-outer-spin-button,
        .quantity-control input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .quantity-control input[type="number"] {
            -moz-appearance: textfield;
            appearance: textfield;
        }
        .order-summary {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            position: sticky;
            top: 80px;
        }
        .order-summary .divider {
            border-top: 1px dashed #dee2e6;
            margin: 16px 0;
        }
        .btn-checkout {
            padding: 14px;
            font-weight: 700;
            font-size: 16px;
            border-radius: 12px;
            transition: all 0.3s;
        }
        .btn-checkout:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.3);
        }
        .btn-checkout:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .remove-btn {
            color: #dc3545;
            background: none;
            border: none;
            padding: 0;
            font-size: 14px;
            transition: color 0.2s;
            text-decoration: underline;
        }
        .remove-btn:hover {
            color: #b02a37;
        }
        .empty-cart-icon {
            font-size: 80px;
            color: #dee2e6;
        }
        .item-subtotal {
            font-weight: 700;
            color: #dc3545;
            font-size: 16px;
        }
        .badge-stock {
            font-size: 11px;
            padding: 4px 10px;
        }
        .cart-item-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        @media (max-width: 768px) {
            .cart-item-image {
                width: 70px;
                height: 70px;
            }
            .cart-item {
                padding: 12px !important;
            }
            .quantity-control input {
                width: 40px;
            }
            .quantity-control button {
                width: 32px;
                height: 32px;
                font-size: 16px;
            }
            .order-summary {
                position: relative;
                top: 0;
                margin-top: 20px;
            }
            .cart-item-actions {
                flex-direction: column;
                align-items: flex-start;
                width: 100%;
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
                    <a class="nav-link" href="<?= BASE_URL ?>views/buyer/orders.php">
                        <i class="bi bi-bag"></i> Pesanan
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link position-relative" href="<?= BASE_URL ?>views/buyer/cart.php">
                        <i class="bi bi-cart3 fs-5"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger cart-badge" id="cartBadge">
                            <?= count($cartItems) ?>
                        </span>
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= sanitize(getUser()['name'] ?? 'User') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Profil</a></li>
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
        
        <!-- ============ CART ITEMS ============ -->
        <div class="col-lg-8">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="fw-bold mb-0">
                    <i class="bi bi-cart3 text-danger"></i> Keranjang Belanja
                    <span class="badge bg-secondary ms-2" id="itemCount"><?= count($cartItems) ?> item</span>
                </h4>
                <?php if (!empty($cartItems)): ?>
                <button class="btn btn-outline-danger btn-sm" onclick="clearCart()">
                    <i class="bi bi-trash"></i> Kosongkan
                </button>
                <?php endif; ?>
            </div>

            <?php if (empty($cartItems)): ?>
            <!-- ============ EMPTY STATE ============ -->
            <div class="text-center py-5 bg-white rounded-4 border">
                <div class="empty-cart-icon mb-4">
                    <i class="bi bi-cart-x"></i>
                </div>
                <h5 class="fw-bold">Keranjang Kosong</h5>
                <p class="text-muted">Belum ada menu yang ditambahkan ke keranjang.</p>
                <a href="<?= BASE_URL ?>views/public/menu.php" class="btn btn-danger mt-2">
                    <i class="bi bi-arrow-left me-2"></i> Mulai Belanja
                </a>
            </div>
            <?php else: ?>
            <!-- ============ CART ITEMS LIST ============ -->
            <div id="cartItemsContainer">
                <?php foreach ($cartItems as $item): 
                    $isAvailable = ($item['is_active'] == 1 && $item['stock'] > 0);
                    $subtotalItem = $item['price'] * $item['quantity'];
                ?>
                <div class="cart-item p-3 mb-3 <?= !$isAvailable ? 'cart-item-unavailable' : '' ?>" 
                     id="cart-row-<?= $item['cart_id'] ?>">
                    <div class="row g-3 align-items-center">
                        <!-- Image -->
                        <div class="col-auto">
                            <img src="<?= $item['image'] ? UPLOAD_URL . $item['image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                                 alt="<?= sanitize($item['name']) ?>"
                                 class="cart-item-image"
                                 onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                        </div>
                        
                        <!-- Info -->
                        <div class="col">
                            <h6 class="fw-bold mb-1"><?= sanitize($item['name']) ?></h6>
                            <div class="text-muted small">
                                <i class="bi bi-shop me-1"></i><?= sanitize($item['seller_name']) ?>
                            </div>
                            <div class="text-danger fw-semibold mt-1">
                                <?= formatRupiah($item['price']) ?>
                            </div>
                            
                            <?php if (!$isAvailable): ?>
                            <div class="mt-2">
                                <span class="badge bg-warning text-dark">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    <?= $item['is_active'] == 0 ? 'Produk tidak tersedia' : 'Stok habis' ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Actions -->
                        <div class="col-auto">
                            <div class="cart-item-actions">
                                <!-- Quantity Control -->
                                <?php if ($isAvailable): ?>
                                <div class="quantity-control" data-cart-id="<?= $item['cart_id'] ?>">
                                    <button onclick="updateQty(<?= $item['cart_id'] ?>, -1)" 
                                            class="qty-btn" 
                                            <?= $item['quantity'] <= 1 ? 'disabled' : '' ?>>
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <input type="number" 
                                           id="qty-<?= $item['cart_id'] ?>" 
                                           value="<?= $item['quantity'] ?>" 
                                           min="1" 
                                           max="<?= $item['stock'] ?>"
                                           onchange="updateQtyInput(<?= $item['cart_id'] ?>, this.value)">
                                    <button onclick="updateQty(<?= $item['cart_id'] ?>, 1)" 
                                            class="qty-btn"
                                            <?= $item['quantity'] >= $item['stock'] ? 'disabled' : '' ?>>
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Subtotal -->
                                <div class="item-subtotal" id="subtotal-<?= $item['cart_id'] ?>">
                                    <?= formatRupiah($subtotalItem) ?>
                                </div>
                                
                                <!-- Remove -->
                                <button class="remove-btn" onclick="removeItem(<?= $item['cart_id'] ?>)">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ============ ORDER SUMMARY ============ -->
        <div class="col-lg-4">
            <div class="order-summary" id="orderSummary">
                <h5 class="fw-bold mb-3">
                    <i class="bi bi-receipt text-danger"></i> Ringkasan Pesanan
                </h5>
                
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Subtotal</span>
                    <span class="fw-bold" id="subtotalDisplay"><?= formatRupiah($subtotal) ?></span>
                </div>
                
                <div class="d-flex justify-content-between mt-2">
                    <span class="text-muted">Ongkir</span>
                    <span class="text-muted">Dihitung saat checkout</span>
                </div>
                
                <div class="divider"></div>
                
                <div class="d-flex justify-content-between fw-bold fs-5">
                    <span>Total</span>
                    <span class="text-danger" id="totalDisplay"><?= formatRupiah($subtotal) ?></span>
                </div>
                
                <div class="mt-3">
                    <a href="<?= BASE_URL ?>views/buyer/checkout.php" 
                       class="btn btn-danger btn-checkout w-100 <?= empty($cartItems) ? 'disabled' : '' ?>"
                       id="checkoutBtn">
                        <i class="bi bi-arrow-right-circle me-2"></i> Lanjut ke Checkout
                    </a>
                </div>
                
                <div class="mt-3 text-center">
                    <a href="<?= BASE_URL ?>views/public/menu.php" class="text-danger small">
                        <i class="bi bi-plus-circle me-1"></i> Tambah menu lagi
                    </a>
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

// =============================================
// UPDATE QUANTITY
// =============================================
function updateQty(cartId, change) {
    const input = document.getElementById('qty-' + cartId);
    let newQty = parseInt(input.value) + change;
    
    if (newQty < 1) newQty = 1;
    if (newQty > parseInt(input.max)) {
        showToast('Stok tidak mencukupi', 'warning');
        return;
    }
    
    updateQtyInput(cartId, newQty);
}

function updateQtyInput(cartId, newQty) {
    const input = document.getElementById('qty-' + cartId);
    const oldQty = parseInt(input.value);
    
    if (newQty < 1) newQty = 1;
    if (newQty > parseInt(input.max)) {
        showToast('Stok tidak mencukupi', 'warning');
        newQty = parseInt(input.max);
    }
    
    input.value = newQty;
    
    // Jika tidak berubah, skip
    if (newQty === oldQty) return;
    
    // Disable buttons
    const container = input.closest('.quantity-control');
    container.querySelectorAll('button').forEach(btn => btn.disabled = true);
    
    fetch(BASE_URL + 'controllers/CartController.php?action=update', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            cart_id: cartId,
            quantity: newQty,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Update subtotal item
            document.getElementById('subtotal-' + cartId).textContent = 
                formatRupiah(data.item_subtotal);
            
            // Update total
            document.getElementById('subtotalDisplay').textContent = 
                formatRupiah(data.cart_total);
            document.getElementById('totalDisplay').textContent = 
                formatRupiah(data.cart_total);
            
            // Update badge
            document.getElementById('cartBadge').textContent = data.cart_count;
            
            // Update button states
            updateButtonStates(cartId, newQty, input.max);
            
            showToast('Jumlah berhasil diupdate', 'success');
        } else {
            showToast('❌ ' + data.message, 'danger');
            input.value = oldQty;
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showToast('❌ Terjadi kesalahan', 'danger');
        input.value = oldQty;
    })
    .finally(() => {
        container.querySelectorAll('button').forEach(btn => btn.disabled = false);
    });
}

function updateButtonStates(cartId, qty, max) {
    const container = document.querySelector(`.quantity-control[data-cart-id="${cartId}"]`);
    if (!container) return;
    
    const btns = container.querySelectorAll('button');
    const minusBtn = btns[0];
    const plusBtn = btns[1];
    
    minusBtn.disabled = qty <= 1;
    plusBtn.disabled = qty >= parseInt(max);
}

// =============================================
// REMOVE ITEM
// =============================================
function removeItem(cartId) {
    if (!confirm('Hapus item ini dari keranjang?')) return;
    
    fetch(BASE_URL + 'controllers/CartController.php?action=remove', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            cart_id: cartId,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Remove row with animation
            const row = document.getElementById('cart-row-' + cartId);
            row.style.transition = 'all 0.3s';
            row.style.opacity = '0';
            row.style.transform = 'translateX(-20px)';
            setTimeout(() => row.remove(), 300);
            
            // Update totals
            document.getElementById('subtotalDisplay').textContent = 
                formatRupiah(data.cart_total);
            document.getElementById('totalDisplay').textContent = 
                formatRupiah(data.cart_total);
            
            // Update badge
            document.getElementById('cartBadge').textContent = data.cart_count;
            
            // Update item count
            const countEl = document.getElementById('itemCount');
            const currentCount = parseInt(countEl.textContent);
            countEl.textContent = (currentCount - 1) + ' item';
            
            // Check if cart is empty
            if (data.cart_count === 0) {
                location.reload();
            }
            
            showToast('Item berhasil dihapus', 'success');
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
// CLEAR CART
// =============================================
function clearCart() {
    if (!confirm('Kosongkan semua item di keranjang?')) return;
    
    // Hapus semua item satu per satu (atau reload page)
    const rows = document.querySelectorAll('.cart-item');
    let deleted = 0;
    
    rows.forEach(row => {
        const cartId = row.id.replace('cart-row-', '');
        fetch(BASE_URL + 'controllers/CartController.php?action=remove', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                cart_id: cartId,
                csrf_token: CSRF_TOKEN
            })
        })
        .then(res => res.json())
        .then(data => {
            deleted++;
            if (deleted === rows.length) {
                location.reload();
            }
        });
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
    setTimeout(() => t.remove(), 3500);
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
// UPDATE CART BADGE
// =============================================
function updateCartBadge() {
    fetch(BASE_URL + 'controllers/CartController.php?action=get_count')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const badge = document.getElementById('cartBadge');
            if (badge) {
                badge.textContent = data.cart_count;
            }
        }
    })
    .catch(err => console.error('Cart badge error:', err));
}

// Auto refresh badge setiap 30 detik
setInterval(updateCartBadge, 30000);

// =============================================
// INIT: Update button states
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.quantity-control').forEach(container => {
        const input = container.querySelector('input');
        const cartId = container.dataset.cartId;
        const qty = parseInt(input.value);
        const max = parseInt(input.max);
        updateButtonStates(cartId, qty, max);
    });
});
</script>

</body>
</html>