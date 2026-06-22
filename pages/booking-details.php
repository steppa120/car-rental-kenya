<?php
/**
 * ============================================================
 * Booking Details – Shows detailed information for a specific booking
 * with Moon Glassmorphism design
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
function format_currency($amount) {
    return 'Ksh ' . number_format($amount, 2);
}
function format_date($date, $format = 'd M Y, h:i A') {
    return date($format, strtotime($date));
}
function get_status_badge($status) {
    $classes = [
        'pending'   => 'status-pending',
        'paid'      => 'status-paid',
        'completed' => 'status-paid',
        'cancelled' => 'status-cancelled',
        'confirmed' => 'status-paid'
    ];
    $class = $classes[strtolower($status)] ?? 'status-pending';
    return "<span class='status-badge $class'>" . ucfirst($status) . "</span>";
}

// Check login
if (!is_logged_in()) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please login to view booking details'];
    header("Location: " . APP_URL . "/pages/login.php");
    exit();
}

// Get booking ID from URL
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
if ($booking_id <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid booking'];
    header("Location: my-bookings.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Dynamically detect timestamp column in payments table
$timestamp_col = 'created_at';
$columns_result = $conn->query("SHOW COLUMNS FROM payments");
if ($columns_result && $columns_result->num_rows > 0) {
    $existing_columns = [];
    while ($col = $columns_result->fetch_assoc()) {
        $existing_columns[] = $col['Field'];
    }
    $possible_timestamp = ['created_at', 'payment_date', 'created', 'date_added'];
    foreach ($possible_timestamp as $candidate) {
        if (in_array($candidate, $existing_columns)) {
            $timestamp_col = $candidate;
            break;
        }
    }
} else {
    // Create payments table if missing
    $conn->query("
        CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            user_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method ENUM('mpesa','card','cash') NOT NULL,
            transaction_reference VARCHAR(100) UNIQUE NOT NULL,
            payment_status ENUM('pending','completed','failed','cancelled') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    $timestamp_col = 'created_at';
}

// Fetch booking details with vehicle, payment, and booking status
$query = "
    SELECT 
        b.*,
        v.make, v.model, v.year, v.vehicle_type, v.transmission, 
        v.fuel_type, v.seating_capacity, v.daily_rate, v.image_url,
        v.registration_number,
        u.first_name, u.last_name, u.email, u.phone,
        p.payment_status, p.transaction_reference, p.amount as paid_amount, 
        p.payment_method, p.{$timestamp_col} as payment_date, p.id as payment_id
    FROM bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.id
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.id = ? AND b.user_id = ?
    ORDER BY p.{$timestamp_col} DESC
    LIMIT 1
";
$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Booking not found'];
    header("Location: my-bookings.php");
    exit();
}

// Calculate duration days if not present
if (!isset($booking['duration_days']) || $booking['duration_days'] == 0) {
    $pickup = new DateTime($booking['pickup_date']);
    $return = new DateTime($booking['return_date']);
    $interval = $pickup->diff($return);
    $booking['duration_days'] = $interval->days;
}

// Determine final status: cancelled from bookings.status overrides payment_status
$final_status = $booking['status'] ?? 'pending';
if ($final_status === 'cancelled') {
    $display_status = 'cancelled';
} else {
    $display_status = $booking['payment_status'] ?? 'pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details - <?php echo htmlspecialchars($booking['booking_reference']); ?> | FleetKE</title>
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
            background: radial-gradient(ellipse at 30% 40%, #f710d8 0%, #13e213 100%);
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* ----- MOON GLASSMORPHISM ELEMENT ----- */
        .moon {
            position: fixed;
            top: 8%;
            right: 5%;
            width: 130px;
            height: 130px;
            background: radial-gradient(circle at 30% 30%, rgba(255, 245, 210, 0.9), rgba(255, 220, 150, 0.6));
            border-radius: 50%;
            box-shadow: 0 0 40px rgba(255, 220, 150, 0.5), 0 0 80px rgba(255, 200, 100, 0.3);
            backdrop-filter: blur(2px);
            z-index: 1;
            pointer-events: none;
            animation: floatMoon 8s ease-in-out infinite;
        }

        /* Crescent cutout (optional) – creates a crescent effect */
        .moon::before {
            content: '';
            position: absolute;
            top: 15px;
            right: 20px;
            width: 35px;
            height: 35px;
            background: radial-gradient(ellipse at 30% 30%, #0a0f2a, #03050b);
            border-radius: 50%;
            opacity: 0.7;
        }

        @keyframes floatMoon {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }

        /* Container – glassmorphism card */
        .container {
            position: relative;
            z-index: 10;
            max-width: 1100px;
            margin: 0 auto;
            background: rgba(20, 30, 55, 0.45);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-radius: 36px;
            padding: 2rem;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        h1 {
            color: #ffd966;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.8rem;
            text-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        h1 i {
            color: #ffd966;
        }

        .booking-header {
            background: rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(4px);
            padding: 1rem 1.5rem;
            border-radius: 28px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .booking-ref {
            font-size: 1.2rem;
            font-weight: 600;
            color: white;
        }

        .booking-ref span {
            color: #ffd966;
        }

        .status-section {
            display: flex;
            align-items: center;
            gap: 15px;
            color: rgba(255,255,255,0.9);
        }

        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 40px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pending { background: rgba(255, 193, 7, 0.25); color: #ffecb3; border: 1px solid #ffc107; }
        .status-paid { background: rgba(40, 167, 69, 0.25); color: #d4edda; border: 1px solid #28a745; }
        .status-cancelled { background: rgba(220, 53, 69, 0.25); color: #f8d7da; border: 1px solid #dc3545; }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .detail-card {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(6px);
            padding: 1.2rem;
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: transform 0.2s;
        }

        .detail-card:hover {
            transform: translateY(-3px);
            background: rgba(0, 0, 0, 0.4);
        }

        .detail-card h3 {
            color: #ffd966;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.2rem;
            border-bottom: 2px solid rgba(255, 217, 102, 0.5);
            padding-bottom: 8px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .detail-row {
            display: flex;
            margin-bottom: 12px;
            color: rgba(255,255,255,0.9);
        }
        .detail-label {
            font-weight: 600;
            width: 125px;
            color: #ffd966;
        }
        .detail-value {
            flex: 1;
            color: white;
        }

        .vehicle-image {
            max-width: 100%;
            max-height: 160px;
            border-radius: 16px;
            margin-top: 12px;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .payment-summary {
            background: rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(8px);
            padding: 1.2rem 1.5rem;
            border-radius: 24px;
            margin-bottom: 25px;
            border-left: 5px solid #ffd966;
        }

        .payment-summary h3 {
            color: #ffd966;
            margin-top: 0;
            margin-bottom: 15px;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            font-size: 1rem;
            padding: 8px 0;
            color: rgba(255,255,255,0.9);
        }

        .payment-row.total {
            font-weight: 700;
            font-size: 1.3rem;
            border-top: 2px solid rgba(255, 217, 102, 0.5);
            margin-top: 8px;
            padding-top: 12px;
            color: #ffd966;
        }

        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 40px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ffb347, #ff8c00);
            color: #0a0f2a;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }

        .btn-success {
            background: #34a853;
            color: white;
        }
        .btn-success:hover {
            background: #2d8659;
            transform: translateY(-2px);
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
        }
        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #ffd966;
        }

        .btn-danger {
            background: rgba(220, 53, 69, 0.8);
            color: white;
        }
        .btn-danger:hover {
            background: #dc3545;
            transform: translateY(-2px);
        }

        .flash {
            padding: 12px 18px;
            border-radius: 28px;
            margin-bottom: 20px;
            backdrop-filter: blur(4px);
        }
        .flash.success {
            background: rgba(52, 168, 83, 0.3);
            color: #d4edda;
            border: 1px solid #28a745;
        }
        .flash.error {
            background: rgba(220, 53, 69, 0.3);
            color: #f8d7da;
            border: 1px solid #dc3545;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1.2rem;
            }
            .detail-label {
                width: 100px;
            }
            .moon {
                width: 80px;
                height: 80px;
                top: 3%;
                right: 3%;
            }
        }
    </style>
</head>
<body>

<!-- Moon glassmorphism element -->
<div class="moon"></div>

<div class="container">
    <h1><i class="fas fa-calendar-check"></i> Booking Details</h1>

    <?php if (isset($_SESSION['flash'])): 
        $flash = $_SESSION['flash']; 
        unset($_SESSION['flash']); ?>
        <div class="flash <?php echo $flash['type']; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="booking-header">
        <div class="booking-ref">
            <i class="fas fa-hashtag"></i> <span><?php echo htmlspecialchars($booking['booking_reference']); ?></span>
        </div>
        <div class="status-section">
            <div>Status:</div>
            <?php echo get_status_badge($display_status); ?>
        </div>
    </div>

    <div class="detail-grid">
        <!-- Booking Information -->
        <div class="detail-card">
            <h3><i class="fas fa-info-circle"></i> Booking Information</h3>
            <div class="detail-row">
                <span class="detail-label">Booking ID:</span>
                <span class="detail-value"><?php echo $booking['id']; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Created:</span>
                <span class="detail-value"><?php echo format_date($booking['created_at']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Pick-up:</span>
                <span class="detail-value"><?php echo format_date($booking['pickup_date']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Return:</span>
                <span class="detail-value"><?php echo format_date($booking['return_date']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Duration:</span>
                <span class="detail-value"><?php echo $booking['duration_days']; ?> day(s)</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Pickup Location:</span>
                <span class="detail-value"><?php echo htmlspecialchars($booking['pickup_location'] ?? 'Nairobi'); ?></span>
            </div>
        </div>

        <!-- Vehicle Details -->
        <div class="detail-card">
            <h3><i class="fas fa-car"></i> Vehicle Details</h3>
            <div class="detail-row">
                <span class="detail-label">Make/Model:</span>
                <span class="detail-value"><?php echo htmlspecialchars($booking['make'] . ' ' . $booking['model']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Reg No:</span>
                <span class="detail-value"><?php echo htmlspecialchars($booking['registration_number']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Year:</span>
                <span class="detail-value"><?php echo $booking['year']; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Type:</span>
                <span class="detail-value"><?php echo ucfirst($booking['vehicle_type']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Transmission:</span>
                <span class="detail-value"><?php echo ucfirst($booking['transmission']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Fuel:</span>
                <span class="detail-value"><?php echo ucfirst($booking['fuel_type']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Seats:</span>
                <span class="detail-value"><?php echo $booking['seating_capacity']; ?></span>
            </div>
            <?php if (!empty($booking['image_url'])): ?>
                <div>
                    <img src="<?php echo APP_URL . '/' . htmlspecialchars($booking['image_url']); ?>" alt="Vehicle Image" class="vehicle-image">
                </div>
            <?php endif; ?>
        </div>

        <!-- Customer Details -->
        <div class="detail-card">
            <h3><i class="fas fa-user"></i> Customer Details</h3>
            <div class="detail-row">
                <span class="detail-label">Name:</span>
                <span class="detail-value"><?php echo htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Email:</span>
                <span class="detail-value"><?php echo htmlspecialchars($booking['email']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Phone:</span>
                <span class="detail-value"><?php echo htmlspecialchars($booking['phone']); ?></span>
            </div>
        </div>

        <!-- Payment Details -->
        <div class="detail-card">
            <h3><i class="fas fa-credit-card"></i> Payment Details</h3>
            <?php if (!empty($booking['payment_id'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Payment ID:</span>
                    <span class="detail-value"><?php echo $booking['payment_id']; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Transaction Ref:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($booking['transaction_reference']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Method:</span>
                    <span class="detail-value"><?php echo strtoupper($booking['payment_method']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payment Date:</span>
                    <span class="detail-value"><?php echo format_date($booking['payment_date']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount Paid:</span>
                    <span class="detail-value"><?php echo format_currency($booking['paid_amount']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value"><?php echo get_status_badge($booking['payment_status']); ?></span>
                </div>
            <?php else: ?>
                <p style="color: rgba(255,255,255,0.7);">No payment has been made for this booking yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Summary -->
    <div class="payment-summary">
        <h3>Payment Summary</h3>
        <div class="payment-row">
            <span>Daily Rate (<?php echo $booking['duration_days']; ?> days)</span>
            <span><?php echo format_currency($booking['daily_rate'] * $booking['duration_days']); ?></span>
        </div>
        <?php if (isset($booking['extras_cost']) && $booking['extras_cost'] > 0): ?>
        <div class="payment-row">
            <span>Extras (GPS, child seat, etc.)</span>
            <span><?php echo format_currency($booking['extras_cost']); ?></span>
        </div>
        <?php endif; ?>
        <div class="payment-row total">
            <span>Total Amount</span>
            <span><?php echo format_currency($booking['total_amount']); ?></span>
        </div>
        <?php if (!empty($booking['paid_amount'])): ?>
        <div class="payment-row" style="color: #a5d6a5;">
            <span>Paid</span>
            <span><?php echo format_currency($booking['paid_amount']); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Action Buttons -->
    <div class="actions">
        <a href="my-bookings.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to My Bookings</a>
        
        <?php if ($display_status === 'pending'): ?>
            <a href="mpesa-payment.php?booking_id=<?php echo $booking['id']; ?>" class="btn btn-primary"><i class="fas fa-money-bill"></i> Pay Now</a>
            <a href="cancel-booking.php?booking_id=<?php echo $booking['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel this booking?');"><i class="fas fa-times"></i> Cancel Booking</a>
        <?php elseif ($display_status === 'paid' || $display_status === 'completed'): ?>
            <?php if (!empty($booking['transaction_reference'])): ?>
                <a href="payment-receipt.php?booking_id=<?php echo $booking['id']; ?>&ref=<?php echo urlencode($booking['transaction_reference']); ?>" class="btn btn-success"><i class="fas fa-receipt"></i> View Receipt</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
<?php $conn->close(); ?>

