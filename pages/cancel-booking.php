<?php
/**
 * ============================================================
 * Cancel Booking – Shows loading, then success window, then redirect
 * ============================================================
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');
define('APP_URL', getenv('APP_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: 'http://localhost/car-rental-kenya'));

// Database connection
require_once __DIR__ . '/../config/db.php';
$conn = db_connect();
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Login check
if (!is_logged_in()) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please login to cancel a booking'];
    header("Location: " . APP_URL . "/pages/login.php");
    exit();
}

// Get booking ID
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
if ($booking_id <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid booking ID'];
    header("Location: my-bookings.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// ------------------------------------------------------------------
// AJAX endpoint: Perform the cancellation and return JSON
// ------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    // Verify booking exists and belongs to user
    $stmt = $conn->prepare("SELECT id, payment_status, status FROM bookings WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    
    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found or you do not have permission to cancel it.']);
        exit();
    }
    
    // Check if already cancelled
    if (isset($booking['status']) && $booking['status'] === 'cancelled') {
        echo json_encode(['success' => false, 'message' => 'Booking is already cancelled.']);
        exit();
    }
    
    // Cannot cancel paid booking
    if ($booking['payment_status'] === 'paid') {
        echo json_encode(['success' => false, 'message' => 'Cannot cancel a paid booking. Please contact support.']);
        exit();
    }
    
    // Ensure bookings table has a 'status' column
    $columns = $conn->query("SHOW COLUMNS FROM bookings");
    $has_status = false;
    while ($col = $columns->fetch_assoc()) {
        if ($col['Field'] === 'status') {
            $has_status = true;
            break;
        }
    }
    if (!$has_status) {
        $conn->query("ALTER TABLE bookings ADD COLUMN status ENUM('pending','confirmed','cancelled','completed') DEFAULT 'pending'");
    }
    
    // Cancel the booking
    $update_booking = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
    $update_booking->bind_param("i", $booking_id);
    if (!$update_booking->execute()) {
        echo json_encode(['success' => false, 'message' => 'Failed to cancel booking: ' . $conn->error]);
        exit();
    }
    
    // Cancel any pending payment records
    $conn->query("UPDATE payments SET payment_status = 'cancelled' WHERE booking_id = $booking_id AND payment_status = 'pending'");
    
    // Store flash message for the next page
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'booking successfully cancelled'];
    
    echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully']);
    exit();
}

// ------------------------------------------------------------------
// Normal page load: Show loading animation, then AJAX call, then success window
// ------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancelling Booking - FleetKE</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            max-width: 400px;
            width: 90%;
            transition: all 0.3s ease;
        }
        .spinner {
            width: 80px;
            height: 80px;
            margin: 0 auto 25px;
            border: 8px solid #f3f3f3;
            border-top: 8px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .success-icon {
            font-size: 80px;
            color: #34a853;
            margin-bottom: 20px;
            animation: bounceIn 0.6s ease;
        }
        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }
        h2 {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.5rem;
        }
        p {
            color: #666;
            margin-bottom: 20px;
        }
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 20px;
        }
        .progress-fill {
            width: 0%;
            height: 100%;
            background: #667eea;
            transition: width 0.1s linear;
        }
        .booking-ref {
            background: #f0f0f0;
            padding: 8px 12px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.9rem;
            display: inline-block;
            margin-top: 15px;
        }
        .error-message {
            color: #ea4335;
            margin-top: 15px;
            padding: 10px;
            background: #fee;
            border-radius: 8px;
        }
        .btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #5a67d8;
        }
    </style>
</head>
<body>
    <div class="container" id="mainContainer">
        <div id="loadingView">
            <div class="spinner"></div>
            <h2>Cancelling Booking</h2>
            <p>Please wait while we process your cancellation request...</p>
            <div class="progress-bar">
                <div id="progressFill" class="progress-fill" style="width: 0%;"></div>
            </div>
            <div class="booking-ref">
                Booking ID: #<?php echo $booking_id; ?>
            </div>
        </div>
    </div>

    <script>
        const bookingId = <?php echo $booking_id; ?>;
        const container = document.getElementById('mainContainer');
        let progress = 0;
        const progressFill = document.getElementById('progressFill');
        
        // Animate progress bar from 0% to 100% over 2 seconds
        const progressInterval = setInterval(function() {
            if (progress >= 100) {
                clearInterval(progressInterval);
                // After progress reaches 100%, make the AJAX call
                cancelBooking();
            } else {
                progress += 2; // 2% every 40ms = 100% in 2000ms (2 seconds)
                if (progress > 100) progress = 100;
                progressFill.style.width = progress + '%';
            }
        }, 40);
        
        function cancelBooking() {
            fetch(window.location.href + '&ajax=1', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success window
                    showSuccessMessage();
                } else {
                    // Show error message
                    showErrorMessage(data.message || 'An error occurred while cancelling the booking.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorMessage('Network error. Please try again.');
            });
        }
        
        function showSuccessMessage() {
            container.innerHTML = `
                <div class="container">
                    <div class="success-icon">✓</div>
                    <h2>Booking Cancelled Successfully!</h2>
                    <p>Your booking has been cancelled. You will be redirected shortly...</p>
                    <div class="booking-ref">
                        Booking ID: #${bookingId}
                    </div>
                </div>
            `;
            // Redirect to booking details page after 2 seconds
            setTimeout(function() {
                window.location.href = 'booking-details.php?booking_id=' + bookingId;
            }, 2000);
        }
        
        function showErrorMessage(message) {
            container.innerHTML = `
                <div class="container">
                    <div class="success-icon" style="color: #ea4335;">✗</div>
                    <h2>Cancellation Failed</h2>
                    <p class="error-message">${message}</p>
                    <a href="booking-details.php?booking_id=${bookingId}" class="btn">Go Back to Booking</a>
                </div>
            `;
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>

