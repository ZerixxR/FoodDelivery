<?php
/**
 * NotificationController.php
 * Menangani notifikasi user
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

class NotificationController
{
    private PDO $db;
    
    public function __construct()
    {
        $this->db = getDB();
    }

    public function getLatest($data)
    {
        try {
            if (!isLoggedIn()) {
                return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
            }

            $userId = (int) $_SESSION['user_id'];
            $limit = isset($data['limit']) ? (int) $data['limit'] : 10;

            $stmt = $this->db->prepare("
                SELECT id, title, message, is_read, created_at 
                FROM notifications 
                WHERE user_id = ?
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            $notifications = $stmt->fetchAll();

            // Hitung unread
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count FROM notifications 
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$userId]);
            $unreadCount = (int) $stmt->fetch()['count'];

            return [
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unreadCount
            ];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    public function readAll($data)
    {
        try {
            if (!isLoggedIn()) {
                return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
            }

            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            $userId = (int) $_SESSION['user_id'];

            $stmt = $this->db->prepare("
                UPDATE notifications SET is_read = 1 
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$userId]);

            return [
                'success' => true,
                'message' => 'Semua notifikasi sudah dibaca'
            ];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    public function readOne($data)
    {
        try {
            if (!isLoggedIn()) {
                return ['success' => false, 'message' => 'Silakan login terlebih dahulu'];
            }

            if (!isset($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
                return ['success' => false, 'message' => 'Invalid CSRF token'];
            }

            if (empty($data['notification_id'])) {
                return ['success' => false, 'message' => 'Notification ID required'];
            }

            $notificationId = (int) $data['notification_id'];
            $userId = (int) $_SESSION['user_id'];

            $stmt = $this->db->prepare("
                UPDATE notifications SET is_read = 1 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$notificationId, $userId]);

            return [
                'success' => true,
                'message' => 'Notifikasi ditandai sudah dibaca'
            ];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    public function getUnreadCount()
    {
        try {
            if (!isLoggedIn()) {
                return ['success' => true, 'unread_count' => 0];
            }

            $userId = (int) $_SESSION['user_id'];

            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count FROM notifications 
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$userId]);
            $unreadCount = (int) $stmt->fetch()['count'];

            return [
                'success' => true,
                'unread_count' => $unreadCount
            ];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }
}

// Handle request
if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $controller = new NotificationController();
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'get_latest':
            echo json_encode($controller->getLatest($_GET));
            break;
        case 'read_all':
            echo json_encode($controller->readAll($_POST));
            break;
        case 'read_one':
            echo json_encode($controller->readOne($_POST));
            break;
        case 'get_unread_count':
            echo json_encode($controller->getUnreadCount());
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
}
?>