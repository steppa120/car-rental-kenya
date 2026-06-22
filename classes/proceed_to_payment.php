<?php
/**
 * ============================================================
 * Confirm Booking Page – Shows booking summary before payment
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
ob_start();

// Configuration – same as other files
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

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function format_currency($amount) {
    return 'Ksh ' . number_format($amount, 2);
}

// Check login
if (!is_logged_in()) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please login to view booking'];
    header("Location: " . APP_URL . "/pages/login.php");
    exit();
}

// Get booking ID
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
if ($booking_id <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'No booking specified'];
    header("Location: " . APP_URL . "/pages/my-bookings.php");
    exit();
}

// Fetch booking details with vehicle and user info
$query = "SELECT b.*, v.make, v.model, v.registration_number, v.daily_rate,
                 u.first_name, u.last_name, u.email, u.phone
          FROM bookings b
          LEFT JOIN vehicles v ON b.vehicle_id = v.id
          LEFT JOIN users u ON b.user_id = u.id
          WHERE b.id = ? AND b.user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Booking not found'];
    header("Location: " . APP_URL . "/pages/my-bookings.php");
    exit();
}

// If already paid, redirect to confirmation
if ($booking['payment_status'] === 'paid') {
    header("Location: " . APP_URL . "/pages/booking-confirmation.php?booking_id=" . $booking_id);
    exit();
}

$user_name = $_SESSION['user_name'] ?? $booking['first_name'] ?? 'User';
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Booking - <?php echo htmlspecialchars($booking['booking_reference']); ?> | FleetKE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1fdfdf 0%, #9345e0 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .confirm-wrapper {
            max-width: 800px;
            margin: 0 auto;
        }
        .confirm-header {
            background: white;
            border-radius: 15px;
            padding: 20px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo h1 {
            color: #1a73e8;
            font-size: 1.8rem;
            margin: 0;
        }
        .logo p {
            color: #5f6368;
            font-size: 0.9rem;
            margin: 0;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-name {
            color: #202124;
            font-weight: 500;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
        }
        .confirm-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .booking-ref {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 25px;
        }
        .booking-ref .label {
            color: #5f6368;
            font-size: 0.9rem;
            display: block;
            margin-bottom: 5px;
        }
        .booking-ref .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1a73e8;
            letter-spacing: 1px;
        }
        .vehicle-summary {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        .vehicle-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
        }
        .vehicle-details h2 {
            color: #202124;
            margin-bottom: 5px;
        }
        .vehicle-details p {
            color: #5f6368;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        .detail-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
        }
        .detail-item .label {
            color: #5f6368;
            font-size: 0.9rem;
            margin-bottom: 5px;
            display: block;
        }
        .detail-item .value {
            color: #202124;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .price-breakdown {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 25px 0;
        }
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .breakdown-item:last-child {
            border-bottom: none;
        }
        .breakdown-total {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1a73e8;
            padding-top: 10px;
            margin-top: 5px;
            border-top: 2px dashed #dadce0;
        }
        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        .btn {
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            flex: 1;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .btn-outline {
            background: white;
            border: 2px solid #e0e0e0;
            color: #5f6368;
        }
        .btn-outline:hover {
            border-color: #1a73e8;
            color: #1a73e8;
        }
        @media (max-width: 600px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
            .button-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="confirm-wrapper">
        <div class="confirm-header">
            <div class="logo">
                <h1>FleetKE</h1>
                <p>Car Rental Kenya</p>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                <div class="user-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
            </div>
        </div>

        <div class="confirm-card">
            <div class="booking-ref">
                <span class="label">Booking Reference</span>
                <span class="value"><?php echo htmlspecialchars($booking['booking_reference']); ?></span>
            </div>

            <div class="vehicle-summary">
                <div class="vehicle-icon">
                    <i class="fas fa-car"></i>
                </div>
                <div class="vehicle-details">
                    <h2><?php echo htmlspecialchars($booking['make'] . ' ' . $booking['model']); ?></h2>
                    <p><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($booking['registration_number']); ?></p>
                </div>
            </div>

            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Pickup Date & Time</span>
                    <span class="value"><?php echo date('d M Y, H:i', strtotime($booking['pickup_date'])); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Return Date & Time</span>
                    <span class="value"><?php echo date('d M Y, H:i', strtotime($booking['return_date'])); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Pickup Location</span>
                    <span class="value"><?php echo htmlspecialchars($booking['pickup_location']); ?>, <?php echo strtoupper($booking['pickup_county']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Return Location</span>
                    <span class="value"><?php echo htmlspecialchars($booking['return_location']); ?>, <?php echo strtoupper($booking['return_county']); ?></span>
                </div>
            </div>

            <div class="price-breakdown">
                <h3 style="margin-bottom: 15px;">Price Details</h3>
                <div class="breakdown-item">
                    <span>Daily Rate (<?php echo $booking['number_of_days']; ?> days)</span>
                    <span><?php echo format_currency($booking['base_amount']); ?></span>
                </div>
                <?php if ($booking['discount_amount'] > 0): ?>
                <div class="breakdown-item">
                    <span>Discount</span>
                    <span>-<?php echo format_currency($booking['discount_amount']); ?></span>
                </div>
                <?php endif; ?>
                <div class="breakdown-item breakdown-total">
                    <span>Total Amount</span>
                    <span><?php echo format_currency($booking['total_amount']); ?></span>
                </div>
            </div>

            <div class="button-group">
                <a href="<?php echo APP_URL; ?>/pages/book-now.php?vehicle_id=<?php echo $booking['vehicle_id']; ?>" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Edit
                </a>
                <!-- Updated Proceed to Payment button (direct to M-Pesa) -->
                <a href="<?php echo APP_URL; ?>mpesa_payment.php?booking_id=<?php echo $booking_id; ?>" class="btn btn-primary">
                    <i class="fas fa-mobile-alt"></i> Proceed to Payment (M‑Pesa)
                </a>
            </div>
        </div>
    </div>
</body>
</html>

