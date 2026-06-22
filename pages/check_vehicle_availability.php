<?php
/**
 * ============================================================
 * Check Vehicle Availability (AJAX endpoint)
 * Returns JSON: { available: bool, message: string }
 * ============================================================
 */

// Disable error display in output (log instead)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Database Configuration (must match your booking.php)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');
define('APP_URL', getenv('APP_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: 'http://localhost/car-rental-kenya'));

// Database Connection
try {
require_once __DIR__ . '/../config/db.php';
    $conn = db_connect();
    if ($conn->connect_error) throw new Exception("Connection failed: " . $conn->connect_error);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    // Return error as JSON
    header('Content-Type: application/json');
    echo json_encode(['available' => false, 'message' => 'Database connection error']);
    exit();
}

// Helper function to sanitize input (simple)
function sanitize($data) {
    global $conn;
    return $conn->real_escape_string(trim(htmlspecialchars($data)));
}

// Get parameters from GET request
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$pickup_date = isset($_GET['pickup_date']) ? trim($_GET['pickup_date']) : '';
$pickup_time = isset($_GET['pickup_time']) ? trim($_GET['pickup_time']) : '';
$return_date = isset($_GET['return_date']) ? trim($_GET['return_date']) : '';
$return_time = isset($_GET['return_time']) ? trim($_GET['return_time']) : '';

// Validate input
if ($vehicle_id <= 0 || empty($pickup_date) || empty($pickup_time) || empty($return_date) || empty($return_time)) {
    header('Content-Type: application/json');
    echo json_encode(['available' => false, 'message' => 'Missing or invalid parameters']);
    exit();
}

// Combine date and time
$pickup_datetime = $pickup_date . ' ' . $pickup_time . ':00';
$return_datetime = $return_date . ' ' . $return_time . ':00';

// Validate date order
try {
    $pickup = new DateTime($pickup_datetime);
    $return = new DateTime($return_datetime);
    $now = new DateTime();
    if ($pickup < $now) {
        header('Content-Type: application/json');
        echo json_encode(['available' => false, 'message' => 'Pickup date cannot be in the past']);
        exit();
    }
    if ($return <= $pickup) {
        header('Content-Type: application/json');
        echo json_encode(['available' => false, 'message' => 'Return date must be after pickup date']);
        exit();
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['available' => false, 'message' => 'Invalid date format']);
    exit();
}

// Check availability using the same logic as in Booking::isVehicleAvailable()
$pickup_sql = date('Y-m-d H:i:s', strtotime($pickup_datetime));
$return_sql = date('Y-m-d H:i:s', strtotime($return_datetime));

$query = "SELECT COUNT(*) as count FROM bookings 
          WHERE vehicle_id = ? 
            AND booking_status IN ('pending', 'confirmed', 'active')
            AND pickup_date < ? 
            AND return_date > ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    header('Content-Type: application/json');
    echo json_encode(['available' => false, 'message' => 'Database error: prepare failed']);
    exit();
}
$stmt->bind_param("iss", $vehicle_id, $return_sql, $pickup_sql);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$count = $row['count'];
$stmt->close();
$conn->close();

$available = ($count == 0);
$message = $available ? 'Vehicle is available for the selected dates' : 'Vehicle is not available for the selected dates';

header('Content-Type: application/json');
echo json_encode(['available' => $available, 'message' => $message]);
?>
