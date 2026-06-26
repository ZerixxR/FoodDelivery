/**
 * main.js
 * Fungsi global untuk semua halaman FoodDelivery
 * 
 * @package FoodDelivery
 * @version 1.0
 */

// =============================================
// KONFIGURASI
// =============================================

const BASE_URL = document.querySelector('meta[name="base-url"]')?.getAttribute('content') || 'http://localhost/fooddelivery/';
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

// =============================================
// CART FUNCTIONS
// =============================================

/**
 * Tambah produk ke keranjang
 * @param {number} productId - ID produk
 * @param {number} qty - Jumlah (default 1)
 */
function addToCart(productId, qty = 1) {
    const btn = event?.target?.closest?.('button');
    if (btn) {
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
    }

    fetch(BASE_URL + 'controllers/CartController.php?action=add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            product_id: productId,
            quantity: qty,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
        if (data.success) {
            showToast('✅ ' + data.message, 'success');
            updateCartBadge(data.cart_count);
        } else {
            showToast('❌ ' + data.message, 'danger');
        }
    })
    .catch(err => {
        console.error('Add to cart error:', err);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
        showToast('❌ Terjadi kesalahan', 'danger');
    });
}

/**
 * Update quantity di keranjang
 * @param {number} cartId - ID cart item
 * @param {number} qty - Quantity baru
 */
function updateCartQty(cartId, qty) {
    if (qty < 1) qty = 1;

    fetch(BASE_URL + 'controllers/CartController.php?action=update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            cart_id: cartId,
            quantity: qty,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Update subtotal item
            const subtotalEl = document.getElementById('subtotal-' + cartId);
            if (subtotalEl) subtotalEl.textContent = formatRupiah(data.item_subtotal);
            
            // Update total
            const totalEl = document.getElementById('cart-total');
            if (totalEl) totalEl.textContent = formatRupiah(data.cart_total);
            
            // Update badge
            updateCartBadge(data.cart_count);
        } else {
            showToast('❌ ' + data.message, 'danger');
        }
    })
    .catch(err => {
        console.error('Update cart error:', err);
        showToast('❌ Terjadi kesalahan', 'danger');
    });
}

/**
 * Hapus item dari keranjang
 * @param {number} cartId - ID cart item
 */
function removeFromCart(cartId) {
    if (!confirm('Hapus item ini dari keranjang?')) return;

    fetch(BASE_URL + 'controllers/CartController.php?action=remove', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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
            if (row) {
                row.style.transition = 'all 0.3s';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px)';
                setTimeout(() => row.remove(), 300);
            }
            
            // Update total
            const totalEl = document.getElementById('cart-total');
            if (totalEl) totalEl.textContent = formatRupiah(data.cart_total);
            
            // Update badge
            updateCartBadge(data.cart_count);
            
            showToast('✅ Item dihapus', 'success');
            
            // Check if cart is empty
            if (data.cart_count === 0) {
                setTimeout(() => location.reload(), 500);
            }
        } else {
            showToast('❌ ' + data.message, 'danger');
        }
    })
    .catch(err => {
        console.error('Remove from cart error:', err);
        showToast('❌ Terjadi kesalahan', 'danger');
    });
}

/**
 * Update semua badge cart di halaman
 * @param {number} count - Jumlah item di keranjang
 */
function updateCartBadge(count) {
    document.querySelectorAll('.cart-badge').forEach(el => {
        el.textContent = count || 0;
        el.style.display = (count && count > 0) ? '' : 'none';
    });
}

/**
 * Tombol +/- quantity di cart
 * @param {number} cartId - ID cart item
 * @param {number} delta - Perubahan (+1 atau -1)
 */
function changeQty(cartId, delta) {
    const input = document.getElementById('qty-' + cartId);
    if (!input) return;
    
    let newQty = parseInt(input.value) + delta;
    if (newQty < 1) newQty = 1;
    if (newQty > parseInt(input.max)) {
        showToast('Stok tidak mencukupi', 'warning');
        return;
    }
    
    input.value = newQty;
    updateCartQty(cartId, newQty);
}

// =============================================
// NOTIFICATION FUNCTIONS
// =============================================

let notificationPollingInterval = null;

/**
 * Load latest notifications
 */
function loadNotifications() {
    fetch(BASE_URL + 'controllers/NotificationController.php?action=get_latest&limit=5')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderNotifications(data.notifications);
                updateNotificationBadge(data.unread_count);
            }
        })
        .catch(err => console.error('Load notifications error:', err));
}

