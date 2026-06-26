<?php
/**
 * PaymentController.php
 * Menangani upload bukti pembayaran dan verifikasi admin
 * 
 * @package FoodDelivery
 * @version 1.0
 */

// Load konfigurasi
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

/**
 * Class PaymentController
 */
class PaymentController
{
    private PDO $db;
    
    public function __construct()
    {
        $this->db = getDB();
    }

    /**
     * Validasi akses buyer
     * 
     * @return array|null Response error atau null jika valid
     */
    private function validateBuyerAccess()
    {
        if (!isLoggedIn()) {
            return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
        }
        
        if (getUserRole() !== 'buyer') {
            return ['success' => false, 'message' => 'Akses hanya untuk pembeli'];
        }
        
        return null;
    }

    /**
     * Validasi akses admin
     * 
     * @return array|null Response error atau null jika valid
     */
    private function validateAdminAccess()
    {
        if (!isLoggedIn()) {
            return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
        }
        
        if (getUserRole() !== 'admin') {
            return ['success' => false, 'message' => 'Akses hanya untuk admin'];
        }
        
        return null;
    }

    /**
     * Cek apakah order milik buyer
     * 
     * @param int $orderId
     * @param int $buyerId
     * @return bool
     */
    private function isOrderOwner($orderId, $buyerId)
    {
        $stmt = $this->db->prepare("
            SELECT id FROM orders WHERE id = ? AND buyer_id = ?
        ");
        $stmt->execute([$orderId, $buyerId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Get order detail by order_id
     * 
     * @param int $orderId
     * @return array|null
     */
    private function getOrderDetail($orderId)
    {
        $stmt = $this->db->prepare("
            SELECT o.*, u.name as buyer_name, u.email as buyer_email,
                   p.id as payment_id, p.status as payment_status, p.proof
            FROM orders o
            JOIN users u ON o.buyer_id = u.id
            LEFT JOIN payments p ON o.id = p.order_id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        return $stmt->fetch();
    }

    /**
     * Action: UPLOAD_PROOF - Upload bukti pembayaran
     * 
     * @param array $data POST data
     * @param array $files $_FILES data
     * @return array Response
     */
    public function uploadProof($data, $files)
    {
        try {
            // Validasi akses buyer
            $accessError = $this->validateBuyerAccess();
            if ($accessError) return $accessError;

            // Validasi CSRF
            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            $buyerId = (int) $_SESSION['user_id'];

            // Validasi order_id
            if (empty($data['order_id'])) {
                return ['success' => false, 'message' => 'Order ID tidak valid'];
            }

            $orderId = (int) $data['order_id'];

            // Cek order milik buyer
            if (!$this->isOrderOwner($orderId, $buyerId)) {
                return ['success' => false, 'message' => 'Anda tidak memiliki akses ke pesanan ini'];
            }

            // Cek order detail
            $order = $this->getOrderDetail($orderId);
            if (!$order) {
                return ['success' => false, 'message' => 'Pesanan tidak ditemukan'];
            }

            // Cek status order (hanya pending yang bisa upload)
            if ($order['status'] !== 'pending') {
                return ['success' => false, 'message' => 'Pesanan sudah diproses, tidak bisa upload bukti'];
            }

            // Cek apakah sudah upload bukti sebelumnya
            if ($order['payment_status'] === 'uploaded') {
                return ['success' => false, 'message' => 'Anda sudah mengupload bukti, menunggu verifikasi admin'];
            }

            // Validasi file
            if (!isset($files['proof']) || $files['proof']['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'Silakan pilih file bukti pembayaran'];
            }

            // Upload bukti
            $uploadResult = uploadFile($files['proof'], 'payments');
            if ($uploadResult === false) {
                return ['success' => false, 'message' => 'Gagal upload bukti. Format: JPG/PNG/WEBP, max 2MB'];
            }

            $proofPath = $uploadResult;

            // Update payment
            $stmt = $this->db->prepare("
                UPDATE payments 
                SET proof = ?, status = 'uploaded', updated_at = NOW() 
                WHERE order_id = ?
            ");
            $stmt->execute([$proofPath, $orderId]);

            if ($stmt->rowCount() == 0) {
                return ['success' => false, 'message' => 'Gagal menyimpan bukti pembayaran'];
            }

            // Add notification ke admin
            $adminMessage = "Pembayaran untuk pesanan #{$order['order_code']} dari {$order['buyer_name']} menunggu verifikasi.";
            $stmt = $this->db->prepare("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
            $stmt->execute();
            $admins = $stmt->fetchAll();
            foreach ($admins as $admin) {
                addNotification(
                    $admin['id'],
                    'Verifikasi Pembayaran',
                    $adminMessage
                );
            }

            // Add notification ke buyer
            addNotification(
                $buyerId,
                'Bukti Pembayaran Terkirim',
                "Bukti pembayaran untuk pesanan #{$order['order_code']} telah terkirim. Menunggu verifikasi admin."
            );

            setFlash('success', 'Bukti pembayaran berhasil diupload! Menunggu verifikasi admin.');

            return [
                'success' => true,
                'message' => 'Bukti pembayaran berhasil diupload',
                'redirect' => BASE_URL . 'views/buyer/orders.php'
            ];

        } catch (PDOException $e) {
            error_log("Upload Proof Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'];
        }
    }

    /**
     * Action: VERIFY - Verifikasi pembayaran (admin)
     * 
     * @param array $data POST data
     * @return array Response
     */
    public function verify($data)
    {
        try {
            // Validasi akses admin
            $accessError = $this->validateAdminAccess();
            if ($accessError) return $accessError;

            // Validasi CSRF
            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            $adminId = (int) $_SESSION['user_id'];

            // Validasi input
            if (empty($data['payment_id']) || empty($data['status'])) {
                return ['success' => false, 'message' => 'Data tidak lengkap'];
            }

            $paymentId = (int) $data['payment_id'];
            $status = sanitize($data['status']);

            // Validasi status
            if (!in_array($status, ['verified', 'rejected'])) {
                return ['success' => false, 'message' => 'Status tidak valid'];
            }

            // Ambil data payment dengan order
            $stmt = $this->db->prepare("
                SELECT p.*, o.order_code, o.buyer_id, o.seller_id, o.status as order_status
                FROM payments p
                JOIN orders o ON p.order_id = o.id
                WHERE p.id = ?
            ");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();

            if (!$payment) {
                return ['success' => false, 'message' => 'Data pembayaran tidak ditemukan'];
            }

            // Cek status payment (hanya uploaded yang bisa diverifikasi)
            if ($payment['status'] !== 'uploaded') {
                return ['success' => false, 'message' => 'Pembayaran sudah diproses sebelumnya'];
            }

            // Cek status order (harus pending)
            if ($payment['order_status'] !== 'pending') {
                return ['success' => false, 'message' => 'Pesanan sudah diproses, tidak bisa verifikasi'];
            }

            // Mulai transaction
            $this->db->beginTransaction();

            try {
                // Update payment
                $stmt = $this->db->prepare("
                    UPDATE payments 
                    SET status = ?, verified_by = ?, verified_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$status, $adminId, $paymentId]);

                if ($status === 'verified') {
                    // Update order status ke confirmed
                    $stmt = $this->db->prepare("
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

                } else {
                    // Rejected - buyer diminta upload ulang
                    // Reset payment status ke pending (belum ada bukti)
                    $stmt = $this->db->prepare("
                        UPDATE payments SET proof = NULL, status = 'pending', updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$paymentId]);

                    // Notifikasi ke buyer
                    addNotification(
                        $payment['buyer_id'],
                        'Pembayaran Ditolak',
                        "Bukti pembayaran untuk pesanan #{$payment['order_code']} ditolak. Silakan upload bukti yang valid."
                    );
                }

                $this->db->commit();

                $statusLabel = $status === 'verified' ? 'diverifikasi' : 'ditolak';
                setFlash('success', "Pembayaran #{$payment['order_code']} berhasil {$statusLabel}!");

                return [
                    'success' => true,
                    'message' => "Pembayaran berhasil {$statusLabel}",
                    'redirect' => BASE_URL . 'views/admin/payments.php'
                ];

            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }

        } catch (PDOException $e) {
            error_log("Verify Payment Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'];
        }
    }

    /**
     * Action: GET_PAYMENTS - Ambil daftar pembayaran (admin)
     * 
     * @param array $data GET data
     * @return array JSON response
     */
    public function getPayments($data)
    {
        try {
            if (!isLoggedIn() || getUserRole() !== 'admin') {
                return ['success' => false, 'message' => 'Akses ditolak'];
            }

            $status = isset($data['status']) ? sanitize($data['status']) : 'all';

            $query = "
                SELECT p.*, o.order_code, o.total_amount, 
                       u.name as buyer_name, u.email as buyer_email,
                       a.name as verified_by_name
                FROM payments p
                JOIN orders o ON p.order_id = o.id
                JOIN users u ON o.buyer_id = u.id
                LEFT JOIN users a ON p.verified_by = a.id
            ";

            $params = [];
            if ($status !== 'all') {
                $query .= " WHERE p.status = ?";
                $params[] = $status;
            }

            $query .= " ORDER BY p.created_at DESC LIMIT 50";

            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $payments = $stmt->fetchAll();

            return [
                'success' => true,
                'payments' => $payments,
                'count' => count($payments)
            ];

        } catch (PDOException $e) {
            error_log("Get Payments Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }
}

// ============================================
// HANDLE REQUEST
// ============================================

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $controller = new PaymentController();
    
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'upload_proof':
            echo json_encode($controller->uploadProof($_POST, $_FILES));
            break;
            
        case 'verify':
            echo json_encode($controller->verify($_POST));
            break;
            
        case 'get_payments':
            echo json_encode($controller->getPayments($_GET));
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
}
?>