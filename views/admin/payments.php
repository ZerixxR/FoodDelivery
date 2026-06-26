<?php
/**
 * payments.php
 * Halaman verifikasi pembayaran untuk admin
 * 
 * @package FoodDelivery
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

// Cek login dan role
if (!isLoggedIn()) {
    redirect(BASE_URL . 'views/public/login.php');
}

if (getUserRole() !== 'admin') {
    redirect(BASE_URL . 'views/public/index.php');
}

$db = getDB();

// Proses Verifikasi (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid CSRF token');
        redirect(BASE_URL . 'views/admin/payments.php');
    }
    
    if ($action === 'verify') {
        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        $status = sanitize($_POST['status'] ?? '');
        $note = sanitize($_POST['note'] ?? '');
        
        if ($paymentId <= 0 || !in_array($status, ['verified', 'rejected'])) {
            setFlash('danger', 'Data tidak valid');
            redirect(BASE_URL . 'views/admin/payments.php');
        }
        
        // Ambil data payment
        $stmt = $db->prepare("
            SELECT p.*, o.order_code, o.buyer_id, o.seller_id
            FROM payments p
            JOIN orders o ON p.order_id = o.id
            WHERE p.id = ?
        ");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            setFlash('danger', 'Pembayaran tidak ditemukan');
            redirect(BASE_URL . 'views/admin/payments.php');
        }
        
        if ($payment['status'] !== 'uploaded') {
            setFlash('warning', 'Pembayaran sudah diproses sebelumnya');
            redirect(BASE_URL . 'views/admin/payments.php');
        }
        
        $adminId = (int) $_SESSION['user_id'];
        
        // Mulai transaction
        $db->beginTransaction();
        
        try {
            // Update payment
            $stmt = $db->prepare("
                UPDATE payments 
                SET status = ?, verified_by = ?, verified_at = NOW(), note = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$status, $adminId, $note, $paymentId]);
            
            if ($status === 'verified') {
                // Update order status ke confirmed
                $stmt = $db->prepare("
                    UPDATE orders SET status = 'confirmed', updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$payment['order_id']]);
                
                // Notifikasi ke buyer
                addNotification(
                    $payment['buyer_id'],
                    'Pembayaran Diverifikasi',
                    "Pembayaran untuk pesanan #{$payment['order_code']} telah diverifikasi. Pesanan akan segera diproses."
                );
                
                // Notifikasi ke seller
                addNotification(
                    $payment['seller_id'],
                    'Pembayaran Terkonfirmasi',
                    "Pembayaran untuk pesanan #{$payment['order_code']} telah terkonfirmasi. Segera proses pesanan."
                );
                
                setFlash('success', "Pembayaran #{$payment['order_code']} berhasil diverifikasi!");
                
            } else {
                // Rejected - reset payment
                $stmt = $db->prepare("
                    UPDATE payments SET proof = NULL, status = 'pending', updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$paymentId]);
                
                // Notifikasi ke buyer
                addNotification(
                    $payment['buyer_id'],
                    'Pembayaran Ditolak',
                    "Bukti pembayaran untuk pesanan #{$payment['order_code']} ditolak. Alasan: {$note}. Silakan upload ulang bukti yang valid."
                );
                
                setFlash('warning', "Pembayaran #{$payment['order_code']} ditolak!");
            }
            
            $db->commit();
            redirect(BASE_URL . 'views/admin/payments.php');
            
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('danger', 'Gagal memproses: ' . $e->getMessage());
            redirect(BASE_URL . 'views/admin/payments.php');
        }
    }
}

// Get tab
$tab = isset($_GET['tab']) ? sanitize($_GET['tab']) : 'pending';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query
$where = "WHERE 1=1";
$params = [];

switch ($tab) {
    case 'pending':
        $where .= " AND p.status = 'uploaded'";
        break;
    case 'verified':
        $where .= " AND p.status = 'verified'";
        break;
    case 'rejected':
        $where .= " AND p.status = 'rejected'";
        break;
    case 'waiting':
        $where .= " AND p.status = 'pending'";
        break;
    default:
        $where .= " AND p.status = 'uploaded'";
        $tab = 'pending';
}

// Count total
$countStmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    $where
");
$countStmt->execute($params);
$totalPayments = (int) $countStmt->fetch()['total'];
$totalPages = ceil($totalPayments / $limit);

// Get payments
$stmt = $db->prepare("
    SELECT 
        p.id as payment_id,
        p.payment_method,
        p.amount,
        p.status as payment_status,
        p.proof,
        p.created_at,
        p.verified_at,
        p.note,
        o.id as order_id,
        o.order_code,
        o.total_amount,
        o.shipping_address,
        u.name as buyer_name,
        u.email as buyer_email,
        u.phone as buyer_phone,
        a.name as verified_by_name
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    JOIN users u ON o.buyer_id = u.id
    LEFT JOIN users a ON p.verified_by = a.id
    $where
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$limit, $offset]));
$payments = $stmt->fetchAll();

// Count pending for badge
$pendingStmt = $db->query("SELECT COUNT(*) as total FROM payments WHERE status = 'uploaded'");
$pendingCount = (int) $pendingStmt->fetch()['total'];

$csrfToken = generateCsrfToken();
$flash = getFlash();

$statusConfig = [
    'pending' => ['label' => 'Menunggu Upload', 'color' => 'secondary'],
    'uploaded' => ['label' => 'Perlu Verifikasi', 'color' => 'warning'],
    'verified' => ['label' => 'Terverifikasi', 'color' => 'success'],
    'rejected' => ['label' => 'Ditolak', 'color' => 'danger']
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Pembayaran - FoodDelivery</title>
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
            background: #f8f0ff;
            color: #6f42c1;
        }
        .sidebar .nav-link.active {
            background: #6f42c1;
            color: white;
        }
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        .tab-filter .nav-link {
            color: #6c757d;
            font-weight: 500;
            border-radius: 8px;
            padding: 6px 16px;
            font-size: 13px;
        }
        .tab-filter .nav-link.active {
            color: #6f42c1;
            background: #f8f0ff;
        }
        .tab-filter .nav-link:hover:not(.active) {
            background: #f8f9fa;
        }
        .payment-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            transition: all 0.2s;
        }
        .payment-card:hover {
            border-color: #6f42c1;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .payment-card .proof-img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 1px solid #e9ecef;
        }
        .payment-card .proof-img:hover {
            opacity: 0.9;
        }
        .status-badge {
            font-size: 11px;
            padding: 3px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        .btn-verify {
            padding: 6px 20px;
            font-weight: 600;
        }
        .pagination .page-link {
            color: #6f42c1;
        }
        .pagination .page-item.active .page-link {
            background-color: #6f42c1;
            border-color: #6f42c1;
            color: white;
        }
        .modal-proof-img {
            max-width: 100%;
            max-height: 80vh;
            object-fit: contain;
        }
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid #e9ecef;
            }
            .payment-card .proof-img {
                height: 100px;
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
                <i class="bi bi-shield-lock me-1"></i> Admin
            </span>
            <a href="<?= BASE_URL ?>controllers/AuthController.php?action=logout_redirect" 
               class="btn btn-outline-danger btn-sm">
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
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close" 
                style="position: absolute; top: 50%; right: 8px; transform: translateY(-50%); z-index: 2; padding: 0.5rem; font-size: 12px;"></button>
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
                    <div class="fw-bold text-primary">Admin Panel</div>
                    <small class="text-muted">FoodDelivery</small>
                </div>
                <nav class="nav flex-column">
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/orders.php">
                        <i class="bi bi-bag"></i> Semua Pesanan
                    </a>
                    <a class="nav-link active" href="<?= BASE_URL ?>views/admin/payments.php">
                        <i class="bi bi-credit-card"></i> Verifikasi Bayar
                        <?php if ($pendingCount > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-2"><?= $pendingCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/users.php">
                        <i class="bi bi-people"></i> Kelola User
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/sellers.php">
                        <i class="bi bi-shop"></i> Kelola Seller
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/drivers.php">
                        <i class="bi bi-truck"></i> Kelola Driver
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/categories.php">
                        <i class="bi bi-tags"></i> Kategori
                    </a>
                    <a class="nav-link" href="<?= BASE_URL ?>views/admin/reviews.php">
                        <i class="bi bi-star"></i> Ulasan
                    </a>
                </nav>
            </div>
        </div>
        
        <!-- ============ CONTENT ============ -->
        <div class="col-lg-10 col-md-9 col-12 p-4">
            <h4 class="fw-bold mb-4">
                <i class="bi bi-credit-card text-primary"></i> Verifikasi Pembayaran
                <?php if ($pendingCount > 0): ?>
                <span class="badge bg-danger ms-2"><?= $pendingCount ?> perlu verifikasi</span>
                <?php endif; ?>
            </h4>
            
            <!-- ============ TABS ============ -->
            <ul class="nav nav-pills tab-filter mb-4 gap-1 flex-wrap">
                <li class="nav-item">
                    <a class="nav-link <?= $tab === 'pending' ? 'active' : '' ?>" href="?tab=pending">
                        Perlu Verifikasi
                        <?php if ($pendingCount > 0): ?>
                        <span class="badge bg-danger ms-1"><?= $pendingCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $tab === 'verified' ? 'active' : '' ?>" href="?tab=verified">
                        Terverifikasi
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $tab === 'rejected' ? 'active' : '' ?>" href="?tab=rejected">
                        Ditolak
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $tab === 'waiting' ? 'active' : '' ?>" href="?tab=waiting">
                        Menunggu Upload
                    </a>
                </li>
            </ul>
            
            <!-- ============ PAYMENTS LIST ============ -->
            <?php if (empty($payments)): ?>
            <div class="text-center py-5 bg-white rounded-3 border">
                <div style="font-size: 64px; color: #dee2e6;">
                    <i class="bi bi-credit-card"></i>
                </div>
                <h5 class="fw-bold mt-3">Tidak ada pembayaran</h5>
                <p class="text-muted">Tidak ada data pembayaran untuk tab ini.</p>
            </div>
            <?php else: ?>
            <div class="row g-4">
                <?php foreach ($payments as $payment): 
                    $status = $statusConfig[$payment['payment_status']] ?? ['label' => $payment['payment_status'], 'color' => 'secondary'];
                    $isPending = $payment['payment_status'] === 'uploaded';
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="payment-card p-3">
                        <!-- Header -->
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <span class="fw-bold text-primary">#<?= $payment['order_code'] ?></span>
                                <br><small class="text-muted"><?= sanitize($payment['buyer_name']) ?></small>
                            </div>
                            <span class="status-badge bg-<?= $status['color'] ?> text-white">
                                <?= $status['label'] ?>
                            </span>
                        </div>
                        
                        <!-- Detail -->
                        <div class="small mb-2">
                            <div><strong>Total:</strong> <?= formatRupiah($payment['total_amount']) ?></div>
                            <div><strong>Metode:</strong> <?= strtoupper($payment['payment_method']) ?></div>
                            <div><strong>Upload:</strong> <?= date('d/m/Y H:i', strtotime($payment['created_at'])) ?></div>
                            <div><strong>HP:</strong> <?= $payment['buyer_phone'] ?? '-' ?></div>
                            <?php if ($payment['verified_at']): ?>
                            <div><strong>Diverifikasi:</strong> <?= date('d/m/Y H:i', strtotime($payment['verified_at'])) ?></div>
                            <div><strong>Oleh:</strong> <?= sanitize($payment['verified_by_name'] ?? 'Admin') ?></div>
                            <?php endif; ?>
                            <?php if ($payment['note']): ?>
                            <div class="mt-1 text-danger"><strong>Catatan:</strong> <?= sanitize($payment['note']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Bukti Gambar -->
                        <?php if ($payment['proof']): ?>
                        <div class="mb-2">
                            <img src="<?= UPLOAD_URL . $payment['proof'] ?>" 
                                 alt="Bukti Pembayaran"
                                 class="proof-img w-100"
                                 onclick="openProofModal('<?= UPLOAD_URL . $payment['proof'] ?>')"
                                 data-bs-toggle="modal" data-bs-target="#proofModal">
                        </div>
                        <?php else: ?>
                        <div class="bg-light rounded-3 p-3 text-center text-muted mb-2">
                            <i class="bi bi-image fs-2 d-block"></i>
                            <small>Belum upload bukti</small>
                        </div>
                        <?php endif; ?>
                        
                        <!-- ============================================================ -->
                        <!-- ============ ACTIONS - PERBAIKAN ============ -->
                        <!-- ============================================================ -->
                        <?php if ($isPending): ?>
                        <div class="d-flex gap-2 mt-2">
                            <!-- FORM VERIFIKASI -->
                            <form method="POST" class="flex-grow-1" 
                                  onsubmit="return confirm('Verifikasi pembayaran #<?= $payment['order_code'] ?>?')">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="payment_id" value="<?= $payment['payment_id'] ?>">
                                <input type="hidden" name="status" value="verified">
                                <input type="hidden" name="note" value="">
                                <button type="submit" class="btn btn-success btn-verify w-100">
                                    <i class="bi bi-check-circle"></i> Verifikasi
                                </button>
                            </form>
                            
                            <!-- FORM TOLAK (dengan alasan) -->
                            <button class="btn btn-danger btn-verify" onclick="showRejectModal(<?= $payment['payment_id'] ?>, '<?= $payment['order_code'] ?>')">
                                <i class="bi bi-x-circle"></i> Tolak
                            </button>
                        </div>
                        <?php elseif ($payment['payment_status'] === 'verified'): ?>
                        <div class="text-center text-success mt-2">
                            <i class="bi bi-check-circle-fill me-1"></i> Sudah diverifikasi
                        </div>
                        <?php elseif ($payment['payment_status'] === 'rejected'): ?>
                        <div class="text-center text-danger mt-2">
                            <i class="bi bi-x-circle-fill me-1"></i> Ditolak
                        </div>
                        <?php else: ?>
                        <div class="text-center text-muted mt-2">
                            <i class="bi bi-clock me-1"></i> Menunggu upload
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- ============ PAGINATION ============ -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?tab=<?= $tab ?>&page=<?= $page - 1 ?>">Sebelumnya</a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?tab=<?= $tab ?>&page=<?= $i ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?tab=<?= $tab ?>&page=<?= $page + 1 ?>">Selanjutnya</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============ MODAL PROOF ============ -->
<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-image text-primary"></i> Bukti Pembayaran</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="proofModalImg" src="" alt="Bukti Pembayaran" class="modal-proof-img">
            </div>
        </div>
    </div>
</div>

<!-- ============ MODAL REJECT ============ -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle"></i> Tolak Pembayaran</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" onsubmit="return confirm('Tolak pembayaran ini?')">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="verify">
                    <input type="hidden" name="payment_id" id="rejectPaymentId" value="">
                    <input type="hidden" name="status" value="rejected">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Alasan Penolakan <span class="text-danger">*</span></label>
                        <textarea name="note" class="form-control" rows="3" required 
                                  placeholder="Berikan alasan penolakan..."></textarea>
                        <div class="invalid-feedback">Alasan penolakan wajib diisi</div>
                    </div>
                    <div class="alert alert-warning small">
                        <i class="bi bi-info-circle me-2"></i>
                        Buyer akan menerima notifikasi dan diminta upload ulang bukti.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle me-2"></i> Tolak
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="bg-dark text-white text-center py-3 mt-4">
    <div class="container">
        <small class="text-secondary">FoodDelivery &copy; 2025 - Verifikasi Pembayaran</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');

// =============================================
// OPEN PROOF MODAL
// =============================================
function openProofModal(imageUrl) {
    document.getElementById('proofModalImg').src = imageUrl;
}

// =============================================
// SHOW REJECT MODAL
// =============================================
function showRejectModal(paymentId, orderCode) {
    document.getElementById('rejectPaymentId').value = paymentId;
    document.querySelector('#rejectModal .modal-title').textContent = 
        'Tolak Pembayaran #' + orderCode;
    const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
    modal.show();
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