/**
 * Render notifications ke dropdown
 * @param {Array} notifications - List notifikasi
 */
function renderNotifications(notifications) {
    const container = document.getElementById('notification-list');
    if (!container) return;
    
    if (!notifications || notifications.length === 0) {
        container.innerHTML = `
            <li><a class="dropdown-item text-muted text-center" href="#">
                <i class="bi bi-bell-slash me-1"></i> Tidak ada notifikasi
            </a></li>
        `;
        return;
    }
    
    let html = '';
    notifications.forEach(notif => {
        const isRead = notif.is_read ? 'text-muted' : 'fw-semibold bg-light';
        html += `
            <li>
                <a class="dropdown-item ${isRead}" href="#" onclick="markNotificationRead(${notif.id})">
                    <div class="small fw-bold">${notif.title}</div>
                    <div class="small text-muted">${notif.message}</div>
                    <div class="text-muted" style="font-size: 10px;">${notif.time_ago || ''}</div>
                </a>
            </li>
        `;
    });
    html += `
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-center text-danger" href="#" onclick="markAllRead()">
            <i class="bi bi-check-all me-1"></i> Tandai semua sudah dibaca
        </a></li>
    `;
    container.innerHTML = html;
}

/**
 * Update badge notifikasi
 * @param {number} count - Jumlah notifikasi belum dibaca
 */
function updateNotificationBadge(count) {
    document.querySelectorAll('.notif-badge').forEach(el => {
        el.textContent = count || 0;
        el.style.display = (count && count > 0) ? '' : 'none';
    });
}

/**
 * Mark one notification as read
 * @param {number} notifId - ID notifikasi
 */
function markNotificationRead(notifId) {
    fetch(BASE_URL + 'controllers/NotificationController.php?action=read_one', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            notification_id: notifId,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
        }
    })
    .catch(err => console.error('Mark read error:', err));
    return false;
}

/**
 * Mark all notifications as read
 */
function markAllRead() {
    fetch(BASE_URL + 'controllers/NotificationController.php?action=read_all', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
            showToast('✅ ' + data.message, 'success');
        }
    })
    .catch(err => console.error('Mark all read error:', err));
    return false;
}

/**
 * Start notification polling
 */
function startNotificationPolling() {
    if (notificationPollingInterval) {
        clearInterval(notificationPollingInterval);
    }
    // Load first
    loadNotifications();
    // Then every 60 seconds
    notificationPollingInterval = setInterval(loadNotifications, 60000);
}

// =============================================
// UTILITY FUNCTIONS
// =============================================

/**
 * Get CSRF token from meta tag
 * @returns {string}
 */
function getCsrfToken() {
    return CSRF_TOKEN;
}

/**
 * Format angka ke format Rupiah
 * @param {number|string} number - Angka yang akan diformat
 * @returns {string} - Format "Rp xx.xxx"
 */
function formatRupiah(number) {
    if (typeof number === 'string') {
        number = parseFloat(number.replace(/[^0-9]/g, '')) || 0;
    }
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(number);
}

/**
 * Preview image dengan FileReader
 * @param {HTMLInputElement} inputEl - Element input file
 * @param {string} previewId - ID element preview
 */
function previewImage(inputEl, previewId) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    
    const file = inputEl.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.classList.add('has-image');
        };
        reader.readAsDataURL(file);
    } else {
        preview.src = '';
        preview.classList.remove('has-image');
    }
}

/**
 * Show toast notification
 * @param {string} message - Pesan yang ditampilkan
 * @param {string} type - 'success', 'danger', 'warning'
 */
function showToast(message, type = 'success') {
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
            <div class="toast-body fw-semibold">${message}</div>
            <button class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button>
        </div>
    `;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

// =============================================
// BOOTSTRAP FORM VALIDATION
// =============================================

document.addEventListener('DOMContentLoaded', function() {
    // Auto validation untuk form dengan class .needs-validation
    document.querySelectorAll('.needs-validation').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!this.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            this.classList.add('was-validated');
        });
    });
    
    // Auto dismiss alert setelah 4 detik
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 4000);
    });
    
    // Update cart badge on load
    if (document.querySelector('.cart-badge')) {
        fetch(BASE_URL + 'controllers/CartController.php?action=get_count')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    updateCartBadge(data.cart_count);
                }
            })
            .catch(err => console.error('Cart badge error:', err));
    }
    
    // Start notification polling if user is logged in
    if (document.querySelector('.notif-badge')) {
        startNotificationPolling();
    }
});