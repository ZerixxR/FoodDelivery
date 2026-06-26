<?php
/**
 * TrackingController.php
 * Menangani tracking pesanan real-time dan assignment driver
 * 
 * @package FoodDelivery
 * @version 1.0
 */

// Load konfigurasi
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

class TrackingController
{
    private PDO $db;
    
    private const STATUS_STEPS = [
        'pending' => ['label' => 'Menunggu Konfirmasi', 'icon' => 'clock', 'order' => 0],
        'confirmed' => ['label' => 'Pesanan Dikonfirmasi', 'icon' => 'check-circle', 'order' => 1],
        'cooking' => ['label' => 'Sedang Dimasak', 'icon' => 'fire', 'order' => 2],
        'on_delivery' => ['label' => 'Sedang Diantar', 'icon' => 'truck', 'order' => 3],
        'delivered' => ['label' => 'Telah Sampai', 'icon' => 'check-circle-fill', 'order' => 4],
        'cancelled' => ['label' => 'Dibatalkan', 'icon' => 'x-circle', 'order' => -1]
    ];
    
    private $statusLabels = [
        'pending' => 'Menunggu Konfirmasi',
        'confirmed' => 'Dikonfirmasi',
        'cooking' => 'Sedang Dimasak',
        'on_delivery' => 'Sedang Diantar',
        'delivered' => 'Selesai',
        'cancelled' => 'Dibatalkan'
    ];
    
    public function __construct()
    {
        $this->db = getDB();
    }

