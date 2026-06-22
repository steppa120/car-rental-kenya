<?php
/**
 * ============================================================
 * M-Pesa Payment Page – with Bubbles Glassmorphism
 * ============================================================
 */
error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
session_start();

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

function is_logged_in() {
    return isset($_SESSION['user_id']);
}
function format_currency($amount) {
    return 'Ksh ' . number_format($amount, 2);
}

// Login check
if (!is_logged_in()) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please login to make payment'];
    header("Location: " . APP_URL . "/pages/login.php");
    exit();
}

// Get booking ID from URL
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
if ($booking_id <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'No booking specified'];
    header("Location: my-bookings.php");
    exit();
}

// Fetch booking details, verify ownership, and check cancellation status
$query = "SELECT b.*, v.make, v.model, v.registration_number 
          FROM bookings b
          LEFT JOIN vehicles v ON b.vehicle_id = v.id
          WHERE b.id = ? AND b.user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Booking not found'];
    header("Location: my-bookings.php");
    exit();
}

// Prevent payment if booking is already cancelled
if (isset($booking['status']) && $booking['status'] === 'cancelled') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Cannot pay for a cancelled booking.'];
    header("Location: booking-details.php?booking_id=" . $booking_id);
    exit();
}

// If already paid, redirect to confirmation page
if ($booking['payment_status'] === 'paid') {
    header("Location: booking-confirmation.php?booking_id=" . $booking_id);
    exit();
}

// Ensure payments table exists
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

// Get actual columns of payments table
$columns_result = $conn->query("SHOW COLUMNS FROM payments");
$existing_columns = [];
while ($col = $columns_result->fetch_assoc()) {
    $existing_columns[] = $col['Field'];
}
$timestamp_col = 'created_at';
foreach (['created_at', 'payment_date', 'created', 'date_added'] as $possible) {
    if (in_array($possible, $existing_columns)) {
        $timestamp_col = $possible;
        break;
    }
}

