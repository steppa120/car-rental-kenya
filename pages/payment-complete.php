<?php
/**
 * ============================================================
 * Payment Completion (Simulation) – Marks payment as completed
 * ============================================================
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);

// Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');
define('APP_URL', getenv('APP_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: 'http://localhost/car-rental-kenya'));

// Database Connection
try {
require_once __DIR__ . '/../config/db.php';
    $conn = db_connect();
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Helper functions
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Check login
if (!is_logged_in()) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please login first'];
    header("Location: " . APP_URL . "/pages/login.php");
    exit();
}

// Get booking ID and transaction reference
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$trans_ref = isset($_GET['ref']) ? trim($_GET['ref']) : '';

if ($booking_id <= 0 || empty($trans_ref)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid request'];
    header("Location: " . APP_URL . "/pages/my-bookings.php");
    exit();
}

// Verify the booking belongs to the logged-in user and fetch vehicle_id
$stmt = $conn->prepare("SELECT id, vehicle_id FROM bookings WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();

if (!$booking) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Booking not found'];
    header("Location: " . APP_URL . "/pages/my-bookings.php");
    exit();
}

// Update the payment status to 'completed'
$stmt = $conn->prepare("UPDATE payments SET payment_status = 'completed' WHERE booking_id = ? AND transaction_reference = ?");
$stmt->bind_param("is", $booking_id, $trans_ref);
$payment_updated = $stmt->execute();

if ($payment_updated) {
    // Update booking payment_status
    $conn->query("UPDATE bookings SET payment_status = 'paid' WHERE id = $booking_id");

    // Update vehicle status to 'rented'
    $vehicle_id = $booking['vehicle_id'];
    if ($vehicle_id) {
        $vehicle_update = $conn->query("UPDATE vehicles SET status = 'rented' WHERE id = $vehicle_id");
        if (!$vehicle_update) {
            error_log("Failed to update vehicle status for vehicle ID $vehicle_id: " . $conn->error);
        }
    }

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Payment completed successfully!'];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Failed to update payment status'];
}

// Redirect to the receipt page
header("Location: payment-receipt.php?booking_id=$booking_id&ref=" . urlencode($trans_ref));
exit();
?>