    /**
     * Action: GET_TRACKING - Get tracking data
     */
    public function getTracking($data)
    {
        try {
            if (!isLoggedIn()) {
                return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
            }

            $userId = (int) $_SESSION['user_id'];
            $userRole = getUserRole();

            if (empty($data['order_id'])) {
                return ['success' => false, 'message' => 'Order ID required'];
            }

            $orderId = (int) $data['order_id'];

            $stmt = $this->db->prepare("
                SELECT o.id, o.buyer_id, o.seller_id, o.driver_id
                FROM orders o
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Pesanan tidak ditemukan'];
            }

            $canAccess = false;
            if ($userRole === 'admin') $canAccess = true;
            if ($userRole === 'buyer' && $order['buyer_id'] == $userId) $canAccess = true;
            if ($userRole === 'seller' && $order['seller_id'] == $userId) $canAccess = true;
            if ($userRole === 'driver' && $order['driver_id'] == $userId) $canAccess = true;

            if (!$canAccess) {
                return ['success' => false, 'message' => 'Anda tidak memiliki akses'];
            }

            $stmt = $this->db->prepare("
                SELECT 
                    o.id, o.order_code, o.status, o.created_at, o.updated_at,
                    o.shipping_address, o.driver_id, o.estimated_time,
                    u.name as buyer_name, u.phone as buyer_phone,
                    s.name as seller_name,
                    d.name as driver_name, d.phone as driver_phone
                FROM orders o
                JOIN users u ON o.buyer_id = u.id
                JOIN users s ON o.seller_id = s.id
                LEFT JOIN users d ON o.driver_id = d.id
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            $orderDetail = $stmt->fetch();

            $currentStatus = $orderDetail['status'];
            $currentOrder = self::STATUS_STEPS[$currentStatus]['order'] ?? -1;

            $steps = [];
            foreach (self::STATUS_STEPS as $key => $step) {
                if ($key === 'cancelled') {
                    $steps[] = [
                        'status' => $key,
                        'label' => $step['label'],
                        'icon' => $step['icon'],
                        'is_done' => false,
                        'is_cancelled' => true
                    ];
                } else {
                    $steps[] = [
                        'status' => $key,
                        'label' => $step['label'],
                        'icon' => $step['icon'],
                        'is_done' => $step['order'] <= $currentOrder,
                        'is_current' => $step['order'] === $currentOrder
                    ];
                }
            }

            return [
                'success' => true,
                'order' => [
                    'id' => $orderDetail['id'],
                    'order_code' => $orderDetail['order_code'],
                    'status' => $orderDetail['status'],
                    'status_label' => self::STATUS_STEPS[$currentStatus]['label'] ?? $currentStatus,
                    'created_at' => $orderDetail['created_at'],
                    'updated_at' => $orderDetail['updated_at'],
                    'shipping_address' => $orderDetail['shipping_address'],
                    'estimated_time' => $orderDetail['estimated_time'],
                    'buyer_name' => $orderDetail['buyer_name'],
                    'buyer_phone' => $orderDetail['buyer_phone'],
                    'seller_name' => $orderDetail['seller_name'],
                    'driver_name' => $orderDetail['driver_name'],
                    'driver_phone' => $orderDetail['driver_phone']
                ],
                'steps' => $steps,
                'is_cancelled' => $currentStatus === 'cancelled'
            ];

        } catch (PDOException $e) {
            error_log("Get Tracking Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Action: ASSIGN_DRIVER - Assign driver ke order (admin only)
     */
    public function assignDriver($data)
    {
        try {
            // Validasi admin
            if (!isLoggedIn()) {
                return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
            }

            if (getUserRole() !== 'admin') {
                return ['success' => false, 'message' => 'Akses hanya untuk admin'];
            }

            // Validasi CSRF
            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            if (empty($data['order_id']) || empty($data['driver_id'])) {
                return ['success' => false, 'message' => 'Data tidak lengkap'];
            }

            $orderId = (int) $data['order_id'];
            $driverId = (int) $data['driver_id'];

            // Cek order
            $stmt = $this->db->prepare("
                SELECT o.id, o.order_code, o.status, o.buyer_id, o.driver_id, u.name as buyer_name
                FROM orders o
                JOIN users u ON o.buyer_id = u.id
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Pesanan tidak ditemukan'];
            }

            // ============================================================
            // VALIDASI STATUS - PERBAIKAN DENGAN PESAN JELAS
            // ============================================================
            $allowedStatuses = ['confirmed', 'cooking'];
            
            // Jika status sudah on_delivery, beri tahu user
            if ($order['status'] === 'on_delivery') {
                return ['success' => false, 'message' => '❌ Pesanan sudah dalam pengiriman (status: Sedang Diantar). Tidak dapat mengassign driver baru.'];
            }
            
            // Jika status sudah delivered atau cancelled
            if (in_array($order['status'], ['delivered', 'cancelled'])) {
                $label = $this->statusLabels[$order['status']] ?? $order['status'];
                return ['success' => false, 'message' => "❌ Pesanan sudah {$label}. Tidak dapat mengassign driver."];
            }
            
            if (!in_array($order['status'], $allowedStatuses)) {
                $label = $this->statusLabels[$order['status']] ?? $order['status'];
                return ['success' => false, 'message' => "⚠️ Pesanan sedang dalam status '{$label}'. Hanya pesanan dengan status 'Dikonfirmasi' atau 'Sedang Dimasak' yang bisa diassign driver."];
            }

            // Cek driver
            $stmt = $this->db->prepare("
                SELECT id, name FROM users 
                WHERE id = ? AND role = 'driver' AND is_verified = 1 AND is_active = 1
            ");
            $stmt->execute([$driverId]);
            $driver = $stmt->fetch();

            if (!$driver) {
                return ['success' => false, 'message' => 'Driver tidak ditemukan atau tidak aktif'];
            }

            // Assign driver
            $stmt = $this->db->prepare("
                UPDATE orders SET driver_id = ?, updated_at = NOW() WHERE id = ?
            ");
            $result = $stmt->execute([$driverId, $orderId]);

            if (!$result || $stmt->rowCount() == 0) {
                return ['success' => false, 'message' => 'Gagal mengassign driver'];
            }

            // Notifikasi ke driver
            $this->addNotification(
                $driverId,
                'Pesanan Siap Diantar',
                "Pesanan #{$order['order_code']} dari {$order['buyer_name']} siap diantar."
            );

            // Notifikasi ke buyer
            $this->addNotification(
                $order['buyer_id'],
                'Driver Ditugaskan',
                "Driver {$driver['name']} telah ditugaskan untuk pesanan #{$order['order_code']}."
            );

            return [
                'success' => true,
                'message' => "✅ Driver berhasil ditugaskan untuk pesanan #{$order['order_code']}"
            ];

        } catch (PDOException $e) {
            error_log("Assign Driver Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()];
        }
    }

    /**
     * Action: UPDATE_DELIVERY - Update status delivery (driver only)
     */
    public function updateDelivery($data)
    {
        try {
            if (!isLoggedIn()) {
                return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
            }

            if (getUserRole() !== 'driver') {
                return ['success' => false, 'message' => 'Akses hanya untuk driver'];
            }

            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            if (empty($data['order_id']) || empty($data['status'])) {
                return ['success' => false, 'message' => 'Data tidak lengkap'];
            }

            $orderId = (int) $data['order_id'];
            $newStatus = sanitize($data['status']);
            $driverId = (int) $_SESSION['user_id'];

            if (!in_array($newStatus, ['on_delivery', 'delivered'])) {
                return ['success' => false, 'message' => 'Status tidak valid. Hanya on_delivery atau delivered'];
            }

            $stmt = $this->db->prepare("
                SELECT o.id, o.order_code, o.status, o.buyer_id, u.name as buyer_name
                FROM orders o
                JOIN users u ON o.buyer_id = u.id
                WHERE o.id = ? AND o.driver_id = ?
            ");
            $stmt->execute([$orderId, $driverId]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Pesanan tidak ditemukan atau bukan milik anda'];
            }

            if ($newStatus === 'on_delivery' && $order['status'] !== 'cooking') {
                return ['success' => false, 'message' => 'Pesanan harus status cooking untuk memulai pengiriman'];
            }

            if ($newStatus === 'delivered' && $order['status'] !== 'on_delivery') {
                return ['success' => false, 'message' => 'Pesanan harus status on_delivery untuk konfirmasi sampai'];
            }

            $estimatedTime = null;
            if ($newStatus === 'on_delivery') {
                $estimatedTime = date('Y-m-d H:i:s', strtotime('+45 minutes'));
            }

            $stmt = $this->db->prepare("
                UPDATE orders 
                SET status = ?, estimated_time = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $estimatedTime, $orderId]);

            $statusLabels = [
                'on_delivery' => 'Pesanan sedang dalam perjalanan',
                'delivered' => 'Pesanan telah sampai'
            ];

            $this->addNotification(
                $order['buyer_id'],
                'Update Pengiriman',
                "{$statusLabels[$newStatus]} untuk pesanan #{$order['order_code']}."
            );

            if ($newStatus === 'delivered') {
                $stmt = $this->db->prepare("SELECT seller_id FROM orders WHERE id = ?");
                $stmt->execute([$orderId]);
                $seller = $stmt->fetch();
                if ($seller) {
                    $this->addNotification(
                        $seller['seller_id'],
                        'Pesanan Selesai',
                        "Pesanan #{$order['order_code']} telah sampai ke pembeli."
                    );
                }
            }

            return [
                'success' => true,
                'message' => "Status pengiriman berhasil diupdate",
                'status' => $newStatus,
                'status_label' => $statusLabels[$newStatus],
                'estimated_time' => $estimatedTime ? date('H:i', strtotime($estimatedTime)) : null
            ];

        } catch (PDOException $e) {
            error_log("Update Delivery Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Action: GET_AVAILABLE_DRIVERS - Get available drivers (admin only)
     */
    public function getAvailableDrivers()
    {
        try {
            if (!isLoggedIn() || getUserRole() !== 'admin') {
                return ['success' => false, 'message' => 'Akses hanya untuk admin'];
            }

            $stmt = $this->db->prepare("
                SELECT id, name, email, phone 
                FROM users 
                WHERE role = 'driver' AND is_verified = 1 AND is_active = 1
                ORDER BY name
            ");
            $stmt->execute();
            $drivers = $stmt->fetchAll();

            return [
                'success' => true,
                'drivers' => $drivers
            ];

        } catch (PDOException $e) {
            error_log("Get Available Drivers Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Add notification helper
     */
    private function addNotification($userId, $title, $message)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications (user_id, title, message, is_read, created_at) 
                VALUES (?, ?, ?, 0, NOW())
            ");
            $stmt->execute([$userId, $title, $message]);
        } catch (Exception $e) {
            error_log("Add Notification Error: " . $e->getMessage());
        }
    }
}

// ============================================
// HANDLE REQUEST
// ============================================

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $controller = new TrackingController();
    
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'get_tracking':
            echo json_encode($controller->getTracking($_GET));
            break;
            
        case 'assign_driver':
            echo json_encode($controller->assignDriver($_POST));
            break;
            
        case 'update_delivery':
            echo json_encode($controller->updateDelivery($_POST));
            break;
            
        case 'get_available_drivers':
            echo json_encode($controller->getAvailableDrivers());
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
}
?>