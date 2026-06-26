<?php
/**
 * menu-form.php
 * Halaman tambah/edit menu seller
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

// Get categories
$categories = $db->query("SELECT id, name, icon FROM categories ORDER BY name")->fetchAll();

// Mode: edit atau tambah
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$isEdit = $editId > 0;

$product = null;
if ($isEdit) {
    $stmt = $db->prepare("
        SELECT p.*, c.name as category_name 
        FROM products p
        JOIN categories c ON p.category_id = c.id
        WHERE p.id = ? AND p.seller_id = ?
    ");
    $stmt->execute([$editId, $userId]);
    $product = $stmt->fetch();
    
    if (!$product) {
        setFlash('danger', 'Produk tidak ditemukan atau bukan milik Anda');
        redirect(BASE_URL . 'views/seller/menu.php');
    }
}

$csrfToken = generateCsrfToken();
$flash = getFlash();

// Default values
$name = $product['name'] ?? '';
$categoryId = $product['category_id'] ?? 0;
$description = $product['description'] ?? '';
$price = $product['price'] ?? '';
$stock = $product['stock'] ?? '';
$weight = $product['weight'] ?? 200;
$isActive = $product['is_active'] ?? 1;
$image = $product['image'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit' : 'Tambah' ?> Menu - FoodDelivery</title>
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
        .form-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            border: 1px solid #e9ecef;
        }
        .image-preview {
            width: 200px;
            height: 200px;
            object-fit: cover;
            border-radius: 12px;
            border: 2px dashed #dee2e6;
            background: #f8f9fa;
        }
        .image-preview.has-image {
            border: 2px solid #28a745;
        }
        .price-input-wrapper {
            position: relative;
        }
        .price-input-wrapper .currency {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: 600;
            color: #6c757d;
        }
        .price-input-wrapper input {
            padding-left: 40px;
        }
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid #e9ecef;
            }
            .image-preview {
                width: 150px;
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
            <a href="<?= BASE_URL ?>views/seller/menu.php" class="btn btn-outline-secondary btn-sm">
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
                    <div class="fw-bold text-danger"><?= sanitize($_SESSION['user']['name'] ?? 'Restoran') ?></div>
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
        
        <!-- ============ FORM ============ -->
        <div class="col-lg-10 col-md-9 col-12 p-4">
            <h4 class="fw-bold mb-4">
                <i class="bi bi-<?= $isEdit ? 'pencil-square' : 'plus-circle' ?> text-danger"></i> 
                <?= $isEdit ? 'Edit' : 'Tambah' ?> Menu
            </h4>
            
            <div class="form-card">
                <form id="menuForm" enctype="multipart/form-data" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="product_id" value="<?= $editId ?>">
                    <?php endif; ?>
                    
                    <div class="row g-4">
                        <!-- ============ LEFT COLUMN ============ -->
                        <div class="col-lg-7">
                            <!-- Nama Menu -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nama Menu <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" 
                                       value="<?= sanitize($name) ?>" required minlength="3">
                                <div class="invalid-feedback">Nama menu minimal 3 karakter</div>
                            </div>
                            
                            <!-- Kategori -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Kategori <span class="text-danger">*</span></label>
                                <select name="category_id" class="form-select" required>
                                    <option value="">Pilih Kategori</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>>
                                        <?= $cat['icon'] ?? '📁' ?> <?= sanitize($cat['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Pilih kategori</div>
                            </div>
                            
                            <!-- Deskripsi -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Deskripsi</label>
                                <textarea name="description" class="form-control" rows="4" 
                                          placeholder="Deskripsi menu..."><?= sanitize($description) ?></textarea>
                            </div>
                            
                            <!-- Harga & Stok -->
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Harga <span class="text-danger">*</span></label>
                                    <div class="price-input-wrapper">
                                        <span class="currency">Rp</span>
                                        <input type="text" name="price" id="priceInput" class="form-control" 
                                               value="<?= number_format($price, 0, ',', '.') ?>" 
                                               required placeholder="0" inputmode="numeric">
                                    </div>
                                    <div class="invalid-feedback">Harga wajib diisi</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Stok <span class="text-danger">*</span></label>
                                    <input type="number" name="stock" class="form-control" 
                                           value="<?= $stock ?>" required min="0">
                                    <div class="invalid-feedback">Stok wajib diisi</div>
                                </div>
                            </div>
                            
                            <!-- Berat -->
                            <div class="row g-3 mt-1">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Berat (gram)</label>
                                    <input type="number" name="weight" class="form-control" 
                                           value="<?= $weight ?>" min="0">
                                    <div class="form-text">Untuk perhitungan ongkir</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Status</label>
                                    <div class="form-check form-switch mt-2">
                                        <input type="checkbox" name="is_active" class="form-check-input" 
                                               id="statusToggle" <?= $isActive == 1 ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-semibold" id="statusLabel">
                                            <?= $isActive == 1 ? 'Aktif' : 'Nonaktif' ?>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ============ RIGHT COLUMN ============ -->
                        <div class="col-lg-5">
                            <label class="form-label fw-semibold">Foto Menu</label>
                            <div class="text-center">
                                <img id="imagePreview" 
                                     src="<?= $image ? UPLOAD_URL . $image : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                                     alt="Preview" 
                                     class="image-preview <?= $image ? 'has-image' : '' ?>">
                                <div class="mt-2">
                                    <input type="file" name="image" id="imageInput" 
                                           class="form-control form-control-sm" accept="image/*">
                                    <div class="form-text small">Format JPG, PNG, WEBP. Maks 2MB.</div>
                                </div>
                            </div>
                            
                            <!-- Preview Detail -->
                            <div class="mt-3 p-3 bg-light rounded-3">
                                <h6 class="fw-bold small text-muted text-uppercase">Preview</h6>
                                <div id="previewDetail">
                                    <div class="fw-bold" id="previewName"><?= $name ?: 'Nama Menu' ?></div>
                                    <div class="text-danger fw-bold" id="previewPrice"><?= $price ? formatRupiah($price) : 'Rp 0' ?></div>
                                    <div class="small text-muted" id="previewStock">Stok: <?= $stock ?: '0' ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ============ SUBMIT BUTTONS ============ -->
                    <div class="d-flex gap-3 mt-4 pt-3 border-top">
                        <button type="submit" class="btn btn-danger px-4">
                            <i class="bi bi-<?= $isEdit ? 'check-lg' : 'plus-lg' ?> me-2"></i>
                            <?= $isEdit ? 'Update Menu' : 'Tambah Menu' ?>
                        </button>
                        <a href="<?= BASE_URL ?>views/seller/menu.php" class="btn btn-outline-secondary">
                            Batal
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - <?= $isEdit ? 'Edit' : 'Tambah' ?> Menu</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const isEdit = <?= $isEdit ? 'true' : 'false' ?>;

// =============================================
// IMAGE PREVIEW
// =============================================
document.getElementById('imageInput').addEventListener('change', function(e) {
    const file = this.files[0];
    const preview = document.getElementById('imagePreview');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(event) {
            preview.src = event.target.result;
            preview.classList.add('has-image');
        };
        reader.readAsDataURL(file);
    } else {
        preview.src = '<?= $image ? UPLOAD_URL . $image : BASE_URL . "assets/images/no-food.jpg" ?>';
        if (!'<?= $image ?>') {
            preview.classList.remove('has-image');
        }
    }
});

// =============================================
// PRICE FORMAT (auto Rupiah)
// =============================================
document.getElementById('priceInput').addEventListener('input', function(e) {
    let value = this.value.replace(/[^0-9]/g, '');
    if (value) {
        value = parseInt(value);
        this.value = new Intl.NumberFormat('id-ID').format(value);
    } else {
        this.value = '';
    }
});

// =============================================
// REAL-TIME PREVIEW
// =============================================
document.querySelector('input[name="name"]').addEventListener('input', function() {
    document.getElementById('previewName').textContent = this.value || 'Nama Menu';
});

document.getElementById('priceInput').addEventListener('input', function() {
    const raw = this.value.replace(/[^0-9]/g, '');
    const price = raw ? 'Rp ' + new Intl.NumberFormat('id-ID').format(parseInt(raw)) : 'Rp 0';
    document.getElementById('previewPrice').textContent = price;
});

document.querySelector('input[name="stock"]').addEventListener('input', function() {
    document.getElementById('previewStock').textContent = 'Stok: ' + (this.value || '0');
});

// =============================================
// STATUS TOGGLE
// =============================================
document.getElementById('statusToggle').addEventListener('change', function() {
    document.getElementById('statusLabel').textContent = this.checked ? 'Aktif' : 'Nonaktif';
});

// =============================================
// FORM SUBMIT
// =============================================
document.getElementById('menuForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Validate
    if (!this.checkValidity()) {
        this.classList.add('was-validated');
        return;
    }
    
    // Parse price (remove dots)
    const priceInput = document.getElementById('priceInput');
    const rawPrice = priceInput.value.replace(/[^0-9]/g, '');
    if (rawPrice) {
        const hiddenPrice = document.createElement('input');
        hiddenPrice.type = 'hidden';
        hiddenPrice.name = 'price';
        hiddenPrice.value = rawPrice;
        this.appendChild(hiddenPrice);
    }
    
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Memproses...';
    
    const formData = new FormData(this);
    
    fetch(BASE_URL + 'controllers/ProductController.php?action=' + (isEdit ? 'update' : 'create'), {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + data.message, 'success');
            setTimeout(() => {
                window.location.href = data.redirect || BASE_URL + 'views/seller/menu.php';
            }, 1500);
        } else {
            showToast('❌ ' + data.message, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-' + (isEdit ? 'check-lg' : 'plus-lg') + ' me-2"></i> ' + 
                           (isEdit ? 'Update Menu' : 'Tambah Menu');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showToast('❌ Terjadi kesalahan. Silakan coba lagi.', 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-' + (isEdit ? 'check-lg' : 'plus-lg') + ' me-2"></i> ' + 
                       (isEdit ? 'Update Menu' : 'Tambah Menu');
    });
});

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