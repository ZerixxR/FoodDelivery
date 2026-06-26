<?php
/**
 * menu.php
 * Halaman kelola menu seller (dengan drag & drop)
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

// Filter
$categoryFilter = isset($_GET['category']) ? (int) $_GET['category'] : 0;
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$search = isset($_GET['q']) ? sanitize($_GET['q']) : '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query
$where = "WHERE p.seller_id = ?";
$params = [$userId];

if ($categoryFilter > 0) {
    $where .= " AND p.category_id = ?";
    $params[] = $categoryFilter;
}

if ($statusFilter === 'active') {
    $where .= " AND p.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $where .= " AND p.is_active = 0";
}

if (!empty($search)) {
    $where .= " AND p.name LIKE ?";
    $params[] = "%$search%";
}

// Count total
$countStmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM products p 
    $where
");
$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetch()['total'];
$totalPages = ceil($totalProducts / $limit);

// Get products (diurutkan berdasarkan sort_order)
$stmt = $db->prepare("
    SELECT 
        p.*,
        c.name as category_name
    FROM products p
    JOIN categories c ON p.category_id = c.id
    $where
    ORDER BY p.sort_order ASC, p.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$limit, $offset]));
$products = $stmt->fetchAll();

// Get categories for filter
$categories = $db->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$csrfToken = generateCsrfToken();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Menu - FoodDelivery</title>
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
        .product-thumb {
            width: 56px;
            height: 56px;
            object-fit: cover;
            border-radius: 8px;
        }
        .status-badge {
            font-size: 11px;
            padding: 3px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        .stock-badge {
            font-size: 11px;
            padding: 2px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        .btn-action {
            padding: 2px 8px;
            font-size: 13px;
        }
        .drag-handle {
            cursor: grab;
            font-size: 20px;
            color: #adb5bd;
            padding: 0 8px;
            user-select: none;
        }
        .drag-handle:hover {
            color: #dc3545;
        }
        .drag-handle:active {
            cursor: grabbing;
        }
        .sortable-chosen {
            background: #fff5f5 !important;
            border: 2px dashed #dc3545 !important;
        }
        .sortable-ghost {
            opacity: 0.4;
            background: #f8f9fa !important;
        }
        #productTable tbody tr {
            transition: background 0.2s;
        }
        #productTable tbody tr:hover {
            background: #fafafa;
        }
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid #e9ecef;
            }
            .table-responsive {
                font-size: 13px;
            }
            .product-thumb {
                width: 40px;
                height: 40px;
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
                    <a class="nav-link" href="<?= BASE_URL ?>views/seller/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link active" href="<?= BASE_URL ?>views/seller/menu.php">
                        <i class="bi bi-grid"></i> Kelola Menu
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/seller/orders.php">
                        <i class="bi bi-bag"></i> Pesanan Masuk
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
                <div>
                    <h4 class="fw-bold">
                        <i class="bi bi-grid text-danger"></i> Kelola Menu
                    </h4>
                    <small class="text-muted">Drag ☰ untuk mengurutkan menu</small>
                </div>
                <div>
                    <button class="btn btn-success btn-sm me-2" id="saveOrderBtn" disabled>
                        <i class="bi bi-check-lg me-2"></i> Urutan Tersimpan
                    </button>
                    <a href="<?= BASE_URL ?>views/seller/menu-form.php" class="btn btn-danger">
                        <i class="bi bi-plus-circle me-2"></i> Tambah Menu Baru
                    </a>
                </div>
            </div>
            
            <!-- ============ FILTERS ============ -->
            <div class="bg-white rounded-3 border p-3 mb-4">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Cari Menu</label>
                        <div class="input-group">
                            <input type="text" name="q" class="form-control" 
                                   placeholder="Nama menu..." value="<?= $search ?>">
                            <button class="btn btn-outline-secondary" type="submit">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Kategori</label>
                        <select name="category" class="form-select" onchange="this.form.submit()">
                            <option value="0">Semua Kategori</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $categoryFilter === $cat['id'] ? 'selected' : '' ?>>
                                <?= sanitize($cat['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktif</option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <?php if (!empty($search) || $categoryFilter > 0 || $statusFilter !== 'all'): ?>
                        <a href="<?= BASE_URL ?>views/seller/menu.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-eraser"></i> Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- ============ PRODUCT TABLE ============ -->
            <div class="bg-white rounded-3 border">
                <?php if (empty($products)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-grid fs-1 text-muted d-block mb-3"></i>
                    <h6 class="fw-bold">Belum ada menu</h6>
                    <p class="text-muted small">Tambahkan menu pertama Anda</p>
                    <a href="<?= BASE_URL ?>views/seller/menu-form.php" class="btn btn-danger btn-sm">
                        <i class="bi bi-plus-circle me-2"></i> Tambah Menu
                    </a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="productTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;"></th>
                                <th>Menu</th>
                                <th>Kategori</th>
                                <th class="text-end">Harga</th>
                                <th class="text-center">Stok</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): 
                                $stockClass = $product['stock'] <= 0 ? 'bg-danger' : ($product['stock'] <= 5 ? 'bg-warning' : 'bg-success');
                                $statusClass = $product['is_active'] == 1 ? 'bg-success' : 'bg-secondary';
                                $statusLabel = $product['is_active'] == 1 ? 'Aktif' : 'Nonaktif';
                            ?>
                            <tr data-product-id="<?= $product['id'] ?>">
                                <td class="text-center">
                                    <span class="drag-handle" title="Seret untuk mengurutkan">☰</span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <img src="<?= $product['image'] ? UPLOAD_URL . $product['image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                                             class="product-thumb"
                                             onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                                        <div>
                                            <div class="fw-semibold"><?= sanitize($product['name']) ?></div>
                                            <small class="text-muted"><?= date('d/m/Y', strtotime($product['created_at'])) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?= sanitize($product['category_name'] ?? '-') ?></td>
                                <td class="text-end fw-bold"><?= formatRupiah($product['price']) ?></td>
                                <td class="text-center">
                                    <span class="stock-badge <?= $stockClass ?> text-white">
                                        <?= $product['stock'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge <?= $statusClass ?> text-white">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <a href="<?= BASE_URL ?>views/seller/menu-form.php?edit=<?= $product['id'] ?>" 
                                           class="btn btn-outline-primary btn-action"
                                           title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <?php if ($product['is_active'] == 1): ?>
                                        <button class="btn btn-outline-secondary btn-action" 
                                                onclick="toggleProduct(<?= $product['id'] ?>, 0)"
                                                title="Nonaktifkan">
                                            <i class="bi bi-eye-slash"></i>
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-outline-success btn-action" 
                                                onclick="toggleProduct(<?= $product['id'] ?>, 1)"
                                                title="Aktifkan">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-outline-danger btn-action" 
                                                onclick="deleteProduct(<?= $product['id'] ?>, '<?= addslashes($product['name']) ?>')"
                                                title="Hapus">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ============ PAGINATION ============ -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&category=<?= $categoryFilter ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>">
                            Sebelumnya
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&category=<?= $categoryFilter ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&category=<?= $categoryFilter ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>">
                            Selanjutnya
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
            
            <div class="text-muted small mt-2">
                Total <?= $totalProducts ?> menu
            </div>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - Kelola Menu</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- ============================================ -->
<!-- DRAG & DROP LIBRARY (SortableJS) -->
<!-- ============================================ -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<script>
// =============================================
// KONFIGURASI
// =============================================
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// =============================================
// DRAG & DROP REORDER (HANYA DI KELOLA MENU)
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.querySelector('#productTable tbody');
    if (!tbody) return;
    if (tbody.querySelectorAll('tr').length < 2) return;
    
    const saveBtn = document.getElementById('saveOrderBtn');
    let hasChanged = false;
    
    // Inisialisasi Sortable
    const sortable = new Sortable(tbody, {
        animation: 150,
        handle: '.drag-handle',
        onStart: function() {
            hasChanged = false;
        },
        onEnd: function(evt) {
            hasChanged = true;
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Simpan Urutan';
        }
    });
    
    // Tombol simpan
    saveBtn.addEventListener('click', function() {
        if (!hasChanged) return;
        
        const rows = tbody.querySelectorAll('tr');
        const order = [];
        rows.forEach((row, index) => {
            const productId = row.dataset.productId;
            if (productId) {
                order.push({
                    id: productId,
                    sort_order: index
                });
            }
        });
        
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Menyimpan...';
        
        fetch(BASE_URL + 'controllers/ProductController.php?action=reorder', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                order: JSON.stringify(order),
                csrf_token: CSRF_TOKEN
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                saveBtn.innerHTML = '<i class="bi bi-check-lg me-2"></i> Urutan Tersimpan';
                hasChanged = false;
            } else {
                showToast('❌ ' + data.message, 'danger');
                saveBtn.innerHTML = '<i class="bi bi-arrow-repeat me-2"></i> Coba Lagi';
                saveBtn.disabled = false;
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showToast('❌ Terjadi kesalahan', 'danger');
            saveBtn.innerHTML = '<i class="bi bi-arrow-repeat me-2"></i> Coba Lagi';
            saveBtn.disabled = false;
        });
    });
});

// =============================================
// DELETE PRODUCT
// =============================================
function deleteProduct(productId, productName) {
    if (!confirm(`Hapus menu "${productName}"?`)) return;
    
    fetch(BASE_URL + 'controllers/ProductController.php?action=delete', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            product_id: productId,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ Menu berhasil dihapus', 'success');
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
// TOGGLE PRODUCT STATUS
// =============================================
function toggleProduct(productId, status) {
    const label = status === 1 ? 'aktifkan' : 'nonaktifkan';
    if (!confirm(`Yakin ingin ${label} menu ini?`)) return;
    
    fetch(BASE_URL + 'controllers/ProductController.php?action=toggle', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            product_id: productId,
            is_active: status,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ Status menu diupdate', 'success');
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