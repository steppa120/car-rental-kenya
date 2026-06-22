<?php
/**
 * ============================================================
 * Check Booking Status API - Returns real-time updates
 * ============================================================
 */
session_start();
header('Content-Type: application/json');

// Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');

// Database Connection
require_once __DIR__ . '/../config/db.php';
$conn = db_connect();
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}
$conn->set_charset("utf8mb4");

// Get user_id from request or session
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0);

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user']);
    exit();
}

$last_check = isset($_GET['last_check']) ? (int)$_GET['last_check'] : 0;

// Fetch updated bookings for this user
$query = "
    SELECT 
        b.id as booking_id,
        b.booking_reference,
        COALESCE(p.payment_status, 'pending') as payment_status,
        p.transaction_reference,
        COALESCE(v.status, 'available') as vehicle_status,
        UNIX_TIMESTAMP(b.updated_at) as updated_at
    FROM bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.id
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.user_id = ? 
    AND (UNIX_TIMESTAMP(b.updated_at) > ? OR UNIX_TIMESTAMP(p.updated_at) > ? OR UNIX_TIMESTAMP(v.updated_at) > ?)
    ORDER BY b.updated_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("iiii", $user_id, $last_check, $last_check, $last_check);
$stmt->execute();
$result = $stmt->get_result();
$updates = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'success' => true,
    'updates' => $updates,
    'timestamp' => time()
]);

$conn->close();
?>
