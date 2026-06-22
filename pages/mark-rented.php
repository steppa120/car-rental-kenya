<?php
/**
 * ============================================================
 * Mark as Rented - Handles marking vehicle as rented after payment
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
require_once __DIR__ . '/../config/db.php';
$conn = db_connect();
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function redirect($url) {
    header("Location: " . $url);
    exit();
}

function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

// Check if admin is logged in
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    set_flash('error', 'Unauthorized access');
    redirect('admin_login.php');
}

// Get parameters
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

if ($booking_id <= 0 || $vehicle_id <= 0) {
    set_flash('error', 'Invalid request');
    redirect('admin_login.php');
}

// Start transaction
$conn->begin_transaction();

try {
    // Update booking status to active
    $update_booking = $conn->prepare("UPDATE bookings SET status = 'active', updated_at = NOW() WHERE id = ?");
    $update_booking->bind_param("i", $booking_id);
    $update_booking->execute();
    
    // Update vehicle status to rented
    $update_vehicle = $conn->prepare("UPDATE vehicles SET status = 'rented', updated_at = NOW() WHERE id = ?");
    $update_vehicle->bind_param("i", $vehicle_id);
    $update_vehicle->execute();
    
    // Update payment status if needed
    $update_payment = $conn->prepare("UPDATE payments SET payment_status = 'completed', updated_at = NOW() WHERE booking_id = ?");
    $update_payment->bind_param("i", $booking_id);
    $update_payment->execute();
    
    // Commit transaction
    $conn->commit();
    
    set_flash('success', 'Booking confirmed and vehicle marked as rented!');
    
} catch (Exception $e) {
    $conn->rollback();
    set_flash('error', 'Failed to process: ' . $e->getMessage());
}

redirect('admin_login.php');
$conn->close();
?>

