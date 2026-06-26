<?php
/**
 * AuthController.php
 * Menangani register, login, logout
 * 
 * @package FoodDelivery
 */

// Load konfigurasi
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/database.php';

class AuthController
{
    private PDO $db;
    
    public function __construct()
    {
        $this->db = getDB();
    }
    
    /**
     * Proses registrasi
     */
    public function register($data)
    {
        try {
            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }
            
            $required = ['name', 'email', 'password', 'password_confirm', 'role'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => 'Semua field harus diisi'];
                }
            }
            
            $name = sanitize($data['name']);
            $email = sanitize($data['email']);
            $password = $data['password'];
            $passwordConfirm = $data['password_confirm'];
            $role = sanitize($data['role']);
            $phone = isset($data['phone']) ? sanitize($data['phone']) : '';
            $address = isset($data['address']) ? sanitize($data['address']) : '';
            
            $allowedRoles = ['buyer', 'seller', 'driver'];
            if (!in_array($role, $allowedRoles)) {
                return ['success' => false, 'message' => 'Role tidak valid'];
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Format email tidak valid'];
            }
            
            if (strlen($password) < 6) {
                return ['success' => false, 'message' => 'Password minimal 6 karakter'];
            }
            
            if ($password !== $passwordConfirm) {
                return ['success' => false, 'message' => 'Password tidak cocok'];
            }
            
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'Email sudah terdaftar'];
            }
            
            $hashedPassword = hashPassword($password);
            $isVerified = ($role === 'buyer') ? 1 : 0;
            $isActive = 1;
            
            $stmt = $this->db->prepare("
                INSERT INTO users (name, email, password, role, phone, address, is_verified, is_active, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $result = $stmt->execute([$name, $email, $hashedPassword, $role, $phone, $address, $isVerified, $isActive]);
            
            if (!$result) {
                return ['success' => false, 'message' => 'Gagal mendaftar, silakan coba lagi'];
            }
            
            $userId = $this->db->lastInsertId();
            
            if ($role !== 'buyer') {
                $this->sendVerificationNotification($userId, $email, $name, $role);
            }
            
            if ($role === 'buyer') {
                $_SESSION['user_id'] = $userId;
                $_SESSION['user'] = [
                    'id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                    'is_verified' => 1,
                    'is_active' => 1
                ];
                
                return [
                    'success' => true,
                    'message' => 'Registrasi berhasil! Selamat datang ' . $name,
                    'redirect' => BASE_URL . 'views/public/menu.php',
                    'auto_login' => true
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Registrasi berhasil! Akun Anda menunggu verifikasi admin.',
                'redirect' => BASE_URL . 'views/public/login.php',
                'auto_login' => false
            ];
            
        } catch (PDOException $e) {
            error_log("Register Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'];
        }
    }
    
    private function sendVerificationNotification($userId, $email, $name, $role)
    {
        try {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1");
            $stmt->execute();
            $admin = $stmt->fetch();
            
            if (!$admin) return;
            
            $message = "User baru mendaftar sebagai {$role}:\n";
            $message .= "Nama: {$name}\n";
            $message .= "Email: {$email}\n";
            $message .= "Status: Menunggu verifikasi";
            
            addNotification($admin['id'], 'Verifikasi User Baru', $message);
            addNotification($userId, 'Menunggu Verifikasi', 'Akun Anda menunggu verifikasi admin.');
            
        } catch (PDOException $e) {
            error_log("Notification Error: " . $e->getMessage());
        }
    }
    
    /**
     * Proses login
     */
    public function login($data)
    {
        try {
            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }
            
            if (empty($data['email']) || empty($data['password'])) {
                return ['success' => false, 'message' => 'Email dan password harus diisi'];
            }
            
            $email = sanitize($data['email']);
            $password = $data['password'];
            $remember = isset($data['remember']) ? true : false;
            
            $stmt = $this->db->prepare("
                SELECT id, name, email, password, role, is_verified, is_active 
                FROM users 
                WHERE email = ?
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Email atau password salah'];
            }
            
            if (!verifyPassword($password, $user['password'])) {
                return ['success' => false, 'message' => 'Email atau password salah'];
            }
            
            if ($user['is_active'] != 1) {
                return ['success' => false, 'message' => 'Akun Anda telah dinonaktifkan. Silakan hubungi admin.'];
            }
            
            if (($user['role'] === 'seller' || $user['role'] === 'driver') && $user['is_verified'] != 1) {
                return ['success' => false, 'message' => 'Akun Anda belum diverifikasi oleh admin.'];
            }
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user'] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'is_verified' => $user['is_verified'],
                'is_active' => $user['is_active']
            ];
            
            session_regenerate_id(true);
            
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                
                $stmt = $this->db->prepare("
                    UPDATE users SET remember_token = ?, remember_expiry = ? WHERE id = ?
                ");
                $stmt->execute([$token, $expiry, $user['id']]);
                
                setcookie('remember_token', $user['id'] . ':' . $token, [
                    'expires' => strtotime('+30 days'),
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
            }
            
            $redirect = $this->getRedirectUrl($user['role']);
            
            return [
                'success' => true,
                'message' => 'Login berhasil! Selamat datang ' . $user['name'],
                'redirect' => $redirect,
                'role' => $user['role']
            ];
            
        } catch (PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'];
        }
    }
    
    public function checkRememberMe()
    {
        try {
            if (!isset($_COOKIE['remember_token'])) {
                return false;
            }
            
            list($userId, $token) = explode(':', $_COOKIE['remember_token'], 2);
            
            $stmt = $this->db->prepare("
                SELECT id, name, email, role, is_verified, is_active 
                FROM users 
                WHERE id = ? AND remember_token = ? AND remember_expiry > NOW()
            ");
            $stmt->execute([$userId, $token]);
            $user = $stmt->fetch();
            
            if (!$user) {
                setcookie('remember_token', '', time() - 3600, '/');
                return false;
            }
            
            if ($user['is_active'] != 1) return false;
            if (($user['role'] === 'seller' || $user['role'] === 'driver') && $user['is_verified'] != 1) return false;
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user'] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'is_verified' => $user['is_verified'],
                'is_active' => $user['is_active']
            ];
            
            $newToken = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
            
            $stmt = $this->db->prepare("
                UPDATE users SET remember_token = ?, remember_expiry = ? WHERE id = ?
            ");
            $stmt->execute([$newToken, $expiry, $user['id']]);
            
            setcookie('remember_token', $user['id'] . ':' . $newToken, [
                'expires' => strtotime('+30 days'),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Remember Me Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Proses logout (JSON response untuk AJAX)
     */
    public function logout()
    {
        try {
            if (isset($_SESSION['user_id'])) {
                $stmt = $this->db->prepare("
                    UPDATE users SET remember_token = NULL, remember_expiry = NULL WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id']]);
            }
            
            setcookie('remember_token', '', time() - 3600, '/');
            $_SESSION = array();
            session_destroy();
            
            return [
                'success' => true,
                'message' => 'Logout berhasil',
                'redirect' => BASE_URL . 'views/public/login.php'
            ];
            
        } catch (Exception $e) {
            error_log("Logout Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Gagal logout'];
        }
    }
    
    /**
     * Proses logout dengan REDIRECT (bukan JSON)
     * DIPANGGIL DARI TOMBOL LOGOUT
     */
    public function logoutRedirect()
    {
        if (isset($_SESSION['user_id'])) {
            $stmt = $this->db->prepare("
                UPDATE users SET remember_token = NULL, remember_expiry = NULL WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
        }
        
        setcookie('remember_token', '', time() - 3600, '/');
        $_SESSION = array();
        session_destroy();
        
        header('Location: ' . BASE_URL . 'views/public/login.php');
        exit;
    }
    
    private function getRedirectUrl($role)
    {
        switch ($role) {
            case 'admin': return BASE_URL . 'views/admin/dashboard.php';
            case 'seller': return BASE_URL . 'views/seller/dashboard.php';
            case 'driver': return BASE_URL . 'views/driver/dashboard.php';
            default: return BASE_URL . 'views/public/menu.php';
        }
    }
}

// ============================================
// HANDLE REQUEST
// ============================================

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $controller = new AuthController();
    
    switch ($action) {
        case 'register':
            header('Content-Type: application/json');
            echo json_encode($controller->register($_POST));
            break;
        case 'login':
            header('Content-Type: application/json');
            echo json_encode($controller->login($_POST));
            break;
        case 'logout':
            header('Content-Type: application/json');
            echo json_encode($controller->logout());
            break;
        case 'logout_redirect':
            // ← INI YANG DIPAKAI UNTUK LOGOUT LANGSUNG
            $controller->logoutRedirect();
            break;
        case 'check_remember':
            header('Content-Type: application/json');
            echo json_encode(['success' => $controller->checkRememberMe()]);
            break;
        default:
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
}
?>