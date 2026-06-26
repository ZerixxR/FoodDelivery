<?php
/**
 * index.php
 * Halaman utama (landing page) FoodDelivery
 * 
 * @package FoodDelivery
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

$db = getDB();

// ============ STATS ============

// Total Restoran (seller aktif & terverifikasi)
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role = 'seller' AND is_verified = 1 AND is_active = 1");
$totalRestaurants = (int) $stmt->fetch()['total'];

// Total Menu Tersedia
$stmt = $db->query("SELECT COUNT(*) as total FROM products WHERE is_active = 1 AND stock > 0");
$totalMenus = (int) $stmt->fetch()['total'];

// Total Pesanan Selesai
$stmt = $db->query("SELECT COUNT(*) as total FROM orders WHERE status = 'delivered'");
$totalOrdersCompleted = (int) $stmt->fetch()['total'];

// ============ KATEGORI ============
$categories = $db->query("SELECT * FROM categories ORDER BY name LIMIT 8")->fetchAll();

// ============================================================
// MENU POPULER - MENGIKUTI URUTAN SELLER (sort_order)
// ============================================================
$popularMenus = $db->query("
    SELECT p.*, u.name AS resto_name, c.name AS category_name,
           COALESCE(AVG(r.rating), 0) AS avg_rating,
           COUNT(r.id) AS total_reviews
    FROM products p
    JOIN users u ON p.seller_id = u.id
    JOIN categories c ON p.category_id = c.id
    LEFT JOIN reviews r ON p.id = r.product_id
    WHERE p.is_active = 1 AND p.stock > 0
    GROUP BY p.id
    ORDER BY p.sort_order ASC, p.created_at DESC
    LIMIT 8
")->fetchAll();

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FoodDelivery - Pesan Makanan Favoritmu</title>
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    <script src="<?= BASE_URL ?>assets/js/main.js"></script>
    
    <style>
        /* ============ HERO ============ */
        .hero {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            padding: 80px 0 100px;
            position: relative;
            overflow: hidden;
            min-height: 500px;
        }
        .hero::after {
            content: '';
            position: absolute;
            bottom: -50px;
            left: 0;
            right: 0;
            height: 100px;
            background: white;
            border-radius: 50% 50% 0 0 / 100% 100% 0 0;
        }
        .hero-title {
            font-size: 48px;
            font-weight: 800;
            color: white;
            text-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .hero-subtitle {
            font-size: 18px;
            color: rgba(255,255,255,0.9);
        }
        .hero-search {
            max-width: 600px;
            margin: 0 auto;
        }
        .hero-search .input-group {
            border-radius: 50px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }
        .hero-search .form-control {
            border: none;
            padding: 18px 24px;
            font-size: 18px;
        }
        .hero-search .btn {
            padding: 18px 40px;
            font-weight: 700;
            font-size: 18px;
        }
        
        /* Floating emoji animation */
        .floating-emoji {
            position: absolute;
            font-size: 40px;
            animation: floatUp 8s infinite ease-in-out;
            opacity: 0.3;
            pointer-events: none;
        }
        .floating-emoji:nth-child(1) { top: 10%; left: 5%; animation-delay: 0s; }
        .floating-emoji:nth-child(2) { top: 20%; right: 8%; animation-delay: 1.5s; font-size: 50px; }
        .floating-emoji:nth-child(3) { top: 60%; left: 3%; animation-delay: 3s; font-size: 35px; }
        .floating-emoji:nth-child(4) { top: 70%; right: 5%; animation-delay: 4.5s; font-size: 45px; }
        .floating-emoji:nth-child(5) { top: 40%; left: 2%; animation-delay: 2s; font-size: 30px; }
        .floating-emoji:nth-child(6) { top: 50%; right: 3%; animation-delay: 5.5s; font-size: 55px; }
        
        @keyframes floatUp {
            0% { transform: translateY(0px) rotate(0deg); opacity: 0.3; }
            50% { transform: translateY(-30px) rotate(10deg); opacity: 0.6; }
            100% { transform: translateY(0px) rotate(0deg); opacity: 0.3; }
        }
        
        /* ============ STATS ============ */
        .stats-section {
            margin-top: -30px;
            position: relative;
            z-index: 10;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-number {
            font-size: 36px;
            font-weight: 800;
            color: #dc3545;
        }
        .stat-label {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }
        
        /* ============ KATEGORI ============ */
        .category-card {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: #333;
            display: block;
        }
        .category-card:hover {
            border-color: #dc3545;
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.12);
            color: #dc3545;
        }
        .category-icon {
            font-size: 40px;
            display: block;
            margin-bottom: 8px;
        }
        .category-name {
            font-weight: 600;
            font-size: 14px;
        }
        
        /* ============ MENU CARD ============ */
        .menu-card-home {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
            height: 100%;
        }
        .menu-card-home:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            border-color: #dc3545;
        }
        .menu-card-home .menu-img {
            height: 180px;
            object-fit: cover;
            width: 100%;
        }
        .menu-card-home .menu-body {
            padding: 16px;
        }
        .menu-card-home .menu-name {
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .menu-card-home .menu-resto {
            font-size: 13px;
            color: #6c757d;
        }
        .menu-card-home .menu-price {
            font-weight: 700;
            color: #dc3545;
            font-size: 18px;
        }
        .menu-card-home .stars {
            color: #f59e0b;
            font-size: 13px;
        }
        
        /* ============ CARA KERJA - STEP CARD ============ */
        .step-card {
            padding: 16px 8px;
            height: 100%;
        }
        .step-number {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #dc3545, #fd7e14);
            color: white;
            font-size: 20px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }
        .step-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }
        .step-title {
            font-weight: 700;
            font-size: 16px;
            color: #1e293b;
        }
        .step-desc {
            font-size: 13px;
            color: #6c757d;
            max-width: 200px;
            margin: 0 auto;
        }
        .step-arrow {
            font-size: 28px;
            color: #dc3545;
            opacity: 0.5;
        }
        
        /* ============ FOOTER ============ */
        .footer-custom {
            background: #1a1a2e;
            color: #fff;
            padding: 40px 0 20px;
        }
        .footer-custom a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
        }
        .footer-custom a:hover {
            color: #fd7e14;
        }
        
        /* ============ RESPONSIVE ============ */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 30px;
            }
            .hero {
                padding: 60px 0 80px;
                min-height: 400px;
            }
            .hero-search .form-control {
                font-size: 14px;
                padding: 14px 18px;
            }
            .hero-search .btn {
                font-size: 14px;
                padding: 14px 24px;
            }
            .stat-number {
                font-size: 28px;
            }
            .step-card {
                padding: 12px 4px;
            }
            .step-number {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }
            .step-icon {
                font-size: 24px;
            }
            .step-title {
                font-size: 14px;
            }
            .step-desc {
                font-size: 12px;
                max-width: 100%;
            }
            .step-arrow {
                display: none !important;
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
                <?php if (isLoggedIn() && getUserRole() === 'buyer'): ?>
                <li class="nav-item">
                    <a class="nav-link position-relative" href="<?= BASE_URL ?>views/buyer/cart.php">
                        <i class="bi bi-cart3 fs-5"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger cart-badge">0</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>views/buyer/orders.php">
                        <i class="bi bi-bag"></i> Pesanan
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (isLoggedIn()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= sanitize(getUser()['name'] ?? 'User') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php if (getUserRole() === 'seller'): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>views/seller/dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                        <?php elseif (getUserRole() === 'driver'): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>views/driver/dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                        <?php elseif (getUserRole() === 'admin'): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>views/admin/dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                        <?php else: ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>views/buyer/profile.php"><i class="bi bi-person me-2"></i>Profil</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?= BASE_URL ?>controllers/AuthController.php?action=logout_redirect">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </li>
                <?php else: ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>views/public/login.php" class="btn btn-outline-danger btn-sm">Login</a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>views/public/register.php" class="btn btn-danger btn-sm">Daftar</a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- ============ HERO SECTION ============ -->
<section class="hero d-flex align-items-center">
    <div class="floating-emoji">🍔</div>
    <div class="floating-emoji">🍕</div>
    <div class="floating-emoji">🍣</div>
    <div class="floating-emoji">🥗</div>
    <div class="floating-emoji">🍜</div>
    <div class="floating-emoji">🧋</div>
    
    <div class="container position-relative">
        <div class="text-center">
            <h1 class="hero-title mb-3">
                🚀 Pesan Makanan Favoritmu, <br>Antar Cepat!
            </h1>
            <p class="hero-subtitle mb-4">
                Dari restoran terdekat langsung ke pintu rumahmu. <br>Pesan sekarang, makanan hangat menunggumu!
            </p>
            <div class="hero-search">
                <form action="<?= BASE_URL ?>views/public/menu.php" method="GET">
                    <div class="input-group">
                        <input type="text" name="q" class="form-control" placeholder="Cari makanan, restoran, atau kategori...">
                        <button class="btn btn-dark" type="submit">
                            <i class="bi bi-search me-2"></i> Cari
                        </button>
                    </div>
                </form>
                <div class="mt-3">
                    <a href="<?= BASE_URL ?>views/public/menu.php" class="btn btn-light btn-lg px-5 fw-bold rounded-pill">
                        <i class="bi bi-arrow-right me-2"></i> Pesan Sekarang
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ STATS SECTION ============ -->
<section class="stats-section">
    <div class="container">
        <div class="row g-3">
            <div class="col-6 col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($totalRestaurants) ?></div>
                    <div class="stat-label">🏪 Restoran Mitra</div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($totalMenus) ?></div>
                    <div class="stat-label">🍽️ Menu Tersedia</div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($totalOrdersCompleted) ?></div>
                    <div class="stat-label">✅ Pesanan Selesai</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ KATEGORI SECTION ============ -->
<section class="py-5">
    <div class="container">
        <div class="text-center mb-4">
            <h2 class="fw-bold">📂 Cari Berdasarkan Kategori</h2>
            <p class="text-muted">Temukan makanan favoritmu dari berbagai kategori</p>
        </div>
        <div class="row g-3">
            <?php foreach ($categories as $cat): ?>
            <div class="col-6 col-md-3">
                <a href="<?= BASE_URL ?>views/public/menu.php?category=<?= $cat['id'] ?>" class="category-card">
                    <span class="category-icon"><?= $cat['icon'] ?? '🍽️' ?></span>
                    <span class="category-name"><?= sanitize($cat['name']) ?></span>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============ MENU POPULER SECTION ============ -->
<section class="py-4 bg-light">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold">🔥 Menu Populer</h2>
                <p class="text-muted">Pilihan terbaik dari pelanggan FoodDelivery</p>
            </div>
            <a href="<?= BASE_URL ?>views/public/menu.php" class="btn btn-outline-danger">
                Lihat Semua <i class="bi bi-arrow-right ms-2"></i>
            </a>
        </div>
        
        <?php if (empty($popularMenus)): ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-emoji-frown fs-1 d-block mb-2"></i>
            <span>Belum ada menu populer</span>
        </div>
        <?php else: ?>
        <div class="row g-4">
            <?php foreach ($popularMenus as $menu): ?>
            <div class="col-6 col-md-3">
                <div class="menu-card-home">
                    <img src="<?= $menu['image'] ? UPLOAD_URL . $menu['image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                         alt="<?= sanitize($menu['name']) ?>"
                         class="menu-img"
                         onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                    <div class="menu-body">
                        <div class="menu-name"><?= sanitize($menu['name']) ?></div>
                        <div class="menu-resto">
                            <i class="bi bi-shop me-1"></i><?= sanitize($menu['resto_name']) ?>
                        </div>
                        <div class="stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="bi bi-star<?= $i <= round($menu['avg_rating']) ? '-fill' : '' ?>"></i>
                            <?php endfor; ?>
                            <span class="text-muted ms-1">(<?= $menu['total_reviews'] ?>)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <span class="menu-price"><?= formatRupiah($menu['price']) ?></span>
                            <?php if (isLoggedIn() && getUserRole() === 'buyer'): ?>
                            <button class="btn btn-danger btn-sm" onclick="addToCart(<?= $menu['id'] ?>)">
                                <i class="bi bi-cart-plus"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================================ -->
<!-- ============ CARA KERJA SECTION ============ -->
<section class="py-5 bg-white">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">📋 Cara Kerja FoodDelivery</h2>
            <p class="text-muted">Mudah, cepat, dan praktis!</p>
        </div>
        
        <div class="row g-0 align-items-start">
            <!-- Step 1 -->
            <div class="col-6 col-md-3 text-center">
                <div class="step-card">
                    <div class="step-number mx-auto">1</div>
                    <span class="step-icon d-block">📱</span>
                    <div class="step-title fw-bold">Pilih Menu</div>
                    <div class="step-desc text-muted small">Cari dan pilih makanan favoritmu dari berbagai restoran</div>
                </div>
            </div>
            
            <!-- Arrow 1 -->
            <div class="col-6 col-md-1 d-none d-md-flex align-items-center justify-content-center">
                <div class="step-arrow text-danger fs-1">→</div>
            </div>
            
            <!-- Step 2 -->
            <div class="col-6 col-md-3 text-center">
                <div class="step-card">
                    <div class="step-number mx-auto">2</div>
                    <span class="step-icon d-block">🛒</span>
                    <div class="step-title fw-bold">Checkout</div>
                    <div class="step-desc text-muted small">Lengkapi alamat, pilih pembayaran, dan konfirmasi pesanan</div>
                </div>
            </div>
            
            <!-- Arrow 2 -->
            <div class="col-6 col-md-1 d-none d-md-flex align-items-center justify-content-center">
                <div class="step-arrow text-danger fs-1">→</div>
            </div>
            
            <!-- Step 3 -->
            <div class="col-6 col-md-3 text-center">
                <div class="step-card">
                    <div class="step-number mx-auto">3</div>
                    <span class="step-icon d-block">🛵</span>
                    <div class="step-title fw-bold">Driver Ambil</div>
                    <div class="step-desc text-muted small">Driver kami akan mengambil pesanan dari restoran</div>
                </div>
            </div>
            
            <!-- Arrow 3 -->
            <div class="col-6 col-md-1 d-none d-md-flex align-items-center justify-content-center">
                <div class="step-arrow text-danger fs-1">→</div>
            </div>
            
            <!-- Step 4 -->
            <div class="col-6 col-md-3 text-center">
                <div class="step-card">
                    <div class="step-number mx-auto">4</div>
                    <span class="step-icon d-block">🏠</span>
                    <div class="step-title fw-bold">Terkirim</div>
                    <div class="step-desc text-muted small">Pesanan tiba di depan pintu rumahmu! Selamat menikmati 🎉</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ FOOTER ============ -->
<footer class="footer-custom">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2 fw-bold fs-5 mb-2">
                    <div class="brand-icon brand-icon-sm"><i class="bi bi-bicycle"></i></div>
                    <span class="text-danger">Food</span><span>Delivery</span>
                </div>
                <p class="text-white-50 small">Platform pesan antar makanan terpercaya di Indonesia. Sajikan makanan favoritmu dengan cepat dan mudah.</p>
                <div class="d-flex gap-3 mt-3">
                    <a href="#" class="text-white-50"><i class="bi bi-instagram fs-5"></i></a>
                    <a href="#" class="text-white-50"><i class="bi bi-twitter-x fs-5"></i></a>
                    <a href="#" class="text-white-50"><i class="bi bi-youtube fs-5"></i></a>
                    <a href="#" class="text-white-50"><i class="bi bi-tiktok fs-5"></i></a>
                </div>
            </div>
            <div class="col-md-2">
                <h6 class="fw-bold mb-2">Menu</h6>
                <ul class="list-unstyled small">
                    <li><a href="<?= BASE_URL ?>views/public/menu.php">Semua Menu</a></li>
                    <li><a href="<?= BASE_URL ?>views/public/menu.php?sort=popular">Populer</a></li>
                    <li><a href="<?= BASE_URL ?>views/public/menu.php?sort=newest">Terbaru</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="fw-bold mb-2">Kontak</h6>
                <ul class="list-unstyled small">
                    <li><i class="bi bi-envelope me-2"></i> support@fooddelivery.com</li>
                    <li><i class="bi bi-telephone me-2"></i> 021-1234-5678</li>
                    <li><i class="bi bi-geo-alt me-2"></i> Jakarta, Indonesia</li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="fw-bold mb-2">Tentang</h6>
                <ul class="list-unstyled small">
                    <li><a href="#">Tentang Kami</a></li>
                    <li><a href="#">Syarat & Ketentuan</a></li>
                    <li><a href="#">Kebijakan Privasi</a></li>
                    <li><a href="#">Bantuan</a></li>
                </ul>
            </div>
        </div>
        <hr class="border-secondary">
        <div class="text-center text-white-50 small">
            &copy; <?= date('Y') ?> FoodDelivery. All Rights Reserved. Tugas Besar Pemrograman Web - UBHARA JAYA
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// =============================================
// GLOBAL VARIABLES
// =============================================
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// =============================================
// ADD TO CART
// =============================================
function addToCart(productId) {
    fetch(BASE_URL + 'controllers/CartController.php?action=add', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            product_id: productId,
            quantity: 1,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + data.message, 'success');
            updateCartBadge(data.cart_count);
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
// UPDATE CART BADGE
// =============================================
function updateCartBadge(count) {
    document.querySelectorAll('.cart-badge').forEach(el => {
        el.textContent = count || 0;
        el.style.display = (count && count > 0) ? '' : 'none';
    });
}

// =============================================
// SHOW TOAST
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
// LOGOUT - TANPA FETCH (PASTI BERHASIL)
// =============================================
function handleLogout(event) {
    event.preventDefault();
    if (!confirm('Yakin ingin logout?')) return;
    // Redirect langsung ke controller logout
    window.location.href = BASE_URL + 'controllers/AuthController.php?action=logout_redirect';
}

// =============================================
// UPDATE CART BADGE ON LOAD
// =============================================
document.addEventListener('DOMContentLoaded', function() {
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
});
</script>

</body>
</html>