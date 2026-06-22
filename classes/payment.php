<?php
/**
 * ============================================================
 * M-Pesa Payment Page – Direct from Booking Confirmation (DIAGNOSTIC)
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
session_start();

// Configuration – must match other files
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

// Check login
if (!is_logged_in()) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please login to make payment'];
    header("Location: " . APP_URL . "/pages/login.php");
    exit();
}

// Get booking ID from URL
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
if ($booking_id <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'No booking specified'];
    header("Location: " . APP_URL . "/pages/my-bookings.php");
    exit();
}

// Fetch booking details and verify ownership
$query = "SELECT b.*, v.make, v.model, v.registration_number
          FROM bookings b
          LEFT JOIN vehicles v ON b.vehicle_id = v.id
          WHERE b.id = ? AND b.user_id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Prepare failed (booking fetch): (" . $conn->errno . ") " . $conn->error);
}
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

// ------------------------------------------------------------
// Ensure payments table exists – with verification
// ------------------------------------------------------------
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
if (!$conn->query($create_table)) {
    die("Error creating payments table: " . $conn->error);
}

// ------------------------------------------------------------
// Get actual columns of the payments table
// ------------------------------------------------------------
$columns_result = $conn->query("SHOW COLUMNS FROM payments");
if (!$columns_result) {
    die("Could not fetch columns: " . $conn->error);
}
$existing_columns = [];
while ($col = $columns_result->fetch_assoc()) {
    $existing_columns[] = $col['Field'];
}

// Display columns for debugging (remove after fixing)
echo "<!-- Existing columns: " . implode(', ', $existing_columns) . " -->\n";

// Define the columns we want to insert (adjust names to match your table)
$desired_columns = [
    'booking_id',
    'user_id',
    'amount',
    'payment_method',
    'transaction_reference',
    'payment_status',
    // Use 'created_at' or 'payment_date' whichever exists
];

// Find a timestamp column (created_at, payment_date, etc.)
$timestamp_col = null;
foreach (['created_at', 'payment_date', 'created', 'date_added'] as $possible) {
    if (in_array($possible, $existing_columns)) {
        $timestamp_col = $possible;
        break;
    }
}
if (!$timestamp_col) {
    die("No timestamp column found in payments table. Please add one (e.g., created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP).");
}

// Build INSERT dynamically using only columns that exist
$insert_columns = [];
$insert_placeholders = [];
$bind_types = '';
$bind_values = [];

foreach ($desired_columns as $col) {
    if (in_array($col, $existing_columns)) {
        $insert_columns[] = $col;
        $insert_placeholders[] = '?';
        // Determine bind type
        if ($col == 'booking_id' || $col == 'user_id') {
            $bind_types .= 'i';
        } elseif ($col == 'amount') {
            $bind_types .= 'd';
        } else {
            $bind_types .= 's';
        }
    }
}

// Add the timestamp column
$insert_columns[] = $timestamp_col;
$insert_placeholders[] = 'NOW()'; // no placeholder, use MySQL function

$insert_sql = "INSERT INTO payments (" . implode(', ', $insert_columns) . ") 
               VALUES (" . implode(', ', $insert_placeholders) . ")";

// ------------------------------------------------------------
// Check for existing pending payment or create new one
// ------------------------------------------------------------
$stmt = $conn->prepare("SELECT * FROM payments WHERE booking_id = ? AND payment_status = 'pending' ORDER BY id DESC LIMIT 1");
if (!$stmt) {
    die("Prepare failed (check existing): Error " . $conn->errno . ": " . $conn->error . " | SQL: SELECT * FROM payments WHERE booking_id = ? AND payment_status = 'pending' ORDER BY id DESC LIMIT 1");
}
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$existing_payment = $stmt->get_result()->fetch_assoc();

if ($existing_payment) {
    $trans_ref = $existing_payment['transaction_reference'];
} else {
    // Create a new payment record
    $trans_ref = 'TXN' . date('YmdHis') . rand(1000, 9999);
    
    // Prepare the dynamic INSERT
    $stmt = $conn->prepare($insert_sql);
    if (!$stmt) {
        die("Prepare failed (insert): Error " . $conn->errno . ": " . $conn->error . " | SQL: " . $insert_sql);
    }
    
    // Bind parameters in the order of $desired_columns (only those that exist)
    $bind_params = [];
    foreach ($desired_columns as $col) {
        if (in_array($col, $existing_columns)) {
            if ($col == 'booking_id') $bind_params[] = $booking_id;
            elseif ($col == 'user_id') $bind_params[] = $_SESSION['user_id'];
            elseif ($col == 'amount') $bind_params[] = $booking['total_amount'];
            elseif ($col == 'payment_method') $bind_params[] = 'mpesa';
            elseif ($col == 'transaction_reference') $bind_params[] = $trans_ref;
            elseif ($col == 'payment_status') $bind_params[] = 'pending';
            // add other columns as needed
        }
    }
    
    // Use call_user_func_array to bind dynamically
    $stmt->bind_param($bind_types, ...$bind_params);
    
    if (!$stmt->execute()) {
        die("Execute failed (insert): Error " . $stmt->errno . ": " . $stmt->error);
    }
}

$user_name = $_SESSION['user_name'] ?? 'User';
$message = '';

// Handle form submission (simulate STK push)
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M-Pesa Payment - <?php echo htmlspecialchars($booking['booking_reference']); ?> | FleetKE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* (your existing CSS – unchanged) */
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #d31b96 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; padding: 20px; }
        .mpesa-box { background: white; max-width: 450px; width: 100%; padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); text-align: center; }
        .mpesa-box h2 { color: #202124; margin-bottom: 10px; }
        .booking-ref { background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 20px 0; font-family: monospace; font-size: 1.1rem; color: #1a73e8; }
        .form-group { margin-bottom: 20px; text-align: left; }
        .form-group label { display: block; margin-bottom: 5px; color: #5f6368; font-weight: 500; }
        .form-control { width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 1rem; }
        .btn { background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%); color: white; border: none; padding: 14px 20px; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; width: 100%; transition: 0.3s; text-decoration: none; display: inline-block; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
        .btn-secondary { background: #f8f9fa; color: #5f6368; border: 2px solid #e0e0e0; margin-top: 10px; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: left; }
        .alert-success { background: #e6f4ea; color: #34a853; border: 1px solid #b8e0c5; }
        .alert-danger { background: #fee; color: #c33; border: 1px solid #fcc; }
        .info-text { color: #5f6368; font-size: 0.9rem; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="mpesa-box">
        <i class="fas fa-mobile-alt" style="font-size: 3rem; color: #1a73e8; margin-bottom: 15px;"></i>
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
            <div style="margin-top: 20px;">
                <a href="payment-complete.php?booking_id=<?php echo $booking_id; ?>&ref=<?php echo $trans_ref; ?>" class="btn" style="background: #34a853;"><i class="fas fa-check-circle"></i> Payment Completed (Simulation)</a>
            </div>
        <?php endif; ?>

        <div style="margin-top: 20px;">
            <a href="confirm_booking.php?booking_id=<?php echo $booking_id; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Cancel and Go Back</a>
        </div>
    </div>
</body>
</html>

