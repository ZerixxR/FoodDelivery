<?php
/**
 * OrderController.php
 * Menangani semua operasi pesanan: checkout, update status, tracking
 * 
 * @package FoodDelivery
 */

// Load konfigurasi
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

/**
 * Class OrderController
 */
class OrderController
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
     * Get cart items dengan detail produk
     * 
     * @param int $userId
     * @return array
     */
    private function getCartItems($userId)
    {
        $stmt = $this->db->prepare("
            SELECT 
                c.id as cart_id,
                c.product_id,
                c.quantity,
                p.name,
                p.price,
                p.stock,
                p.seller_id,
                u.name as seller_name
            FROM cart c
            JOIN products p ON c.product_id = p.id
            JOIN users u ON p.seller_id = u.id
            WHERE c.user_id = ? AND p.is_active = 1 AND p.stock > 0
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Generate invoice text file
     * 
     * @param int $orderId
     * @param string $orderCode
     * @param array $orderData
     * @return bool
     */
    private function generateInvoice($orderId, $orderCode, $orderData)
    {
        try {
            $invoiceDir = UPLOAD_PATH . 'invoices/';
            if (!is_dir($invoiceDir)) {
                mkdir($invoiceDir, 0755, true);
            }

            $invoiceFile = $invoiceDir . 'INV-' . $orderCode . '.txt';
            
            $content = "========================================\n";
            $content .= "           FOODDELIVERY INVOICE          \n";
            $content .= "========================================\n\n";
            $content .= "Invoice Number: INV-{$orderCode}\n";
            $content .= "Order Code:     {$orderCode}\n";
            $content .= "Date:           " . date('d/m/Y H:i:s') . "\n\n";
            $content .= "----------------------------------------\n";
            $content .= "CUSTOMER INFORMATION\n";
            $content .= "----------------------------------------\n";
            $content .= "Name:    {$orderData['buyer_name']}\n";
            $content .= "Email:   {$orderData['buyer_email']}\n";
            $content .= "Phone:   {$orderData['buyer_phone']}\n";
            $content .= "Address: {$orderData['shipping_address']}\n\n";
            $content .= "----------------------------------------\n";
            $content .= "ORDER DETAILS\n";
            $content .= "----------------------------------------\n";
            
            $total = 0;
            foreach ($orderData['items'] as $item) {
                $subtotal = $item['price'] * $item['quantity'];
                $total += $subtotal;
                $content .= sprintf(
                    "%-30s x%3d  %10s\n",
                    substr($item['name'], 0, 30),
                    $item['quantity'],
                    'Rp ' . number_format($subtotal, 0, ',', '.')
                );
            }
            
            $content .= "\n----------------------------------------\n";
            $content .= sprintf("TOTAL: %38s\n", 'Rp ' . number_format($total, 0, ',', '.'));
            $content .= "----------------------------------------\n";
            $content .= "Payment Method: {$orderData['payment_method']}\n";
            $content .= "Status:         PENDING\n\n";
            $content .= "========================================\n";
            $content .= "Terima kasih telah berbelanja!\n";
            $content .= "========================================\n";

            return file_put_contents($invoiceFile, $content) !== false;
            
        } catch (Exception $e) {
            error_log("Generate Invoice Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Action: CHECKOUT - Proses checkout
     * 
     * @param array $data POST data
     * @return array JSON response
     */
    public function checkout($data)
    {
        try {
            // Validasi akses buyer
            $accessError = $this->validateBuyerAccess();
            if ($accessError) return $accessError;

            // Validasi CSRF
            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            $userId = (int) $_SESSION['user_id'];
            $user = getUser();

            // Validasi field pengiriman
            $required = ['shipping_address', 'payment_method'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => 'Semua field pengiriman harus diisi'];
                }
            }

            $shippingAddress = sanitize($data['shipping_address']);
            $paymentMethod = sanitize($data['payment_method']);
            $paymentMethod = in_array($paymentMethod, ['cod', 'bank_transfer', 'ewallet']) ? $paymentMethod : 'bank_transfer';

            // Ambil cart items
            $cartItems = $this->getCartItems($userId);
            
            if (empty($cartItems)) {
                return ['success' => false, 'message' => 'Keranjang belanja kosong'];
            }

            // Cek apakah semua item dari seller yang sama
            $uniqueSellers = array_unique(array_column($cartItems, 'seller_id'));
            if (count($uniqueSellers) > 1) {
                return ['success' => false, 'message' => 'Pesanan hanya bisa dari satu restoran'];
            }

            $sellerId = (int) $cartItems[0]['seller_id'];

            // Hitung total
            $totalAmount = 0;
            foreach ($cartItems as $item) {
                $totalAmount += $item['price'] * $item['quantity'];
            }

            // Generate order code (unik)
            $orderCode = $this->generateUniqueOrderCode();

            // Mulai transaction
            $this->db->beginTransaction();

            try {
                // 1. INSERT ke orders
                $stmt = $this->db->prepare("
                    INSERT INTO orders (
                        order_code, buyer_id, seller_id, total_amount, 
                        shipping_address, payment_method, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                
                $stmt->execute([
                    $orderCode,
                    $userId,
                    $sellerId,
                    $totalAmount,
                    $shippingAddress,
                    $paymentMethod
                ]);

                $orderId = (int) $this->db->lastInsertId();

                if ($orderId <= 0) {
                    throw new Exception("Gagal membuat pesanan");
                }

                // 2. INSERT order_items dan UPDATE stock
                $orderItems = [];
                foreach ($cartItems as $item) {
                    // Insert order_item
                    $stmt = $this->db->prepare("
                        INSERT INTO order_items (
                            order_id, product_id, quantity, price, product_name, product_image
                        ) VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $orderId,
                        (int) $item['product_id'],
                        (int) $item['quantity'],
                        (float) $item['price'],
                        $item['name'],
                        null
                    ]);

                    // UPDATE stock produk
                    $stmt = $this->db->prepare("
                        UPDATE products 
                        SET stock = stock - ?, sold = sold + ? 
                        WHERE id = ? AND stock >= ?
                    ");
                    $stmt->execute([
                        (int) $item['quantity'],
                        (int) $item['quantity'],
                        (int) $item['product_id'],
                        (int) $item['quantity']
                    ]);

                    // Cek apakah update stock berhasil
                    if ($stmt->rowCount() == 0) {
                        throw new Exception("Stok produk {$item['name']} tidak mencukupi");
                    }

                    $orderItems[] = $item;
                }

                // 3. INSERT ke payments (status pending)
                $stmt = $this->db->prepare("
                    INSERT INTO payments (
                        order_id, payment_method, amount, status, created_at
                    ) VALUES (?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([$orderId, $paymentMethod, $totalAmount]);

                // 4. DELETE cart user
                $stmt = $this->db->prepare("DELETE FROM cart WHERE user_id = ?");
                $stmt->execute([$userId]);

                // 5. Add notifications
                // Notifikasi ke buyer
                $this->addNotification(
                    $userId,
                    'Pesanan Dibuat',
                    "Pesanan #{$orderCode} berhasil dibuat. Silakan lakukan pembayaran."
                );

                // Notifikasi ke seller
                $this->addNotification(
                    $sellerId,
                    'Pesanan Masuk',
                    "Pesanan #{$orderCode} dari {$user['name']} telah masuk. Segera proses pesanan."
                );

                // Notifikasi ke admin
                $stmt = $this->db->prepare("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
                $stmt->execute();
                $admins = $stmt->fetchAll();
                foreach ($admins as $admin) {
                    $this->addNotification(
                        $admin['id'],
                        'Pesanan Baru',
                        "Pesanan #{$orderCode} dari {$user['name']} telah dibuat."
                    );
                }

                // 6. Generate invoice
                $orderData = [
                    'buyer_name' => $user['name'],
                    'buyer_email' => $user['email'],
                    'buyer_phone' => $data['phone'] ?? '-',
                    'shipping_address' => $shippingAddress,
                    'payment_method' => $paymentMethod,
                    'items' => $orderItems
                ];
                $this->generateInvoice($orderId, $orderCode, $orderData);

                // 7. Commit transaction
                $this->db->commit();

                setFlash('success', "Pesanan #{$orderCode} berhasil dibuat! Silakan lakukan pembayaran.");

                return [
                    'success' => true,
                    'message' => 'Pesanan berhasil dibuat',
                    'redirect' => BASE_URL . 'views/buyer/orders.php',
                    'order_code' => $orderCode
                ];

            } catch (Exception $e) {
                $this->db->rollBack();
                error_log("Checkout Transaction Error: " . $e->getMessage());
                return ['success' => false, 'message' => $e->getMessage()];
            }

        } catch (PDOException $e) {
            error_log("Checkout Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()];
        }
    }

    /**
     * Generate unique order code
     * 
     * @return string
     */
    private function generateUniqueOrderCode()
    {
        $code = generateOrderCode();
        
        // Cek apakah kode sudah ada
        $stmt = $this->db->prepare("SELECT id FROM orders WHERE order_code = ?");
        $stmt->execute([$code]);
        
        // Jika sudah ada, generate ulang
        while ($stmt->fetch()) {
            $code = generateOrderCode();
            $stmt->execute([$code]);
        }
        
        return $code;
    }

    /**
     * Add notification (wrapper for helper)
     * 
     * @param int $userId
     * @param string $title
     * @param string $message
     * @return void
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

    /**
     * Action: UPDATE_STATUS - Update status pesanan
     */
    public function updateStatus($data)
    {
        try {
            if (!isLoggedIn()) {
                return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
            }

            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            if (empty($data['order_id']) || empty($data['status'])) {
                return ['success' => false, 'message' => 'Data tidak lengkap'];
            }

            $orderId = (int) $data['order_id'];
            $newStatus = sanitize($data['status']);
            $userId = (int) $_SESSION['user_id'];
            $userRole = getUserRole();

            $allowedStatuses = ['confirmed', 'cooking', 'on_delivery', 'delivered', 'cancelled'];
            if (!in_array($newStatus, $allowedStatuses)) {
                return ['success' => false, 'message' => 'Status tidak valid'];
            }

            $stmt = $this->db->prepare("
                SELECT o.*, u.name as buyer_name, u.email as buyer_email
                FROM orders o
                JOIN users u ON o.buyer_id = u.id
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Pesanan tidak ditemukan'];
            }

            if (in_array($order['status'], ['delivered', 'cancelled'])) {
                return ['success' => false, 'message' => 'Pesanan sudah selesai/dibatalkan'];
            }

            // Cek akses berdasarkan role
            if ($userRole === 'seller') {
                if ($order['seller_id'] != $userId) {
                    return ['success' => false, 'message' => 'Anda tidak memiliki akses ke pesanan ini'];
                }
                if ($newStatus === 'cancelled') {
                    return ['success' => false, 'message' => 'Penjual tidak dapat membatalkan pesanan'];
                }
            } elseif ($userRole === 'admin') {
                // Admin bisa semua
            } elseif ($userRole === 'driver') {
                if (!in_array($newStatus, ['on_delivery', 'delivered'])) {
                    return ['success' => false, 'message' => 'Driver hanya bisa update status pengiriman'];
                }
            } elseif ($userRole === 'buyer') {
                if ($newStatus !== 'cancelled') {
                    return ['success' => false, 'message' => 'Pembeli hanya bisa membatalkan pesanan'];
                }
                if ($order['status'] !== 'pending') {
                    return ['success' => false, 'message' => 'Pesanan tidak dapat dibatalkan karena sedang diproses'];
                }
            } else {
                return ['success' => false, 'message' => 'Anda tidak memiliki izin'];
            }

            // Update status
            $stmt = $this->db->prepare("
                UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?
            ");
            $stmt->execute([$newStatus, $orderId]);

            // Jika status cancelled, update stok kembali
            if ($newStatus === 'cancelled') {
                $stmt = $this->db->prepare("
                    SELECT product_id, quantity FROM order_items WHERE order_id = ?
                ");
                $stmt->execute([$orderId]);
                $items = $stmt->fetchAll();

                foreach ($items as $item) {
                    $stmt = $this->db->prepare("
                        UPDATE products SET stock = stock + ? WHERE id = ?
                    ");
                    $stmt->execute([$item['quantity'], $item['product_id']]);
                }
            }

            $statusLabels = [
                'confirmed' => 'Pesanan dikonfirmasi',
                'cooking' => 'Pesanan sedang dimasak',
                'on_delivery' => 'Pesanan sedang diantar',
                'delivered' => 'Pesanan telah sampai',
                'cancelled' => 'Pesanan dibatalkan'
            ];

            $statusLabel = $statusLabels[$newStatus] ?? $newStatus;
            $message = "Pesanan #{$order['order_code']} status: {$statusLabel}";

            $this->addNotification($order['buyer_id'], 'Update Pesanan', $message);

            return [
                'success' => true,
                'message' => "Status pesanan berhasil diupdate",
                'status' => $newStatus,
                'status_label' => $statusLabel
            ];

        } catch (PDOException $e) {
            error_log("Update Status Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Action: GET_ORDERS - Ambil daftar pesanan user
     */
    public function getOrders($data)
    {
        try {
            if (!isLoggedIn()) {
                return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
            }

            $userId = (int) $_SESSION['user_id'];
            $userRole = getUserRole();
            $status = isset($data['status']) ? sanitize($data['status']) : 'all';
            
            $query = "
                SELECT 
                    o.*,
                    u.name as seller_name,
                    u2.name as buyer_name
                FROM orders o
                JOIN users u ON o.seller_id = u.id
                JOIN users u2 ON o.buyer_id = u2.id
            ";
            
            $where = [];
            $params = [];

            if ($userRole === 'buyer') {
                $where[] = "o.buyer_id = ?";
                $params[] = $userId;
            } elseif ($userRole === 'seller') {
                $where[] = "o.seller_id = ?";
                $params[] = $userId;
            }

            if ($status !== 'all') {
                $where[] = "o.status = ?";
                $params[] = $status;
            }

            if (!empty($where)) {
                $query .= " WHERE " . implode(" AND ", $where);
            }

            $query .= " ORDER BY o.created_at DESC LIMIT 50";

            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $orders = $stmt->fetchAll();

            foreach ($orders as &$order) {
                $stmt = $this->db->prepare("
                    SELECT oi.*, 
                           COALESCE(
                               (SELECT image FROM products WHERE id = oi.product_id),
                               oi.product_image
                           ) as image
                    FROM order_items oi
                    WHERE oi.order_id = ?
                ");
                $stmt->execute([$order['id']]);
                $order['items'] = $stmt->fetchAll();
            }

            return [
                'success' => true,
                'orders' => $orders,
                'count' => count($orders)
            ];

        } catch (PDOException $e) {
            error_log("Get Orders Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Action: GET_ORDER_DETAIL - Ambil detail satu order
     */
    public function getOrderDetail($orderId)
    {
        try {
            if (!isLoggedIn()) {
                return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
            }

            $userId = (int) $_SESSION['user_id'];
            $userRole = getUserRole();

            $stmt = $this->db->prepare("
                SELECT 
                    o.*,
                    u.name as seller_name,
                    u2.name as buyer_name,
                    u2.phone as buyer_phone
                FROM orders o
                JOIN users u ON o.seller_id = u.id
                JOIN users u2 ON o.buyer_id = u2.id
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Pesanan tidak ditemukan'];
            }

            if ($userRole === 'buyer' && $order['buyer_id'] != $userId) {
                return ['success' => false, 'message' => 'Anda tidak memiliki akses'];
            }
            if ($userRole === 'seller' && $order['seller_id'] != $userId) {
                return ['success' => false, 'message' => 'Anda tidak memiliki akses'];
            }

            $stmt = $this->db->prepare("
                SELECT oi.*, 
                       COALESCE(
                           (SELECT image FROM products WHERE id = oi.product_id),
                           oi.product_image
                       ) as image
                FROM order_items oi
                WHERE oi.order_id = ?
            ");
            $stmt->execute([$orderId]);
            $order['items'] = $stmt->fetchAll();

            $stmt = $this->db->prepare("
                SELECT * FROM payments WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
            $order['payment'] = $stmt->fetch();

            return [
                'success' => true,
                'order' => $order
            ];

        } catch (PDOException $e) {
            error_log("Get Order Detail Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Action: GET_TRACKING - Ambil tracking status order
     */
    public function getTracking($orderId)
    {
        try {
            if (!isLoggedIn()) {
                return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
            }

            $userId = (int) $_SESSION['user_id'];
            $userRole = getUserRole();

            $stmt = $this->db->prepare("
                SELECT 
                    o.id, o.order_code, o.status, o.created_at, 
                    o.shipping_address, o.total_amount, o.buyer_id,
                    u.name as seller_name,
                    u.phone as seller_phone
                FROM orders o
                JOIN users u ON o.seller_id = u.id
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Pesanan tidak ditemukan'];
            }

            if ($userRole === 'buyer' && $order['buyer_id'] != $userId) {
                return ['success' => false, 'message' => 'Anda tidak memiliki akses'];
            }

            $statusSteps = [
                'pending' => ['label' => 'Menunggu Konfirmasi', 'icon' => 'clock', 'order' => 0],
                'confirmed' => ['label' => 'Pesanan Dikonfirmasi', 'icon' => 'check-circle', 'order' => 1],
                'cooking' => ['label' => 'Sedang Dimasak', 'icon' => 'fire', 'order' => 2],
                'on_delivery' => ['label' => 'Sedang Diantar', 'icon' => 'truck', 'order' => 3],
                'delivered' => ['label' => 'Telah Sampai', 'icon' => 'check-circle-fill', 'order' => 4],
                'cancelled' => ['label' => 'Dibatalkan', 'icon' => 'x-circle', 'order' => -1]
            ];

            $currentStatus = $order['status'];
            $currentStep = $statusSteps[$currentStatus]['order'] ?? 0;
            $currentLabel = $statusSteps[$currentStatus]['label'] ?? $currentStatus;

            $steps = [];
            foreach ($statusSteps as $key => $step) {
                if ($key === 'cancelled') continue;
                
                $isActive = $step['order'] <= $currentStep;
                $isCurrent = $step['order'] === $currentStep;
                
                $steps[] = [
                    'status' => $key,
                    'label' => $step['label'],
                    'icon' => $step['icon'],
                    'active' => $isActive,
                    'current' => $isCurrent,
                    'order' => $step['order']
                ];
            }

            return [
                'success' => true,
                'order' => [
                    'id' => $order['id'],
                    'order_code' => $order['order_code'],
                    'status' => $order['status'],
                    'status_label' => $currentLabel,
                    'created_at' => $order['created_at'],
                    'shipping_address' => $order['shipping_address'],
                    'total_amount' => $order['total_amount'],
                    'seller_name' => $order['seller_name'],
                    'seller_phone' => $order['seller_phone']
                ],
                'steps' => $steps,
                'current_step' => $currentStep
            ];

        } catch (PDOException $e) {
            error_log("Get Tracking Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }
}

// ============================================
// HANDLE REQUEST
// ============================================

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $controller = new OrderController();
    
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'checkout':
            echo json_encode($controller->checkout($_POST));
            break;
            
        case 'update_status':
            echo json_encode($controller->updateStatus($_POST));
            break;
            
        case 'get_orders':
            echo json_encode($controller->getOrders($_GET));
            break;
            
        case 'get_order_detail':
            if (empty($_GET['order_id'])) {
                echo json_encode(['success' => false, 'message' => 'Order ID required']);
            } else {
                echo json_encode($controller->getOrderDetail((int)$_GET['order_id']));
            }
            break;
            
        case 'get_tracking':
            if (empty($_GET['order_id'])) {
                echo json_encode(['success' => false, 'message' => 'Order ID required']);
            } else {
                echo json_encode($controller->getTracking((int)$_GET['order_id']));
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
}
?>