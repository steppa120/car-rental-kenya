<?php
/**
 * ============================================================
 * Payment Receipt – Shows confirmation of paid booking
 * with Mountainous Glassmorphism design (Print‑optimized)
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

// Helper functions using dynamic settings
function format_currency($amount) {
    global $settings;
    return $settings['currency'] . ' ' . number_format($amount, 2);
}
function format_date($date) {
    return date('d M Y, h:i A', strtotime($date));
}
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Helper function to display vehicle image or icon (minimized)
function get_vehicle_image($image_url, $make, $model) {
    if (!empty($image_url)) {
        $image_path = trim($image_url, '/');
        $image_path = str_replace('\\', '/', $image_path);
        $src = APP_URL . '/' . $image_path;
        return '<img src="' . htmlspecialchars($src) . '" 
                     alt="' . htmlspecialchars($make . ' ' . $model) . '"
                     style="width:60px; height:60px; object-fit:cover; border-radius:12px;">';
    } else {
        return '<i class="fas fa-car" style="font-size:2.5rem; color:#ffd966;"></i>';
    }
}

// Check login
if (!is_logged_in()) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please login to view receipt'];
    header("Location: " . APP_URL . "/pages/login.php");
    exit();
}

// Get booking ID and transaction reference
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$trans_ref = isset($_GET['ref']) ? trim($_GET['ref']) : '';

if ($booking_id <= 0 || empty($trans_ref)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid receipt request'];
    header("Location: " . APP_URL . "/pages/my-bookings.php");
    exit();
}

// ------------------------------------------------------------
// Dynamically detect the timestamp column in payments table
// ------------------------------------------------------------
$timestamp_col = 'created_at'; // default
$columns_result = $conn->query("SHOW COLUMNS FROM payments");
if ($columns_result) {
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
    $create_table = "
        CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            user_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method ENUM('mpesa','card','cash') NOT NULL,
            transaction_reference VARCHAR(100) UNIQUE NOT NULL,
            payment_status ENUM('pending','completed','failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
    $conn->query($create_table);
    $timestamp_col = 'created_at';
}

// Build the query – added v.image_url
$query = "
    SELECT 
        b.*,
        v.make, v.model, v.registration_number, v.image_url,
        p.id as payment_id, 
        p.amount as paid_amount, 
        p.payment_method, 
        p.transaction_reference, 
        p.payment_status, 
        p.{$timestamp_col} as payment_date,
        u.first_name, u.last_name, u.email, u.phone
    FROM bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.id
    LEFT JOIN payments p ON b.id = p.booking_id AND p.transaction_reference = ?
    LEFT JOIN users u ON b.user_id = u.id
    WHERE b.id = ? AND b.user_id = ?
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Prepare failed: " . $conn->error . " | Query: " . $query);
}
$stmt->bind_param("sii", $trans_ref, $booking_id, $_SESSION['user_id']);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Receipt not found'];
    header("Location: " . APP_URL . "/pages/my-bookings.php");
    exit();
}

if ($data['payment_status'] !== 'completed') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'This payment is not yet completed'];
    header("Location: " . APP_URL . "/pages/mpesa-payment.php?booking_id=$booking_id");
    exit();
}

// Calculate duration days if not present
if (!isset($data['duration_days']) || $data['duration_days'] == 0) {
    $pickup = new DateTime($data['pickup_date']);
    $return = new DateTime($data['return_date']);
    $interval = $pickup->diff($return);
    $data['duration_days'] = $interval->days;
}
$daily_rate = isset($data['daily_rate']) ? (float)$data['daily_rate'] : 0;

if (isset($_GET['print'])) {
    echo "<script>window.print();</script>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Payment Receipt - <?php echo htmlspecialchars($data['booking_reference']); ?> | <?php echo htmlspecialchars($settings['site_name']); ?></title>
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
            background: linear-gradient(145deg, #1a472a 0%, #499ca1 50%, #4745b3 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 30px 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* ----- MOUNTAINOUS GLASSMORPHISM BACKGROUND (screen only) ----- */
        .mountain-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        .mountain {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(3px);
            clip-path: polygon(0% 100%, 10% 60%, 20% 75%, 35% 40%, 50% 65%, 65% 35%, 80% 55%, 90% 45%, 100% 70%, 100% 100%);
            height: 45%;
            pointer-events: none;
        }

        .mountain-2 {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(2px);
            clip-path: polygon(0% 100%, 0% 75%, 15% 55%, 30% 70%, 45% 45%, 60% 65%, 80% 40%, 95% 60%, 100% 50%, 100% 100%);
            height: 60%;
            pointer-events: none;
        }

        .mountain-3 {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(1px);
            clip-path: polygon(0% 100%, 0% 85%, 20% 65%, 40% 80%, 60% 55%, 80% 75%, 100% 60%, 100% 100%);
            height: 75%;
            pointer-events: none;
        }

        .mist {
            position: absolute;
            top: 20%;
            left: -10%;
            width: 120%;
            height: 30px;
            background: radial-gradient(ellipse at center, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 70%);
            filter: blur(12px);
            animation: drift 18s infinite linear;
            pointer-events: none;
        }
        .mist-2 {
            top: 45%;
            animation-duration: 25s;
            animation-delay: -5s;
        }
        .mist-3 {
            top: 70%;
            animation-duration: 22s;
            animation-delay: -10s;
        }
        @keyframes drift {
            from { transform: translateX(0); }
            to { transform: translateX(20%); }
        }

        /* ----- MAIN GLASS RECEIPT CARD (screen) ----- */
        .receipt {
            position: relative;
            z-index: 10;
            max-width: 900px;
            width: 100%;
            background: rgba(25, 45, 35, 0.45);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-radius: 48px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.12);
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: transform 0.2s;
        }

        .receipt-header {
            text-align: center;
            border-bottom: 2px solid rgba(255, 217, 102, 0.5);
            padding-bottom: 20px;
            margin-bottom: 25px;
        }

        .receipt-header h1 {
            color: #ffd966;
            margin: 10px 0 5px;
            font-size: 2.5rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .receipt-header p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
        }

        .company-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(4px);
            padding: 1rem 1.5rem;
            border-radius: 32px;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .company-details .logo i {
            font-size: 2rem;
            color: #ffd966;
        }

        .company-info {
            text-align: right;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.85rem;
        }

        .receipt-body {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-group {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(4px);
            padding: 1.2rem;
            border-radius: 28px;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .info-group h3 {
            color: #ffd966;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 8px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .vehicle-detail-row {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .vehicle-image-thumb {
            flex-shrink: 0;
        }

        .vehicle-text-details {
            flex: 1;
        }

        .info-row {
            display: flex;
            margin-bottom: 10px;
            color: rgba(255, 255, 255, 0.9);
        }

        .info-label {
            font-weight: 600;
            width: 110px;
            color: #ffd966;
        }

        .info-value {
            flex: 1;
        }

        .payment-summary {
            background: rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(8px);
            padding: 1.2rem 1.5rem;
            border-radius: 32px;
            margin-bottom: 30px;
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
            color: white;
        }

        .payment-row.total {
            font-weight: 700;
            font-size: 1.3rem;
            border-top: 2px solid rgba(255, 217, 102, 0.5);
            margin-top: 8px;
            padding-top: 12px;
            color: #ffd966;
        }

        .receipt-footer {
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding-top: 20px;
            margin-top: 10px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
        }

        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 40px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ffb347, #ff8c00);
            color: #0a0f2a;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(4px);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.25);
            color: #ffd966;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #ffd966;
            color: #ffd966;
        }
        .btn-outline:hover {
            background: rgba(255, 217, 102, 0.2);
            transform: translateY(-2px);
        }

        /* ========== PRINT STYLES – ensure black text on white, no glass effects ========== */
        @media print {
            /* Hide all decorative/window elements */
            .mountain-bg, .mist, .actions, .btn, .btn-primary, .btn-outline, .btn-secondary {
                display: none !important;
            }
            
            /* Force white background and black text on all receipt elements */
            body {
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            .receipt {
                background: white !important;
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
                box-shadow: none !important;
                border: 1px solid #ccc !important;
                padding: 20px !important;
                border-radius: 0 !important;
                margin: 0 auto !important;
            }
            
            /* Override all text colors to dark */
            .receipt-header h1,
            .receipt-header p,
            .company-info div,
            .info-group h3,
            .info-row,
            .info-label,
            .info-value,
            .payment-row,
            .payment-row.total,
            .receipt-footer p,
            .receipt-footer {
                color: #000 !important;
                text-shadow: none !important;
                border-color: #ccc !important;
            }
            
            /* Remove glass/semi‑transparent backgrounds, make them solid white/light gray */
            .company-details,
            .info-group,
            .payment-summary {
                background: #f9f9f9 !important;
                backdrop-filter: none !important;
                border: 1px solid #ddd !important;
                box-shadow: none !important;
            }
            
            .info-label {
                color: #333 !important;
                font-weight: bold;
            }
            
            .payment-summary {
                border-left: 3px solid #333 !important;
            }
            
            .payment-row.total {
                border-top: 2px solid #333 !important;
                color: #000 !important;
            }
            
            /* Ensure images are visible and not clipped */
            .vehicle-image-thumb img {
                filter: none !important;
                border: 1px solid #ddd !important;
            }
            
            /* Keep the layout intact */
            .receipt-body {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 15px;
            }
            
            /* Hide any remaining icons that might be decorative */
            .fa-mountain, .fa-car, .fa-user, .fa-calendar, .fa-credit-card {
                color: #000 !important;
            }
        }

        @media (max-width: 650px) {
            .receipt {
                padding: 1.2rem;
            }
            .info-label {
                width: 90px;
            }
        }
    </style>
</head>
<body>

<!-- Mountainous Glassmorphism Background (hidden when printing) -->
<div class="mountain-bg">
    <div class="mountain"></div>
    <div class="mountain-2"></div>
    <div class="mountain-3"></div>
    <div class="mist mist-1"></div>
    <div class="mist mist-2"></div>
    <div class="mist mist-3"></div>
</div>

<div class="receipt">
    <div class="receipt-header">
        <h1><?php echo htmlspecialchars($settings['site_name']); ?></h1>
        <p>Payment Receipt</p>
    </div>

    <div class="company-details">
        <div class="logo">
            <i class="fas fa-mountain"></i>
        </div>
        <div class="company-info">
            <div><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($settings['contact_address']); ?></div>
            <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($settings['contact_phone']); ?></div>
            <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($settings['contact_email']); ?></div>
        </div>
    </div>

    <div class="receipt-body">
        <div class="info-group">
            <h3><i class="fas fa-user"></i> Customer Details</h3>
            <div class="info-row">
                <span class="info-label">Name:</span>
                <span class="info-value"><?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Email:</span>
                <span class="info-value"><?php echo htmlspecialchars($data['email']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Phone:</span>
                <span class="info-value"><?php echo htmlspecialchars($data['phone']); ?></span>
            </div>
        </div>

        <div class="info-group">
            <h3><i class="fas fa-car"></i> Vehicle Details</h3>
            <div class="vehicle-detail-row">
                <div class="vehicle-image-thumb">
                    <?php echo get_vehicle_image($data['image_url'] ?? '', $data['make'] ?? '', $data['model'] ?? ''); ?>
                </div>
                <div class="vehicle-text-details">
                    <div class="info-row">
                        <span class="info-label">Make/Model:</span>
                        <span class="info-value"><?php echo htmlspecialchars($data['make'] . ' ' . $data['model']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Reg No:</span>
                        <span class="info-value"><?php echo htmlspecialchars($data['registration_number']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-group">
            <h3><i class="fas fa-calendar"></i> Booking Period</h3>
            <div class="info-row">
                <span class="info-label">Pick-up:</span>
                <span class="info-value"><?php echo format_date($data['pickup_date']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Return:</span>
                <span class="info-value"><?php echo format_date($data['return_date']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Duration:</span>
                <span class="info-value"><?php echo $data['duration_days']; ?> day(s)</span>
            </div>
        </div>

        <div class="info-group">
            <h3><i class="fas fa-credit-card"></i> Payment Details</h3>
            <div class="info-row">
                <span class="info-label">Booking Ref:</span>
                <span class="info-value"><?php echo htmlspecialchars($data['booking_reference']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Transaction ID:</span>
                <span class="info-value"><?php echo htmlspecialchars($data['transaction_reference']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Payment Date:</span>
                <span class="info-value"><?php echo format_date($data['payment_date']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Method:</span>
                <span class="info-value"><?php echo strtoupper($data['payment_method']); ?></span>
            </div>
        </div>
    </div>

    <div class="payment-summary">
        <h3>Payment Summary</h3>
        <div class="payment-row">
            <span>Daily Rate (<?php echo $data['duration_days']; ?> days)</span>
            <span><?php echo format_currency($daily_rate * $data['duration_days']); ?></span>
        </div>
        <?php if (isset($data['extras_cost']) && $data['extras_cost'] > 0): ?>
        <div class="payment-row">
            <span>Extras (GPS, child seat, etc.)</span>
            <span><?php echo format_currency($data['extras_cost']); ?></span>
        </div>
        <?php endif; ?>
        <div class="payment-row total">
            <span>Total Paid</span>
            <span><?php echo format_currency($data['paid_amount']); ?></span>
        </div>
    </div>

    <div class="receipt-footer">
        <p>Thank you for choosing <?php echo htmlspecialchars($settings['site_name']); ?>. This receipt serves as proof of payment.</p>
        <p>For any inquiries, contact our support team at <?php echo htmlspecialchars($settings['contact_email']); ?>.</p>
    </div>

    <div class="actions">
        <a href="?booking_id=<?php echo $booking_id; ?>&ref=<?php echo urlencode($trans_ref); ?>&print=1" class="btn btn-primary"><i class="fas fa-print"></i> Print Receipt</a>
        <a href="my-bookings.php" class="btn btn-outline"><i class="fas fa-list"></i> My Bookings</a>
        <a href="browse.php" class="btn btn-secondary"><i class="fas fa-search"></i> Browse Cars</a>
        <a href="feedback.php?booking_id=<?php echo $booking_id; ?>&ref=<?php echo urlencode($trans_ref); ?>" class="btn btn-secondary"><i class="fas fa-star"></i> Leave Feedback</a>
    </div>
</div>

<?php if (isset($_GET['print'])): ?>
<script>window.print();</script>
<?php endif; ?>
</body>
</html>
<?php $conn->close(); ?>

