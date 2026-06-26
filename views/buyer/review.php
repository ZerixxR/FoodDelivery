<?php
/**
 * review.php
 * Halaman untuk memberikan ulasan produk
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

// Cek order milik buyer dan status delivered
$stmt = $db->prepare("
    SELECT o.id, o.order_code, o.status, o.buyer_id
    FROM orders o
    WHERE o.id = ? AND o.buyer_id = ? AND o.status = 'delivered'
");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch();

if (!$order) {
    setFlash('danger', 'Pesanan tidak ditemukan atau belum selesai');
    redirect(BASE_URL . 'views/buyer/orders.php');
}

// Ambil produk dari order yang BELUM di-review
$stmt = $db->prepare("
    SELECT oi.*, 
           COALESCE(p.image, oi.product_image) as image,
           CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END as is_reviewed
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    LEFT JOIN reviews r ON r.product_id = oi.product_id AND r.order_id = oi.order_id AND r.user_id = ?
    WHERE oi.order_id = ?
");
$stmt->execute([$userId, $orderId]);
$items = $stmt->fetchAll();

// Cek apakah semua sudah di-review
$allReviewed = true;
$reviewedCount = 0;
foreach ($items as $item) {
    if ($item['is_reviewed']) {
        $reviewedCount++;
    } else {
        $allReviewed = false;
    }
}

// Jika semua sudah di-review, redirect ke orders
if ($allReviewed && !empty($items)) {
    setFlash('success', 'Semua produk sudah diulas! Terima kasih.');
    redirect(BASE_URL . 'views/buyer/orders.php');
}

if (empty($items)) {
    setFlash('warning', 'Tidak ada produk dalam pesanan ini');
    redirect(BASE_URL . 'views/buyer/orders.php');
}

// Proses submit review
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid CSRF token');
        redirect(BASE_URL . 'views/buyer/review.php?order_id=' . $orderId);
    }
    
    $productId = (int) ($_POST['product_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = sanitize($_POST['comment'] ?? '');
    
    if ($productId <= 0 || $rating < 1 || $rating > 5) {
        setFlash('danger', 'Data ulasan tidak valid');
        redirect(BASE_URL . 'views/buyer/review.php?order_id=' . $orderId);
    }
    
    // Cek apakah sudah di-review
    $stmt = $db->prepare("
        SELECT id FROM reviews WHERE product_id = ? AND order_id = ? AND user_id = ?
    ");
    $stmt->execute([$productId, $orderId, $userId]);
    if ($stmt->fetch()) {
        setFlash('warning', 'Produk ini sudah diulas');
        redirect(BASE_URL . 'views/buyer/review.php?order_id=' . $orderId);
    }
    
    // Upload gambar jika ada
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadFile($_FILES['image'], 'reviews');
        if ($uploadResult !== false) {
            $imagePath = $uploadResult;
        }
    }
    
    // Insert review
    $stmt = $db->prepare("
        INSERT INTO reviews (product_id, user_id, order_id, rating, comment, image, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $result = $stmt->execute([$productId, $userId, $orderId, $rating, $comment, $imagePath]);
    
    if ($result) {
        setFlash('success', 'Ulasan untuk produk berhasil dikirim!');
        // Redirect kembali ke halaman review untuk produk berikutnya
        redirect(BASE_URL . 'views/buyer/review.php?order_id=' . $orderId);
    } else {
        setFlash('danger', 'Gagal mengirim ulasan. Silakan coba lagi.');
        redirect(BASE_URL . 'views/buyer/review.php?order_id=' . $orderId);
    }
}

$csrfToken = generateCsrfToken();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beri Ulasan - FoodDelivery</title>
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    
    <style>
        .review-item-img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 10px;
        }
        .rating-star {
            font-size: 32px;
            cursor: pointer;
            transition: all 0.2s;
            color: #d1d5db;
        }
        .rating-star:hover,
        .rating-star.active {
            color: #f59e0b;
            transform: scale(1.1);
        }
        .rating-star.active {
            color: #f59e0b;
        }
        .preview-img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 10px;
            margin-top: 10px;
            border: 2px solid #e5e7eb;
        }
        .reviewed-badge {
            background: #dcfce7;
            color: #16a34a;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .progress-bar-container {
            background: #f1f5f9;
            border-radius: 10px;
            padding: 12px 20px;
        }
        @media (max-width: 768px) {
            .rating-star {
                font-size: 28px;
            }
            .review-item-img {
                width: 60px;
                height: 60px;
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
                        <i class="bi bi-arrow-left"></i> Kembali
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= sanitize(getUser()['name'] ?? 'User') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>views/buyer/profile.php"><i class="bi bi-person me-2"></i>Profil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>controllers/AuthController.php?action=logout_redirect"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
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
    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <div class="text-center mb-4">
                <h4 class="fw-bold">
                    <i class="bi bi-star text-warning"></i> Beri Ulasan
                </h4>
                <p class="text-muted">Pesanan #<?= $order['order_code'] ?></p>
                
                <!-- ============ PROGRESS REVIEW ============ -->
                <div class="progress-bar-container">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted">Progress Ulasan</span>
                        <span class="fw-bold"><?= $reviewedCount ?>/<?= count($items) ?></span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" role="progressbar" 
                             style="width: <?= ($reviewedCount / count($items)) * 100 ?>%;" 
                             aria-valuenow="<?= ($reviewedCount / count($items)) * 100 ?>" 
                             aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                </div>
            </div>
            
            <?php 
            $foundUnreviewed = false;
            foreach ($items as $item): 
                if ($item['is_reviewed']) {
                    // Sudah di-review - tampilkan sebagai "Sudah Diulas"
                    continue;
                }
                $foundUnreviewed = true;
            ?>
            <!-- ============ FORM REVIEW UNTUK 1 PRODUK ============ -->
            <div class="bg-white rounded-3 border p-4 mb-3">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                    
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <img src="<?= $item['image'] ? UPLOAD_URL . $item['image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                             alt="<?= sanitize($item['product_name'] ?? 'Produk') ?>"
                             class="review-item-img"
                             onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                        <div>
                            <h6 class="fw-bold mb-0"><?= sanitize($item['product_name'] ?? 'Produk') ?></h6>
                            <small class="text-muted">×<?= $item['quantity'] ?></small>
                        </div>
                        <span class="ms-auto badge bg-warning text-dark">Belum diulas</span>
                    </div>
                    
                    <!-- Rating -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Rating <span class="text-danger">*</span></label>
                        <div class="rating-stars" id="rating-<?= $item['product_id'] ?>">
                            <i class="bi bi-star rating-star" data-value="1" onclick="setRating(<?= $item['product_id'] ?>, 1)"></i>
                            <i class="bi bi-star rating-star" data-value="2" onclick="setRating(<?= $item['product_id'] ?>, 2)"></i>
                            <i class="bi bi-star rating-star" data-value="3" onclick="setRating(<?= $item['product_id'] ?>, 3)"></i>
                            <i class="bi bi-star rating-star" data-value="4" onclick="setRating(<?= $item['product_id'] ?>, 4)"></i>
                            <i class="bi bi-star rating-star" data-value="5" onclick="setRating(<?= $item['product_id'] ?>, 5)"></i>
                            <input type="hidden" name="rating" id="rating-input-<?= $item['product_id'] ?>" value="0" required>
                        </div>
                        <div class="invalid-feedback">Pilih rating minimal 1 bintang</div>
                    </div>
                    
                    <!-- Komentar -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Komentar</label>
                        <textarea name="comment" class="form-control" rows="3" 
                                  placeholder="Tulis pengalaman Anda menikmati menu ini..."></textarea>
                    </div>
                    
                    <!-- Upload Foto -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Foto (Opsional)</label>
                        <input type="file" name="image" class="form-control" accept="image/*" 
                               onchange="previewImage(this, 'preview-<?= $item['product_id'] ?>')">
                        <img id="preview-<?= $item['product_id'] ?>" class="preview-img d-none">
                    </div>
                    
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="bi bi-send me-2"></i> Kirim Ulasan
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
            
            <!-- ============ PRODUK YANG SUDAH DI-REVIEW ============ -->
            <?php foreach ($items as $item): 
                if (!$item['is_reviewed']) continue;
            ?>
            <div class="bg-light rounded-3 border p-3 mb-2 d-flex align-items-center">
                <img src="<?= $item['image'] ? UPLOAD_URL . $item['image'] : BASE_URL . 'assets/images/no-food.jpg' ?>" 
                     alt="<?= sanitize($item['product_name'] ?? 'Produk') ?>"
                     style="width: 48px; height: 48px; object-fit: cover; border-radius: 8px;"
                     onerror="this.src='<?= BASE_URL ?>assets/images/no-food.jpg'">
                <div class="ms-3 flex-grow-1">
                    <span class="fw-semibold"><?= sanitize($item['product_name'] ?? 'Produk') ?></span>
                    <span class="ms-2 text-muted">×<?= $item['quantity'] ?></span>
                </div>
                <span class="reviewed-badge">
                    <i class="bi bi-check-circle me-1"></i> Sudah Diulas
                </span>
            </div>
            <?php endforeach; ?>
            
            <!-- ============ TOMBOL SELESAI ============ -->
            <?php if (!$foundUnreviewed && !empty($items)): ?>
            <div class="text-center mt-4">
                <div class="bg-success bg-opacity-10 rounded-3 p-4 border border-success">
                    <i class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"></i>
                    <h5 class="fw-bold text-success">Semua produk sudah diulas!</h5>
                    <p class="text-muted">Terima kasih atas ulasan Anda.</p>
                    <a href="<?= BASE_URL ?>views/buyer/orders.php" class="btn btn-success mt-2">
                        <i class="bi bi-arrow-left me-2"></i> Kembali ke Pesanan
                    </a>
                </div>
            </div>
            <?php elseif ($foundUnreviewed): ?>
            <div class="text-center mt-3">
                <a href="<?= BASE_URL ?>views/buyer/orders.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i> Lewati & Kembali
                </a>
                <small class="d-block text-muted mt-1">Ulasan yang belum dikirim bisa dilanjutkan nanti</small>
            </div>
            <?php endif; ?>
            
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

// =============================================
// SET RATING
// =============================================
function setRating(productId, rating) {
    const stars = document.querySelectorAll('#rating-' + productId + ' .rating-star');
    const input = document.getElementById('rating-input-' + productId);
    
    stars.forEach((star, index) => {
        if (index < rating) {
            star.className = 'bi bi-star-fill rating-star active';
        } else {
            star.className = 'bi bi-star rating-star';
        }
    });
    
    input.value = rating;
}

// =============================================
// PREVIEW IMAGE
// =============================================
function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    
    const file = input.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    } else {
        preview.src = '';
        preview.classList.add('d-none');
    }
}

// =============================================
// LOGOUT
// =============================================
function handleLogout(event) {
    event.preventDefault();
    if (!confirm('Yakin ingin logout?')) return;
    window.location.href = BASE_URL + 'controllers/AuthController.php?action=logout_redirect';
}
</script>

</body>
</html>