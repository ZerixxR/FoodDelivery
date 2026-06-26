<?php
/**
 * CartController.php
 * Menangani semua operasi keranjang belanja
 * 
 * @package FoodDelivery
 * @version 1.0
 */

// Load konfigurasi
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

/**
 * Class CartController
 */
class CartController
{
    private PDO $db;
    
    public function __construct()
    {
        $this->db = getDB();
    }
    
    /**
     * Get jumlah item di keranjang user
     * 
     * @param PDO $db
     * @param int $userId
     * @return int
     */
    private function getCartCount($db, $userId)
    {
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(quantity), 0) as total 
                FROM cart 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            return (int) $result['total'];
        } catch (PDOException $e) {
            error_log("getCartCount Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get total harga di keranjang user
     * 
     * @param PDO $db
     * @param int $userId
     * @return float
     */
    private function getCartTotal($db, $userId)
    {
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(c.quantity * p.price), 0) as total 
                FROM cart c
                JOIN products p ON c.product_id = p.id
                WHERE c.user_id = ? AND p.is_active = 1
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            return (float) $result['total'];
        } catch (PDOException $e) {
            error_log("getCartTotal Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get detail item di keranjang
     * 
     * @param PDO $db
     * @param int $userId
     * @return array
     */
    private function getCartItems($db, $userId)
    {
        try {
            $stmt = $db->prepare("
                SELECT 
                    c.id as cart_id,
                    c.product_id,
                    c.quantity,
                    p.name,
                    p.price,
                    p.image,
                    p.stock,
                    p.seller_id,
                    u.name as seller_name
                FROM cart c
                JOIN products p ON c.product_id = p.id
                JOIN users u ON p.seller_id = u.id
                WHERE c.user_id = ? AND p.is_active = 1
                ORDER BY c.created_at DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getCartItems Error: " . $e->getMessage());
            return [];
        }
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
     * Action: ADD item ke keranjang
     * 
     * @param array $data POST data
     * @return array JSON response
     */
    public function add($data)
    {
        try {
            // Validasi akses buyer
            $accessError = $this->validateBuyerAccess();
            if ($accessError) return $accessError;
            
            // Validasi CSRF
            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }
            
            // Validasi input
            if (empty($data['product_id'])) {
                return ['success' => false, 'message' => 'Produk tidak valid'];
            }
            
            $productId = (int) $data['product_id'];
            $quantity = isset($data['quantity']) ? (int) $data['quantity'] : 1;
            $userId = (int) $_SESSION['user_id'];
            
            // Validasi quantity
            if ($quantity < 1) {
                return ['success' => false, 'message' => 'Jumlah minimal 1'];
            }
            
            // Cek produk
            $stmt = $this->db->prepare("
                SELECT id, name, price, stock, is_active 
                FROM products 
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
            if (!$product) {
                return ['success' => false, 'message' => 'Produk tidak tersedia'];
            }
            
            // Cek stok
            if ($product['stock'] < $quantity) {
                return [
                    'success' => false, 
                    'message' => "Stok tidak mencukupi. Tersedia: {$product['stock']}"
                ];
            }
            
            // Cek apakah produk sudah ada di cart
            $stmt = $this->db->prepare("
                SELECT id, quantity 
                FROM cart 
                WHERE user_id = ? AND product_id = ?
            ");
            $stmt->execute([$userId, $productId]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update quantity
                $newQuantity = $existing['quantity'] + $quantity;
                
                // Cek stok untuk update
                if ($product['stock'] < $newQuantity) {
                    return [
                        'success' => false, 
                        'message' => "Stok tidak mencukupi. Tersedia: {$product['stock']}"
                    ];
                }
                
                $stmt = $this->db->prepare("
                    UPDATE cart 
                    SET quantity = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$newQuantity, $existing['id']]);
                
                $message = "Jumlah produk diperbarui";
            } else {
                // Insert baru
                $stmt = $this->db->prepare("
                    INSERT INTO cart (user_id, product_id, quantity, created_at, updated_at) 
                    VALUES (?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$userId, $productId, $quantity]);
                
                $message = "Produk ditambahkan ke keranjang";
            }
            
            // Get cart count
            $cartCount = $this->getCartCount($this->db, $userId);
            
            return [
                'success' => true,
                'message' => $message,
                'cart_count' => $cartCount
            ];
            
        } catch (PDOException $e) {
            error_log("Cart Add Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }
    
    /**
     * Action: UPDATE quantity di keranjang
     * 
     * @param array $data POST data
     * @return array JSON response
     */
    public function update($data)
    {
        try {
            // Validasi akses buyer
            $accessError = $this->validateBuyerAccess();
            if ($accessError) return $accessError;
            
            // Validasi CSRF
            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }
            
            // Validasi input
            if (empty($data['cart_id'])) {
                return ['success' => false, 'message' => 'Data keranjang tidak valid'];
            }
            
            $cartId = (int) $data['cart_id'];
            $quantity = isset($data['quantity']) ? (int) $data['quantity'] : 1;
            $userId = (int) $_SESSION['user_id'];
            
            // Validasi quantity
            if ($quantity < 1) {
                return ['success' => false, 'message' => 'Jumlah minimal 1'];
            }
            
            // Cek item di cart milik user
            $stmt = $this->db->prepare("
                SELECT c.id, c.product_id, c.quantity, p.stock, p.name
                FROM cart c
                JOIN products p ON c.product_id = p.id
                WHERE c.id = ? AND c.user_id = ? AND p.is_active = 1
            ");
            $stmt->execute([$cartId, $userId]);
            $cartItem = $stmt->fetch();
            
            if (!$cartItem) {
                return ['success' => false, 'message' => 'Item tidak ditemukan di keranjang'];
            }
            
            // Cek stok
            if ($cartItem['stock'] < $quantity) {
                return [
                    'success' => false, 
                    'message' => "Stok tidak mencukupi. Tersedia: {$cartItem['stock']}"
                ];
            }
            
            // Update quantity
            $stmt = $this->db->prepare("
                UPDATE cart 
                SET quantity = ?, updated_at = NOW() 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$quantity, $cartId, $userId]);
            
            // Hitung subtotal item
            $itemSubtotal = $this->getItemSubtotal($cartId, $userId);
            
            // Get cart count dan total
            $cartCount = $this->getCartCount($this->db, $userId);
            $cartTotal = $this->getCartTotal($this->db, $userId);
            
            return [
                'success' => true,
                'message' => 'Jumlah berhasil diupdate',
                'cart_count' => $cartCount,
                'cart_total' => $cartTotal,
                'item_subtotal' => $itemSubtotal,
                'quantity' => $quantity
            ];
            
        } catch (PDOException $e) {
            error_log("Cart Update Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }
    
    /**
     * Get subtotal untuk satu item di keranjang
     * 
     * @param int $cartId
     * @param int $userId
     * @return float
     */
    private function getItemSubtotal($cartId, $userId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT c.quantity * p.price as subtotal
                FROM cart c
                JOIN products p ON c.product_id = p.id
                WHERE c.id = ? AND c.user_id = ?
            ");
            $stmt->execute([$cartId, $userId]);
            $result = $stmt->fetch();
            return $result ? (float) $result['subtotal'] : 0;
        } catch (PDOException $e) {
            error_log("getItemSubtotal Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Action: REMOVE item dari keranjang
     * 
     * @param array $data POST data
     * @return array JSON response
     */
    public function remove($data)
    {
        try {
            // Validasi akses buyer
            $accessError = $this->validateBuyerAccess();
            if ($accessError) return $accessError;
            
            // Validasi CSRF
            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }
            
            // Validasi input
            if (empty($data['cart_id'])) {
                return ['success' => false, 'message' => 'Data keranjang tidak valid'];
            }
            
            $cartId = (int) $data['cart_id'];
            $userId = (int) $_SESSION['user_id'];
            
            // Cek item milik user
            $stmt = $this->db->prepare("
                SELECT id FROM cart 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$cartId, $userId]);
            
            if (!$stmt->fetch()) {
                return ['success' => false, 'message' => 'Item tidak ditemukan'];
            }
            
            // Delete item
            $stmt = $this->db->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
            $stmt->execute([$cartId, $userId]);
            
            // Get cart count dan total
            $cartCount = $this->getCartCount($this->db, $userId);
            $cartTotal = $this->getCartTotal($this->db, $userId);
            
            return [
                'success' => true,
                'message' => 'Item berhasil dihapus',
                'cart_count' => $cartCount,
                'cart_total' => $cartTotal
            ];
            
        } catch (PDOException $e) {
            error_log("Cart Remove Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }
    
    /**
     * Action: GET cart count (untuk badge navbar)
     * 
     * @return array JSON response
     */
    public function getCount()
    {
        try {
            // Cek login
            if (!isLoggedIn()) {
                return ['success' => true, 'cart_count' => 0];
            }
            
            $userId = (int) $_SESSION['user_id'];
            
            // Buyer only
            if (getUserRole() !== 'buyer') {
                return ['success' => true, 'cart_count' => 0];
            }
            
            $cartCount = $this->getCartCount($this->db, $userId);
            
            return [
                'success' => true,
                'cart_count' => $cartCount
            ];
            
        } catch (PDOException $e) {
            error_log("Cart GetCount Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }
    
    /**
     * Action: GET semua item di keranjang (untuk halaman cart)
     * 
     * @return array JSON response
     */
    public function getItems()
    {
        try {
            // Validasi akses buyer
            $accessError = $this->validateBuyerAccess();
            if ($accessError) return $accessError;
            
            $userId = (int) $_SESSION['user_id'];
            
            $items = $this->getCartItems($this->db, $userId);
            $cartTotal = $this->getCartTotal($this->db, $userId);
            $cartCount = $this->getCartCount($this->db, $userId);
            
            // Format data
            $formattedItems = array_map(function($item) {
                return [
                    'cart_id' => $item['cart_id'],
                    'product_id' => $item['product_id'],
                    'name' => $item['name'],
                    'price' => (float) $item['price'],
                    'quantity' => (int) $item['quantity'],
                    'stock' => (int) $item['stock'],
                    'image' => $item['image'] ? UPLOAD_URL . $item['image'] : null,
                    'seller_id' => $item['seller_id'],
                    'seller_name' => $item['seller_name'],
                    'subtotal' => (float) $item['price'] * (int) $item['quantity']
                ];
            }, $items);
            
            return [
                'success' => true,
                'items' => $formattedItems,
                'cart_total' => $cartTotal,
                'cart_count' => $cartCount,
                'item_count' => count($formattedItems)
            ];
            
        } catch (PDOException $e) {
            error_log("Cart GetItems Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }
    
    /**
     * Action: CLEAR seluruh keranjang (setelah checkout)
     * 
     * @param int $userId
     * @return bool
     */
    public function clearCart($userId)
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM cart WHERE user_id = ?");
            return $stmt->execute([$userId]);
        } catch (PDOException $e) {
            error_log("Cart Clear Error: " . $e->getMessage());
            return false;
        }
    }
}

// ============================================
// HANDLE REQUEST
// ============================================

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $controller = new CartController();
    
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'add':
            echo json_encode($controller->add($_POST));
            break;
            
        case 'update':
            echo json_encode($controller->update($_POST));
            break;
            
        case 'remove':
            echo json_encode($controller->remove($_POST));
            break;
            
        case 'get_count':
            echo json_encode($controller->getCount());
            break;
            
        case 'get_items':
            echo json_encode($controller->getItems());
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
}
?>