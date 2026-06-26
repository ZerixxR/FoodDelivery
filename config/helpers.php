<?php
// Keamanan
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}
function hashPassword($pass) { return password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]); }
function verifyPassword($pass, $hash) { return password_verify($pass, $hash); }
function generateCsrfToken() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verifyCsrfToken($token) { return hash_equals($_SESSION['csrf'] ?? '', $token); }

// Session
function isLoggedIn() { return !empty($_SESSION['user_id']); }
function getUser() { return $_SESSION['user'] ?? null; }
function getUserRole() { return $_SESSION['user']['role'] ?? null; }
function requireRole($role) {
    if (!isLoggedIn() || getUserRole() !== $role) {
        redirect(BASE_URL . 'views/public/login.php');
    }
}

// Utility
function redirect($url) { header("Location: $url"); exit; }
function formatRupiah($n) { return 'Rp ' . number_format($n,0,',','.'); }
function generateOrderCode() { return 'FD-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3))); }

// Flash message
function setFlash($type, $msg) { $_SESSION['flash'] = ['type'=>$type,'message'=>$msg]; }
function getFlash() { $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

// Notifikasi
function addNotification($userId, $title, $message) {
    $db = getDB();
    $db->prepare("INSERT INTO notifications (user_id,title,message) VALUES (?,?,?)")
       ->execute([$userId, $title, $message]);
}

// Upload file
function uploadFile($file, $folder='foods') {
    $allowed = ['image/jpeg','image/png','image/webp'];
    if ($file['error'] !== 0 || !in_array($file['type'],$allowed) || $file['size']>2*1024*1024) return false;
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = uniqid().'_'.time().'.'.$ext;
    $dir = UPLOAD_PATH.$folder.'/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    return move_uploaded_file($file['tmp_name'], $dir.$name) ? $folder.'/'.$name : false;
}

// ============================================
// TAMBAHKAN DI BAWAH FUNGSI YANG SUDAH ADA
// ============================================

/**
 * Alias untuk generateCsrfToken (biar kompatibel)
 */
function generateCSRF() {
    return generateCsrfToken();
}

/**
 * Alias untuk verifyCsrfToken (biar kompatibel)
 */
function validateCSRF($token) {
    return verifyCsrfToken($token);
}