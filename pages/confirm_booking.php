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

// ============================================================
// FETCH DYNAMIC SYSTEM SETTINGS (ADMIN CAN CHANGE)
// ============================================================
$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
// Default values if not set in DB
$default_settings = [
    'site_name' => 'FleetKE',
    'contact_email' => 'info@fleetke.com',
    'contact_phone' => '+254 700 000 000',
    'contact_address' => 'Westlands, Nairobi, Kenya',
    'currency' => 'Ksh',
    'tax_rate' => 16
];
foreach ($default_settings as $key => $default) {
    if (!isset($settings[$key])) $settings[$key] = $default;
}
// Override constants or use settings array
define('APP_NAME', $settings['site_name']);
define('CURRENCY_SYMBOL', $settings['currency']);

// Helper function to format currency with dynamic symbol
function format_currency($amount) {
    global $settings;
    return $settings['currency'] . ' ' . number_format($amount, 2);
}

// Helper function to display vehicle image (or icon if none)
function get_vehicle_image_html($vehicle, $app_url) {
    if (empty($vehicle['image_url'])) {
        return '<i class="fas fa-car" style="font-size:2.5rem; color:#ffd966;"></i>';
    }
    $image_path = trim($vehicle['image_url'], '/');
    $image_path = str_replace('\\', '/', $image_path);
    $src = $app_url . '/' . $image_path;
    return '<img src="' . htmlspecialchars($src) . '" 
                 alt="' . htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) . '"
                 style="width:100%; height:100%; object-fit:cover;">';
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
function is_logged_in() {
    return isset($_SESSION['user_id']);
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

// Fetch booking details with vehicle and user info (include vehicle image)
$query = "SELECT b.*, v.make, v.model, v.registration_number, v.daily_rate, v.image_url,
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Confirm Booking - <?php echo htmlspecialchars($booking['booking_reference']); ?> | <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            background: radial-gradient(ellipse at center, #29315f 0%, #e71632 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            overflow-x: hidden;
        }

        /* ----- STATIC STARS CONTAINER (non-blinking) ----- */
        .stars-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .star {
            position: absolute;
            background-color: #ffffff;
            border-radius: 50%;
            box-shadow: 0 0 6px 2px rgba(255, 255, 255, 0.4);
            opacity: 0.7;
            pointer-events: none;
        }

        /* Optional: small stars have less opacity to create depth */
        .star.small {
            opacity: 0.4;
            box-shadow: 0 0 2px 1px rgba(255, 255, 255, 0.3);
        }
        .star.medium {
            opacity: 0.7;
        }
        .star.large {
            opacity: 0.9;
            box-shadow: 0 0 8px 3px rgba(255, 255, 255, 0.6);
        }

        /* ----- GLASSMORPHISM CONTAINER ----- */
        .confirm-wrapper {
            position: relative;
            z-index: 10;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
        }

        .confirm-header {
            background: rgba(15, 25, 50, 0.5);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 28px;
            padding: 1rem 1.8rem;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }

        .logo h1 {
            color: #fff;
            font-size: 1.8rem;
            margin: 0;
            text-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }

        .logo p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.8rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-name {
            color: #ffd966;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #ffb347, #ff8c00);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0a0f2a;
            font-weight: bold;
            font-size: 1.2rem;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.3);
        }

        /* Main glass card */
        .confirm-card {
            background: rgba(15, 25, 50, 0.45);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 32px;
            padding: 2rem;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .booking-ref {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 20px;
            padding: 1rem;
            text-align: center;
            margin-bottom: 25px;
            backdrop-filter: blur(4px);
        }

        .booking-ref .label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            display: block;
            margin-bottom: 5px;
        }

        .booking-ref .value {
            font-size: 1.6rem;
            font-weight: 700;
            color: #ffd966;
            letter-spacing: 1px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .vehicle-summary {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .vehicle-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .vehicle-icon i {
            font-size: 2.5rem;
            color: #ffd966;
        }

        .vehicle-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .vehicle-details h2 {
            color: white;
            margin-bottom: 5px;
        }

        .vehicle-details p {
            color: rgba(255, 255, 255, 0.8);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .detail-item {
            background: rgba(0, 0, 0, 0.25);
            padding: 15px;
            border-radius: 20px;
            backdrop-filter: blur(4px);
        }

        .detail-item .label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            margin-bottom: 5px;
            display: block;
        }

        .detail-item .value {
            color: #fff;
            font-weight: 600;
            font-size: 1rem;
        }

        .price-breakdown {
            background: rgba(0, 0, 0, 0.3);
            padding: 20px;
            border-radius: 24px;
            margin: 25px 0;
            backdrop-filter: blur(4px);
        }

        .price-breakdown h3 {
            color: #ffd966;
            margin-bottom: 15px;
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            color: rgba(255, 255, 255, 0.9);
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        }

        .breakdown-item:last-child {
            border-bottom: none;
        }

        .breakdown-total {
            font-size: 1.2rem;
            font-weight: 700;
            color: #ffd966;
            padding-top: 10px;
            margin-top: 5px;
            border-top: 2px dashed rgba(255, 255, 255, 0.3);
        }

        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .btn {
            padding: 14px 30px;
            border: none;
            border-radius: 40px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            flex: 1;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ffb347, #ff8c00);
            color: #0a0f2a;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            backdrop-filter: blur(4px);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: #ffd966;
            color: #ffd966;
        }

        @media (max-width: 600px) {
            body {
                padding: 1rem;
            }
            .confirm-card {
                padding: 1.5rem;
            }
            .detail-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .button-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<!-- Static stars overlay (non-blinking) -->
<div class="stars-container" id="starsContainer"></div>

<div class="confirm-wrapper">
    <div class="confirm-header">
        <div class="logo">
            <h1><?php echo htmlspecialchars($settings['site_name']); ?></h1>
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
                <?php echo get_vehicle_image_html($booking, APP_URL); ?>
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
            <h3>Price Details</h3>
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
            <a href="<?php echo APP_URL; ?>/pages/booking.php?vehicle_id=<?php echo $booking['vehicle_id']; ?>" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Edit
            </a>
            <a href="<?php echo APP_URL; ?>/pages/mpesa_payment.php?booking_id=<?php echo $booking_id; ?>" class="btn btn-primary">
                <i class="fas fa-mobile-alt"></i> Proceed to Payment (M‑Pesa)
            </a>
        </div>
    </div>
</div>

<script>
    (function() {
        // Generate static stars with varying sizes and opacities (no blinking)
        const starsContainer = document.getElementById('starsContainer');
        if (starsContainer) {
            const STAR_COUNT = 250;
            for (let i = 0; i < STAR_COUNT; i++) {
                const star = document.createElement('div');
                star.classList.add('star');
                
                // Random size between 1px and 3.5px
                const size = Math.random() * 2.5 + 0.8;
                star.style.width = size + 'px';
                star.style.height = size + 'px';
                
                // Random position
                star.style.left = Math.random() * 100 + '%';
                star.style.top = Math.random() * 100 + '%';
                
                // Add size class for different opacity levels (depth effect)
                if (size < 1.5) {
                    star.classList.add('small');
                } else if (size < 2.5) {
                    star.classList.add('medium');
                } else {
                    star.classList.add('large');
                }
                
                starsContainer.appendChild(star);
            }
        }
    })();
</script>
</body>
</html>

