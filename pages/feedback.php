<?php
/**
 * ============================================================
 * Feedback Page – Allows user to leave a rating and review after payment
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
function format_date($date) {
    return date('d M Y, h:i A', strtotime($date));
}

// Check login
if (!is_logged_in()) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please login to leave feedback'];
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

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ------------------------------------------------------------
// Ensure feedback table exists with rating column
// ------------------------------------------------------------
$conn->query("CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    rating INT DEFAULT 5,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)");

// Check if rating column exists, if not add it
$check_rating = $conn->query("SHOW COLUMNS FROM feedback LIKE 'rating'");
if ($check_rating && $check_rating->num_rows == 0) {
    $conn->query("ALTER TABLE feedback ADD rating INT DEFAULT 5 AFTER message");
}

// ------------------------------------------------------------
// Verify booking belongs to user and payment is completed
// ------------------------------------------------------------
$query = "
    SELECT b.*, p.payment_status, p.transaction_reference
    FROM bookings b
    LEFT JOIN payments p ON b.id = p.booking_id AND p.transaction_reference = ?
    WHERE b.id = ? AND b.user_id = ?
";
$stmt = $conn->prepare($query);
$stmt->bind_param("sii", $trans_ref, $booking_id, $_SESSION['user_id']);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Booking not found'];
    header("Location: " . APP_URL . "/pages/my-bookings.php");
    exit();
}

if ($booking['payment_status'] !== 'completed') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Payment not completed. You can only leave feedback after payment.'];
    header("Location: " . APP_URL . "/pages/mpesa-payment.php?booking_id=$booking_id");
    exit();
}

// Check if user already submitted feedback for this booking? (optional – prevent duplicates)
// For simplicity, we allow multiple feedback entries; adjust if needed.

// Handle form submission
$feedback_success = '';
$feedback_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    // Verify CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $feedback_error = 'Invalid session. Please try again.';
    } else {
        $rating = (int)$_POST['rating'];
        $message = trim($_POST['message']);
        if ($rating < 1 || $rating > 5) $rating = 5;
        if (empty($message)) {
            $feedback_error = 'Please enter your review.';
        } else {
            // Get user info
            $user_id = $_SESSION['user_id'];
            $stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $name = $user['first_name'] . ' ' . $user['last_name'];
            $email = $user['email'];

            $stmt = $conn->prepare("INSERT INTO feedback (user_id, name, email, message, rating, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->bind_param("isssi", $user_id, $name, $email, $message, $rating);
            if ($stmt->execute()) {
                $feedback_success = 'Thank you for your feedback!';
                // Optionally redirect back to receipt after a few seconds
                header("refresh:3;url=payment-receipt.php?booking_id=$booking_id&ref=" . urlencode($trans_ref));
            } else {
                $feedback_error = 'Failed to submit feedback. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Feedback | FleetKE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .feedback-container {
            max-width: 600px;
            width: 100%;
            background:violet;
            border-radius: 5px;
            box-shadow: 0 80px 90px rgb(22, 250, 1);
            padding: 30px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        h1 i {
            color: #667eea;
        }
        .thankyou-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .booking-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }
        .booking-info p {
            margin: 5px 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
        }
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        .rating-container {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            gap: 5px;
        }
        .rating-container input[type="radio"] {
            display: none;
        }
        .rating-container label {
            font-size: 2rem;
            color: #ddd;
            cursor: pointer;
            transition: color 0.2s;
        }
        .rating-container input[type="radio"]:checked ~ label,
        .rating-container label:hover,
        .rating-container label:hover ~ label {
            color: #ffc107;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(102,126,234,0.4);
        }
        .btn-outline {
            background: transparent;
            border: 1px solid #667eea;
            color: #667eea;
        }
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="feedback-container">
        <h1><i class="fas fa-star"></i> Leave Feedback</h1>

        <?php if ($feedback_success): ?>
            <div class="thankyou-message">
                <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 10px;"></i>
                <h2>Thank You!</h2>
                <p><?php echo htmlspecialchars($feedback_success); ?></p>
                <p>You will be redirected to your receipt in a few seconds.</p>
                <a href="payment-receipt.php?booking_id=<?php echo $booking_id; ?>&ref=<?php echo urlencode($trans_ref); ?>" class="btn btn-outline" style="margin-top: 10px;">Return to Receipt Now</a>
            </div>
        <?php else: ?>

            <?php if ($feedback_error): ?>
                <div class="alert-error"><?php echo htmlspecialchars($feedback_error); ?></div>
            <?php endif; ?>

            <div class="booking-info">
                <p><strong>Booking Reference:</strong> <?php echo htmlspecialchars($booking['booking_reference']); ?></p>
                <p><strong>Transaction Ref:</strong> <?php echo htmlspecialchars($trans_ref); ?></p>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="form-group">
                    <label>Your Rating</label>
                    <div class="rating-container">
                        <input type="radio" name="rating" value="5" id="star5" checked><label for="star5">★</label>
                        <input type="radio" name="rating" value="4" id="star4"><label for="star4">★</label>
                        <input type="radio" name="rating" value="3" id="star3"><label for="star3">★</label>
                        <input type="radio" name="rating" value="2" id="star2"><label for="star2">★</label>
                        <input type="radio" name="rating" value="1" id="star1"><label for="star1">★</label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="message">Your Review</label>
                    <textarea class="form-control" id="message" name="message" placeholder="Tell us about your experience..." required></textarea>
                </div>

                <div class="actions">
                    <button type="submit" name="submit_feedback" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Feedback</button>
                    <a href="payment-receipt.php?booking_id=<?php echo $booking_id; ?>&ref=<?php echo urlencode($trans_ref); ?>" class="btn btn-outline"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>

        <?php endif; ?>
    </div>
</body>
</html>
<?php $conn->close(); ?>

