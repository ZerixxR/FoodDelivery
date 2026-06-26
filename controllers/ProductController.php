<?php
/**
 * ProductController.php
 * Menangani CRUD produk untuk seller
 * 
 * @package FoodDelivery
 */

// Load konfigurasi
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

/**
 * Class ProductController
 */
class ProductController
{
    private PDO $db;
    
    public function __construct()
    {
        $this->db = getDB();
    }

    /**
     * Validasi akses seller
     * 
     * @return array|null Response error atau null jika valid
     */
    private function validateSellerAccess()
    {
        if (!isLoggedIn()) {
            return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
        }
        
        if (getUserRole() !== 'seller') {
            return ['success' => false, 'message' => 'Akses hanya untuk penjual'];
        }
        
        return null;
    }

    /**
     * Generate slug dari nama produk
     * 
     * @param string $name
     * @return string
     */
    private function generateSlug($name)
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    /**
     * Cek apakah produk milik seller
     * 
     * @param int $productId
     * @param int $sellerId
     * @return bool
     */
    private function isProductOwner($productId, $sellerId)
    {
        $stmt = $this->db->prepare("
            SELECT id FROM products WHERE id = ? AND seller_id = ?
        ");
        $stmt->execute([$productId, $sellerId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Action: CREATE - Tambah produk baru
     */
    public function create($data, $files)
    {
        try {
            $accessError = $this->validateSellerAccess();
            if ($accessError) return $accessError;

            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            $sellerId = (int) $_SESSION['user_id'];

            $required = ['name', 'description', 'price', 'stock', 'category_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => 'Semua field harus diisi'];
                }
            }

            $name = sanitize($data['name']);
            $description = sanitize($data['description']);
            $price = (float) str_replace(['Rp', '.', ' '], '', $data['price']);
            $stock = (int) $data['stock'];
            $categoryId = (int) $data['category_id'];
            $weight = isset($data['weight']) ? (int) $data['weight'] : 200;
            $isActive = isset($data['is_active']) ? 1 : 0;

            if ($price <= 0) {
                return ['success' => false, 'message' => 'Harga harus lebih dari 0'];
            }

            if ($stock < 0) {
                return ['success' => false, 'message' => 'Stok tidak valid'];
            }

            $slug = $this->generateSlug($name);
            $stmt = $this->db->prepare("SELECT id FROM products WHERE slug = ?");
            $stmt->execute([$slug]);
            if ($stmt->fetch()) {
                $slug = $slug . '-' . uniqid();
            }

            $imagePath = null;
            if (isset($files['image']) && $files['image']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = uploadFile($files['image'], 'foods');
                if ($uploadResult === false) {
                    return ['success' => false, 'message' => 'Gagal upload gambar. Format: JPG/PNG/WEBP, max 2MB'];
                }
                $imagePath = $uploadResult;
            }

            // Ambil max sort_order untuk seller ini
            $stmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 as next_order FROM products WHERE seller_id = ?");
            $stmt->execute([$sellerId]);
            $nextOrder = (int) $stmt->fetch()['next_order'];

            $stmt = $this->db->prepare("
                INSERT INTO products (
                    seller_id, category_id, name, slug, description, 
                    price, stock, weight, image, is_active, sort_order, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $result = $stmt->execute([
                $sellerId,
                $categoryId,
                $name,
                $slug,
                $description,
                $price,
                $stock,
                $weight,
                $imagePath,
                $isActive,
                $nextOrder
            ]);

            if (!$result) {
                return ['success' => false, 'message' => 'Gagal menambahkan produk'];
            }

            setFlash('success', 'Produk "' . $name . '" berhasil ditambahkan!');

            return [
                'success' => true,
                'message' => 'Produk berhasil ditambahkan',
                'redirect' => BASE_URL . 'views/seller/menu.php'
            ];

        } catch (PDOException $e) {
            error_log("Product Create Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'];
        }
    }

    /**
     * Action: UPDATE - Update produk
     */
    public function update($data, $files)
    {
        try {
            $accessError = $this->validateSellerAccess();
            if ($accessError) return $accessError;

            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            $sellerId = (int) $_SESSION['user_id'];

            if (empty($data['product_id'])) {
                return ['success' => false, 'message' => 'Produk tidak valid'];
            }

            $productId = (int) $data['product_id'];

            if (!$this->isProductOwner($productId, $sellerId)) {
                return ['success' => false, 'message' => 'Anda tidak memiliki akses ke produk ini'];
            }

            $required = ['name', 'description', 'price', 'stock', 'category_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => 'Semua field harus diisi'];
                }
            }

            $name = sanitize($data['name']);
            $description = sanitize($data['description']);
            $price = (float) str_replace(['Rp', '.', ' '], '', $data['price']);
            $stock = (int) $data['stock'];
            $categoryId = (int) $data['category_id'];
            $weight = isset($data['weight']) ? (int) $data['weight'] : 200;
            $isActive = isset($data['is_active']) ? 1 : 0;

            if ($price <= 0) {
                return ['success' => false, 'message' => 'Harga harus lebih dari 0'];
            }

            if ($stock < 0) {
                return ['success' => false, 'message' => 'Stok tidak valid'];
            }

            $stmt = $this->db->prepare("SELECT image FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $oldProduct = $stmt->fetch();
            $oldImage = $oldProduct['image'] ?? null;

            $imagePath = $oldImage;
            if (isset($files['image']) && $files['image']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = uploadFile($files['image'], 'foods');
                if ($uploadResult === false) {
                    return ['success' => false, 'message' => 'Gagal upload gambar. Format: JPG/PNG/WEBP, max 2MB'];
                }
                $imagePath = $uploadResult;
                
                if ($oldImage) {
                    $oldFile = UPLOAD_PATH . $oldImage;
                    if (file_exists($oldFile)) {
                        unlink($oldFile);
                    }
                }
            }

            $slug = $this->generateSlug($name);
            
            $stmt = $this->db->prepare("
                UPDATE products 
                SET 
                    category_id = ?,
                    name = ?,
                    slug = ?,
                    description = ?,
                    price = ?,
                    stock = ?,
                    weight = ?,
                    image = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE id = ? AND seller_id = ?
            ");

            $result = $stmt->execute([
                $categoryId,
                $name,
                $slug,
                $description,
                $price,
                $stock,
                $weight,
                $imagePath,
                $isActive,
                $productId,
                $sellerId
            ]);

            if (!$result) {
                return ['success' => false, 'message' => 'Gagal mengupdate produk'];
            }

            setFlash('success', 'Produk "' . $name . '" berhasil diupdate!');

            return [
                'success' => true,
                'message' => 'Produk berhasil diupdate',
                'redirect' => BASE_URL . 'views/seller/menu.php'
            ];

        } catch (PDOException $e) {
            error_log("Product Update Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'];
        }
    }

    /**
     * Action: DELETE - Soft delete produk
     */
    public function delete($data)
    {
        try {
            $accessError = $this->validateSellerAccess();
            if ($accessError) return $accessError;

            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            $sellerId = (int) $_SESSION['user_id'];

            if (empty($data['product_id'])) {
                return ['success' => false, 'message' => 'Produk tidak valid'];
            }

            $productId = (int) $data['product_id'];

            $stmt = $this->db->prepare("SELECT id, name FROM products WHERE id = ? AND seller_id = ?");
            $stmt->execute([$productId, $sellerId]);
            $product = $stmt->fetch();

            if (!$product) {
                return ['success' => false, 'message' => 'Produk tidak ditemukan atau bukan milik Anda'];
            }

            $stmt = $this->db->prepare("
                UPDATE products SET is_active = 0, updated_at = NOW() 
                WHERE id = ? AND seller_id = ?
            ");
            $stmt->execute([$productId, $sellerId]);

            setFlash('success', 'Produk "' . $product['name'] . '" berhasil dihapus!');

            return [
                'success' => true,
                'message' => 'Produk berhasil dihapus',
                'redirect' => BASE_URL . 'views/seller/menu.php'
            ];

        } catch (PDOException $e) {
            error_log("Product Delete Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'];
        }
    }

    /**
     * Action: TOGGLE - Aktifkan/Nonaktifkan produk
     */
    public function toggle($data)
    {
        try {
            $accessError = $this->validateSellerAccess();
            if ($accessError) return $accessError;

            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            $sellerId = (int) $_SESSION['user_id'];

            if (empty($data['product_id'])) {
                return ['success' => false, 'message' => 'Produk tidak valid'];
            }

            $productId = (int) $data['product_id'];
            $isActive = isset($data['is_active']) ? (int) $data['is_active'] : 0;

            $stmt = $this->db->prepare("SELECT id, name FROM products WHERE id = ? AND seller_id = ?");
            $stmt->execute([$productId, $sellerId]);
            $product = $stmt->fetch();

            if (!$product) {
                return ['success' => false, 'message' => 'Produk tidak ditemukan atau bukan milik Anda'];
            }

            $stmt = $this->db->prepare("
                UPDATE products SET is_active = ?, updated_at = NOW() 
                WHERE id = ? AND seller_id = ?
            ");
            $stmt->execute([$isActive, $productId, $sellerId]);

            $statusLabel = $isActive == 1 ? 'diaktifkan' : 'dinonaktifkan';

            setFlash('success', "Produk '{$product['name']}' berhasil {$statusLabel}!");

            return [
                'success' => true,
                'message' => "Produk berhasil {$statusLabel}",
                'redirect' => BASE_URL . 'views/seller/menu.php'
            ];

        } catch (PDOException $e) {
            error_log("Product Toggle Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'];
        }
    }

    /**
     * Action: REORDER - Update urutan menu (Drag & Drop) - KHUSUS UNTUK SELLER
     */
    public function reorder($data)
    {
        try {
            $accessError = $this->validateSellerAccess();
            if ($accessError) return $accessError;

            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            $sellerId = (int) $_SESSION['user_id'];

            if (empty($data['order'])) {
                return ['success' => false, 'message' => 'Data urutan kosong'];
            }

            $order = json_decode($data['order'], true);
            
            if (!is_array($order) || empty($order)) {
                return ['success' => false, 'message' => 'Format data urutan tidak valid'];
            }

            $this->db->beginTransaction();

            try {
                foreach ($order as $item) {
                    $productId = (int) $item['id'];
                    $sortOrder = (int) $item['sort_order'];
                    
                    $stmt = $this->db->prepare("
                        SELECT id FROM products 
                        WHERE id = ? AND seller_id = ?
                    ");
                    $stmt->execute([$productId, $sellerId]);
                    
                    if (!$stmt->fetch()) {
                        throw new Exception("Produk ID {$productId} bukan milik Anda");
                    }
                    
                    $stmt = $this->db->prepare("
                        UPDATE products 
                        SET sort_order = ?, updated_at = NOW() 
                        WHERE id = ? AND seller_id = ?
                    ");
                    $stmt->execute([$sortOrder, $productId, $sellerId]);
                }
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => 'Urutan menu berhasil diupdate!'
                ];
                
            } catch (Exception $e) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $e->getMessage()];
            }

        } catch (PDOException $e) {
            error_log("Reorder Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Action: GET_PRODUCTS - Ambil daftar produk seller (untuk Kelola Menu)
     * Diurutkan berdasarkan sort_order (drag & drop)
     */
    public function getProducts()
    {
        try {
            if (!isLoggedIn() || getUserRole() !== 'seller') {
                return ['success' => false, 'message' => 'Akses ditolak'];
            }

            $sellerId = (int) $_SESSION['user_id'];

            $stmt = $this->db->prepare("
                SELECT p.*, c.name as category_name 
                FROM products p
                JOIN categories c ON p.category_id = c.id
                WHERE p.seller_id = ?
                ORDER BY p.sort_order ASC, p.created_at DESC
            ");
            $stmt->execute([$sellerId]);
            $products = $stmt->fetchAll();

            return [
                'success' => true,
                'products' => $products,
                'count' => count($products)
            ];

        } catch (PDOException $e) {
            error_log("Get Products Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Action: GET_PUBLIC_PRODUCTS - Ambil daftar produk untuk halaman publik (Lihat Semua Menu)
     * Diurutkan berdasarkan created_at DESC (terbaru di atas)
     */
    public function getPublicProducts()
    {
        try {
            $search = isset($_GET['q']) ? sanitize($_GET['q']) : '';
            $categoryId = isset($_GET['category']) ? (int) $_GET['category'] : 0;
            $sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'newest';

            $where = "WHERE p.is_active = 1 AND p.stock > 0";
            $params = [];

            if (!empty($search)) {
                $where .= " AND (p.name LIKE ? OR p.description LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            if ($categoryId > 0) {
                $where .= " AND p.category_id = ?";
                $params[] = $categoryId;
            }

            $orderBy = match($sort) {
                'price_asc'  => 'p.price ASC',
                'price_desc' => 'p.price DESC',
                'popular'    => 'p.sold DESC',
                'newest'     => 'p.created_at DESC',
                default      => 'p.created_at DESC'
            };

            $stmt = $this->db->prepare("
                SELECT p.*, u.name AS resto_name, c.name AS category_name,
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
            $stmt->execute($params);
            $products = $stmt->fetchAll();

            return [
                'success' => true,
                'products' => $products,
                'count' => count($products)
            ];

        } catch (PDOException $e) {
            error_log("Get Public Products Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Action: GET_CATEGORIES - Ambil daftar kategori
     */
    public function getCategories()
    {
        try {
            $stmt = $this->db->query("
                SELECT id, name, icon FROM categories ORDER BY name
            ");
            $categories = $stmt->fetchAll();

            return [
                'success' => true,
                'categories' => $categories
            ];

        } catch (PDOException $e) {
            error_log("Get Categories Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }
}

// ============================================
// HANDLE REQUEST
// ============================================

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $controller = new ProductController();
    
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'create':
            echo json_encode($controller->create($_POST, $_FILES));
            break;
            
        case 'update':
            echo json_encode($controller->update($_POST, $_FILES));
            break;
            
        case 'delete':
            echo json_encode($controller->delete($_POST));
            break;
            
        case 'toggle':
            echo json_encode($controller->toggle($_POST));
            break;
            
        case 'reorder':
            echo json_encode($controller->reorder($_POST));
            break;
            
        case 'get_products':
            echo json_encode($controller->getProducts());
            break;
            
        case 'get_public_products':
            echo json_encode($controller->getPublicProducts());
            break;
            
        case 'get_categories':
            echo json_encode($controller->getCategories());
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
}
?>