// Check for existing pending payment, otherwise create a new one
$stmt = $conn->prepare("SELECT * FROM payments WHERE booking_id = ? AND payment_status = 'pending' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$existing_payment = $stmt->get_result()->fetch_assoc();

if ($existing_payment) {
    $trans_ref = $existing_payment['transaction_reference'];
} else {
    $trans_ref = 'TXN' . date('YmdHis') . rand(1000, 9999);
    
    $desired_columns = ['booking_id', 'user_id', 'amount', 'payment_method', 'transaction_reference', 'payment_status'];
    $insert_columns = [];
    $insert_placeholders = [];
    $bind_types = '';
    $bind_values = [];
    
    foreach ($desired_columns as $col) {
        if (in_array($col, $existing_columns)) {
            $insert_columns[] = $col;
            $insert_placeholders[] = '?';
            if ($col == 'booking_id' || $col == 'user_id') $bind_types .= 'i';
            elseif ($col == 'amount') $bind_types .= 'd';
            else $bind_types .= 's';
        }
    }
    $insert_columns[] = $timestamp_col;
    $insert_placeholders[] = 'NOW()';
    
    $insert_sql = "INSERT INTO payments (" . implode(',', $insert_columns) . ") VALUES (" . implode(',', $insert_placeholders) . ")";
    $stmt = $conn->prepare($insert_sql);
    
    $bind_params = [];
    foreach ($desired_columns as $col) {
        if (in_array($col, $existing_columns)) {
            if ($col == 'booking_id') $bind_params[] = $booking_id;
            elseif ($col == 'user_id') $bind_params[] = $_SESSION['user_id'];
            elseif ($col == 'amount') $bind_params[] = $booking['total_amount'];
            elseif ($col == 'payment_method') $bind_params[] = 'mpesa';
            elseif ($col == 'transaction_reference') $bind_params[] = $trans_ref;
            elseif ($col == 'payment_status') $bind_params[] = 'pending';
        }
    }
    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
}

// Handle STK Push simulation
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = $_POST['phone'] ?? '';
    if (preg_match('/^(07|01)\d{8}$/', $phone)) {
        $message = "<div class='alert alert-success'>✅ STK push sent to $phone. Please check your phone and enter PIN.</div>";
    } else {
        $message = "<div class='alert alert-danger'>❌ Invalid phone number. Use 07XXXXXXXX or 01XXXXXXXX.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>M-Pesa Payment - <?php echo htmlspecialchars($booking['booking_reference']); ?> | FleetKE</title>
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
            background: radial-gradient(ellipse at 30% 40%, #1a2a6c, #b21f1f, #fdbb4d);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* ----- BUBBLES GLASSMORPHISM CONTAINER ----- */
        .bubbles-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .bubble {
            position: absolute;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(4px);
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            pointer-events: none;
            animation: floatBubble linear infinite;
        }

        @keyframes floatBubble {
            0% {
                transform: translateY(100vh) scale(0.8);
                opacity: 0;
            }
            20% {
                opacity: 0.6;
            }
            80% {
                opacity: 0.6;
            }
            100% {
                transform: translateY(-20vh) scale(1.2);
                opacity: 0;
            }
        }

        /* ----- GLASS CARD (mpesa-box) ----- */
        .mpesa-box {
            position: relative;
            z-index: 10;
            max-width: 480px;
            width: 100%;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-radius: 32px;
            padding: 2rem;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.15);
            text-align: center;
            transition: transform 0.2s ease;
            border: 1px solid rgba(255, 255, 255, 0.4);
        }

        .mpesa-box:hover {
            transform: translateY(-4px);
        }

        .mpesa-box i.fa-mobile-alt {
            font-size: 3.2rem;
            background: linear-gradient(135deg, #fff, #ffd966);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 12px;
            text-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .mpesa-box h2 {
            color: white;
            margin-bottom: 8px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        .mpesa-box p {
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 12px;
        }

        .booking-ref {
            background: rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(4px);
            padding: 12px;
            border-radius: 20px;
            margin: 18px 0;
            font-family: monospace;
            font-size: 1rem;
            font-weight: bold;
            color: #ffd966;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .form-group {
            margin-bottom: 22px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-group label i {
            margin-right: 8px;
            color: #ffd966;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 28px;
            font-size: 1rem;
            color: white;
            outline: none;
            transition: all 0.2s;
        }

        .form-control:focus {
            background: rgba(255, 255, 255, 0.25);
            border-color: #ffd966;
            box-shadow: 0 0 0 3px rgba(255, 217, 102, 0.3);
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .info-text {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 6px;
            display: block;
        }

        .btn {
            background: linear-gradient(135deg, #ffb347, #ff8c00);
            border: none;
            padding: 12px 20px;
            border-radius: 40px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.25s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            color: #0a0f2a;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(4px);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.4);
            box-shadow: none;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            color: #ffd966;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 28px;
            margin-bottom: 20px;
            text-align: left;
            font-size: 0.9rem;
            backdrop-filter: blur(4px);
        }

        .alert-success {
            background: rgba(52, 168, 83, 0.25);
            color: #e6f4ea;
            border: 1px solid rgba(52, 168, 83, 0.6);
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.25);
            color: #ffe0e0;
            border: 1px solid rgba(220, 53, 69, 0.6);
        }

        .mt-20 {
            margin-top: 20px;
        }

        @media (max-width: 500px) {
            .mpesa-box {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>

<!-- Bubbles Glassmorphism Background -->
<div class="bubbles-container" id="bubblesContainer"></div>

<div class="mpesa-box">
    <i class="fas fa-mobile-alt"></i>
    <h2>M-Pesa Payment</h2>
    <p><strong><?php echo htmlspecialchars($booking['make'] . ' ' . $booking['model']); ?></strong></p>
    <p>Booking: <?php echo htmlspecialchars($booking['booking_reference']); ?></p>
    <p>Total Amount: <strong><?php echo format_currency($booking['total_amount']); ?></strong></p>
    <div class="booking-ref">Ref: <?php echo htmlspecialchars($trans_ref); ?></div>

    <?php echo $message; ?>

    <form method="post">
        <div class="form-group">
            <label for="phone"><i class="fas fa-phone"></i> M-Pesa Phone Number</label>
            <input type="text" class="form-control" id="phone" name="phone" placeholder="e.g., 0712345678" required value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
            <small class="info-text">Enter the phone number registered with M-Pesa</small>
        </div>
        <button type="submit" class="btn"><i class="fas fa-paper-plane"></i> Send STK Push</button>
    </form>

    <?php if (strpos($message, '✅') !== false): ?>
        <div class="mt-20">
            <a href="payment-complete.php?booking_id=<?php echo $booking_id; ?>&ref=<?php echo $trans_ref; ?>" class="btn" style="background: #34a853;"><i class="fas fa-check-circle"></i> Payment Completed (Simulation)</a>
        </div>
    <?php endif; ?>

    <div class="mt-20">
        <a href="booking-details.php?booking_id=<?php echo $booking_id; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Go Back</a>
    </div>
</div>

<script>
    (function() {
        // Generate floating glass bubbles
        const bubblesContainer = document.getElementById('bubblesContainer');
        if (!bubblesContainer) return;

        const BUBBLE_COUNT = 22;
        for (let i = 0; i < BUBBLE_COUNT; i++) {
            const bubble = document.createElement('div');
            bubble.classList.add('bubble');

            // Random size between 30px and 180px
            const size = Math.floor(Math.random() * 150 + 30);
            bubble.style.width = size + 'px';
            bubble.style.height = size + 'px';

            // Random horizontal position
            bubble.style.left = Math.random() * 100 + '%';

            // Random animation duration (8s to 18s)
            const duration = Math.random() * 10 + 8;
            bubble.style.animationDuration = duration + 's';

            // Random delay to stagger them
            const delay = Math.random() * 5;
            bubble.style.animationDelay = delay + 's';

            bubblesContainer.appendChild(bubble);
        }
    })();
</script>
</body>
</html>

