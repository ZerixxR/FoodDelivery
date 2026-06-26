<?php
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../config/database.php';

$pageTitle = 'Menu Makanan';
$db = getDB();

// Filter & search
$search     = sanitize($_GET['q'] ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
$sort       = sanitize($_GET['sort'] ?? 'newest');

$where  = "WHERE p.is_active = 1 AND u.is_verified = 1";
$params = [];
if ($search)     { $where .= " AND (p.name LIKE ? OR p.description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($categoryId) { $where .= " AND p.category_id = ?"; $params[] = $categoryId; }

$orderBy = match($sort) {
  'price_asc'  => 'p.price ASC',
  'price_desc' => 'p.price DESC',
  'popular'    => 'p.sold DESC',
  default      => 'p.created_at DESC',
};

$menus = $db->prepare("
  SELECT p.*, u.name AS resto_name, c.name AS category_name, c.icon AS category_icon,
         COALESCE(AVG(r.rating), 0) AS avg_rating,
         COUNT(r.id) AS total_reviews
  FROM products p
  JOIN users u ON p.seller_id = u.id
  JOIN categories c ON p.category_id = c.id
  LEFT JOIN reviews r ON p.id = r.product_id
  $where
  GROUP BY p.id
  ORDER BY $orderBy
  LIMIT 24
");
$menus->execute($params);
$menus = $menus->fetchAll();

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Get cart count for badge
$cartCount = 0;
if (isLoggedIn() && getUserRole() === 'buyer') {
    $stmt = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE user_id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $cartCount = (int)$stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Menu - FoodDelivery</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg bg-white shadow-sm sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="<?= BASE_URL ?>views/public/index.php">
      <div class="brand-icon brand-icon-sm"><i class="bi bi-bicycle"></i></div>
      <span class="text-danger">Food</span><span>Delivery</span>
    </a>

    <!-- Search -->
    <form class="d-none d-lg-flex flex-grow-1 mx-4" method="GET">
      <?php if ($categoryId): ?><input type="hidden" name="category" value="<?= $categoryId ?>"><?php endif; ?>
      <div class="input-group">
        <input type="text" name="q" class="form-control" placeholder="Cari makanan, restoran..."
               value="<?= $search ?>">
        <button class="btn btn-danger" type="submit"><i class="bi bi-search"></i></button>
      </div>
    </form>

    <!-- Nav Right -->
    <div class="d-flex align-items-center gap-2">
      <?php if (isLoggedIn() && getUserRole() === 'buyer'): ?>
      <a href="<?= BASE_URL ?>views/buyer/cart.php" class="btn btn-outline-danger position-relative">
        <i class="bi bi-cart3"></i>
        <?php if ($cartCount > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger cart-badge"><?= $cartCount ?></span>
        <?php endif; ?>
      </a>
      <div class="dropdown">
        <button class="btn btn-light fw-semibold small" data-bs-toggle="dropdown">
          <?= sanitize(getUser()['name']) ?> <i class="bi bi-chevron-down"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= BASE_URL ?>views/buyer/orders.php"><i class="bi bi-bag me-2"></i>Pesanan Saya</a></li>
          <li><hr class="dropdown-divider"></li>
          <!-- ============ LOGOUT - PAKAI LOGOUT_REDIRECT ============ -->
          <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>controllers/AuthController.php?action=logout_redirect"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
        </ul>
      </div>
      <?php elseif (isLoggedIn()): ?>
      <a href="<?= BASE_URL ?>views/<?= getUserRole() ?>/dashboard.php" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-speedometer2 me-1"></i>Dashboard
      </a>
      <!-- ============ LOGOUT - PAKAI LOGOUT_REDIRECT ============ -->
      <a href="<?= BASE_URL ?>controllers/AuthController.php?action=logout_redirect" class="btn btn-light btn-sm">Logout</a>
      <?php else: ?>
      <a href="<?= BASE_URL ?>views/public/login.php"    class="btn btn-outline-danger btn-sm">Login</a>
      <a href="<?= BASE_URL ?>views/public/register.php" class="btn btn-danger btn-sm">Daftar</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- Flash Message -->
<?php $flash = getFlash(); if ($flash): ?>
<div class="alert alert-<?= $flash['type']==='success'?'success':'danger' ?> alert-dismissible rounded-0 mb-0 py-2">
  <div class="container small">
    <i class="bi bi-info-circle me-2"></i><?= sanitize($flash['message']) ?>
    <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php endif; ?>

<!-- HERO SEARCH (Mobile) -->
<div class="bg-danger py-4 d-lg-none">
  <div class="container">
    <form method="GET">
      <div class="input-group">
        <input type="text" name="q" class="form-control form-control-lg"
               placeholder="🍔 Mau makan apa hari ini?" value="<?= $search ?>">
        <button class="btn btn-warning fw-bold" type="submit">Cari</button>
      </div>
    </form>
  </div>
</div>

<!-- KATEGORI PILLS -->
<div class="bg-white border-bottom py-3">
  <div class="container">
    <div class="d-flex gap-2 overflow-auto pb-1">
      <a href="<?= BASE_URL ?>views/public/menu.php<?= $search ? "?q=$search" : '' ?>"
         class="btn btn-sm rounded-pill flex-shrink-0 <?= !$categoryId ? 'btn-danger' : 'btn-outline-danger' ?>">
        🍽️ Semua
      </a>
      <?php foreach ($categories as $cat): ?>
      <a href="?category=<?= $cat['id'] ?><?= $search ? "&q=$search" : '' ?>"
         class="btn btn-sm rounded-pill flex-shrink-0 <?= $categoryId === $cat['id'] ? 'btn-danger' : 'btn-outline-danger' ?>">
        <?= $cat['icon'] ?> <?= sanitize($cat['name']) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- MAIN CONTENT -->
<div class="container py-4">

  <!-- Header & Sort -->
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
      <h5 class="fw-bold mb-0">
        <?= $search ? "Hasil: \"$search\"" : ($categoryId ? sanitize($categories[array_search($categoryId, array_column($categories,'id'))]['name'] ?? 'Kategori') : 'Semua Menu') ?>
      </h5>
      <small class="text-muted"><?= count($menus) ?> menu tersedia</small>
    </div>
    <form method="GET" class="d-flex gap-2">
      <?php if ($search): ?><input type="hidden" name="q" value="<?= $search ?>"><?php endif; ?>
      <?php if ($categoryId): ?><input type="hidden" name="category" value="<?= $categoryId ?>"><?php endif; ?>
      <select name="sort" class="form-select form-select-sm" style="width:160px" onchange="this.form.submit()">
        <option value="newest"     <?= $sort==='newest'    ?'selected':'' ?>>Terbaru</option>
        <option value="popular"    <?= $sort==='popular'   ?'selected':'' ?>>Terpopuler</option>
        <option value="price_asc"  <?= $sort==='price_asc' ?'selected':'' ?>>Harga Terendah</option>
        <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>Harga Tertinggi</option>
      </select>
    </form>
  </div>

  <?php if (empty($menus)): ?>
  <!-- Empty State -->
  <div class="text-center py-5 bg-white rounded-4 border">
    <div style="font-size:4rem">😕</div>
    <h5 class="fw-bold mt-3">Menu tidak ditemukan</h5>
    <p class="text-muted">Coba kata kunci lain atau pilih kategori berbeda.</p>
    <a href="<?= BASE_URL ?>views/public/menu.php" class="btn btn-danger">Lihat Semua Menu</a>
  </div>

  <?php else: ?>
  <div class="row g-4">
    <?php foreach ($menus as $menu): ?>
    <div class="col-6 col-md-4 col-lg-3">
      <div class="menu-card h-100">

        <!-- Gambar -->
        <div class="menu-img-wrap">
          <img src="<?= UPLOAD_URL ?><?= $menu['image'] ?>"
               alt="<?= sanitize($menu['name']) ?>"
               onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
          <span class="menu-badge"><?= $menu['category_icon'] ?> <?= sanitize($menu['category_name']) ?></span>
        </div>

        <!-- Info -->
        <div class="menu-body">
          <div class="small text-muted mb-1">
            <i class="bi bi-shop me-1"></i><?= sanitize($menu['resto_name']) ?>
          </div>
          <h6 class="menu-title"><?= sanitize($menu['name']) ?></h6>

          <!-- Rating -->
          <div class="d-flex align-items-center gap-1 mb-2">
            <span class="stars">
              <?php for ($i=1;$i<=5;$i++): ?>
              <i class="bi bi-star<?= $i <= round($menu['avg_rating']) ? '-fill' : '' ?>"></i>
              <?php endfor; ?>
            </span>
            <small class="text-muted">(<?= $menu['total_reviews'] ?>)</small>
          </div>

          <div class="d-flex justify-content-between align-items-center">
            <span class="menu-price"><?= formatRupiah($menu['price']) ?></span>
            <?php if ($menu['stock'] <= 0): ?>
            <span class="badge bg-secondary">Habis</span>
            <?php endif; ?>
          </div>

          <!-- Tombol Tambah -->
          <?php if (isLoggedIn() && getUserRole() === 'buyer' && $menu['stock'] > 0): ?>
          <button class="btn btn-danger btn-sm w-100 mt-2 fw-semibold"
                  onclick="addToCart(<?= $menu['id'] ?>)">
            <i class="bi bi-cart-plus me-1"></i>Tambah
          </button>
          <?php elseif (!isLoggedIn() && $menu['stock'] > 0): ?>
          <a href="<?= BASE_URL ?>views/public/login.php"
             class="btn btn-outline-danger btn-sm w-100 mt-2 fw-semibold">
            Login untuk Pesan
          </a>
          <?php else: ?>
          <button class="btn btn-secondary btn-sm w-100 mt-2" disabled>Stok Habis</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- FOOTER SIMPLE -->
<footer class="bg-dark text-white text-center py-4 mt-5">
  <div class="container">
    <div class="fw-bold mb-1"><span class="text-danger">Food</span>Delivery &copy; 2025</div>
    <small class="text-secondary">Tugas Besar Pemrograman Web - Universitas Bhayangkara Jakarta Raya</small>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BASE_URL  = '<?= BASE_URL ?>';
const CSRF_TOKEN = '<?= generateCsrfToken() ?>';

function addToCart(productId, qty = 1) {
  fetch(BASE_URL + 'controllers/CartController.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=add&product_id=${productId}&quantity=${qty}&csrf_token=${CSRF_TOKEN}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      showToast('✅ ' + data.message, 'success');
      // Update badge cart
      document.querySelectorAll('.cart-badge').forEach(b => b.textContent = data.cart_count);
    } else {
      showToast('❌ ' + data.message, 'danger');
    }
  })
  .catch(() => showToast('❌ Gagal menambahkan. Coba lagi.', 'danger'));
}

function showToast(msg, type = 'success') {
  let wrap = document.getElementById('toast-wrap');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.id = 'toast-wrap';
    wrap.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;';
    document.body.appendChild(wrap);
  }
  const t = document.createElement('div');
  t.className = `toast align-items-center text-bg-${type} border-0 show mb-2`;
  t.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
    <button class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button></div>`;
  wrap.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}
</script>
</body>
</html>