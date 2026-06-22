<?php
/**
 * ============================================================
 * return-vehicle.php – Handles vehicle return and updates status
 * Displays a success message before redirecting.
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

// Database connection
require_once __DIR__ . '/../config/db.php';
$conn = db_connect();
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Helper functions
function redirect($url) {
    header("Location: " . $url);
    exit();
}

function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

// Check if admin is logged in
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    set_flash('error', 'Unauthorized access. Please login as admin.');
    redirect('admin_login.php');
}

// Get parameters
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

if ($booking_id <= 0 || $vehicle_id <= 0) {
    set_flash('error', 'Invalid return request. Missing booking or vehicle ID.');
    redirect('admin_login.php');
}

// Ensure necessary columns exist in both tables
$conn->query("ALTER TABLE bookings ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'pending'");
$conn->query("ALTER TABLE bookings ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
$conn->query("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
$conn->query("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'available'");

// Verify that the vehicle is actually rented
$check_vehicle = $conn->prepare("SELECT status FROM vehicles WHERE id = ?");
if (!$check_vehicle) {
    $error_message = 'Database error: ' . $conn->error;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error - FleetKE</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .message-card {
                background: white;
                border-radius: 20px;
                padding: 40px;
                text-align: center;
                max-width: 500px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .message-card i { font-size: 70px; color: #dc3545; margin-bottom: 20px; }
            .message-card h2 { color: #333; margin-bottom: 10px; }
            .message-card p { color: #666; margin-bottom: 20px; }
            .btn { display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 8px; }
        </style>
    </head>
    <body>
        <div class="message-card">
            <i class="fas fa-exclamation-circle"></i>
            <h2>Error</h2>
            <p><?php echo htmlspecialchars($error_message); ?></p>
            <a href="admin_login.php" class="btn">Back to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}
$check_vehicle->bind_param("i", $vehicle_id);
$check_vehicle->execute();
$result = $check_vehicle->get_result();
$vehicle = $result->fetch_assoc();

if (!$vehicle || $vehicle['status'] !== 'rented') {
    $error_message = 'This vehicle is not currently rented.';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error - FleetKE</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .message-card {
                background: white;
                border-radius: 20px;
                padding: 40px;
                text-align: center;
                max-width: 500px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .message-card i { font-size: 70px; color: #dc3545; margin-bottom: 20px; }
            .message-card h2 { color: #333; margin-bottom: 10px; }
            .message-card p { color: #666; margin-bottom: 20px; }
            .btn { display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 8px; }
        </style>
    </head>
    <body>
        <div class="message-card">
            <i class="fas fa-exclamation-circle"></i>
            <h2>Error</h2>
            <p><?php echo htmlspecialchars($error_message); ?></p>
            <a href="admin_login.php" class="btn">Back to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // 1. Update booking status to 'completed'
    $update_booking = $conn->prepare("UPDATE bookings SET status = 'completed', updated_at = NOW() WHERE id = ?");
    if (!$update_booking) {
        throw new Exception("Prepare failed for bookings: " . $conn->error);
    }
    $update_booking->bind_param("i", $booking_id);
    if (!$update_booking->execute()) {
        throw new Exception("Failed to update booking: " . $update_booking->error);
    }

    // 2. Update vehicle status to 'available'
    $update_vehicle = $conn->prepare("UPDATE vehicles SET status = 'available', updated_at = NOW() WHERE id = ?");
    if (!$update_vehicle) {
        throw new Exception("Prepare failed for vehicles: " . $conn->error);
    }
    $update_vehicle->bind_param("i", $vehicle_id);
    if (!$update_vehicle->execute()) {
        throw new Exception("Failed to update vehicle: " . $update_vehicle->error);
    }

    // Commit transaction
    $conn->commit();

    // Display success message and redirect
    $success_message = '✅ Vehicle Returned Successfully! It is now available for new bookings.';
    $redirect_url = 'admin_login.php';
    
    // Output styled message page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Vehicle Returned - FleetKE</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .message-card {
                background: white;
                border-radius: 20px;
                padding: 40px;
                text-align: center;
                max-width: 500px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                animation: fadeIn 0.5s ease;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .message-card i { font-size: 70px; color: #28a745; margin-bottom: 20px; }
            .message-card h2 { color: #333; margin-bottom: 10px; }
            .message-card p { color: #666; margin-bottom: 20px; }
            .redirect-info { font-size: 14px; color: #999; margin-top: 20px; }
            .btn { display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 8px; margin-top: 15px; transition: background 0.3s; }
            .btn:hover { background: #5a67d8; }
        </style>
    </head>
    <body>
        <div class="message-card">
            <i class="fas fa-check-circle"></i>
            <h2>Vehicle Returned</h2>
            <p><?php echo htmlspecialchars($success_message); ?></p>
            <div class="redirect-info">
                <i class="fas fa-spinner fa-pulse"></i> Redirecting to dashboard in <span id="countdown">3</span> seconds...
            </div>
            <a href="<?php echo $redirect_url; ?>" class="btn">Go Now</a>
        </div>
        <script>
            let seconds = 3;
            const countdownSpan = document.getElementById('countdown');
            const interval = setInterval(() => {
                seconds--;
                countdownSpan.textContent = seconds;
                if (seconds <= 0) {
                    clearInterval(interval);
                    window.location.href = '<?php echo $redirect_url; ?>';
                }
            }, 1000);
        </script>
    </body>
    </html>
    <?php
    exit();
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Return vehicle error: " . $e->getMessage());
    $error_message = 'Failed to process return: ' . $e->getMessage();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error - FleetKE</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .message-card {
                background: white;
                border-radius: 20px;
                padding: 40px;
                text-align: center;
                max-width: 500px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .message-card i { font-size: 70px; color: #dc3545; margin-bottom: 20px; }
            .message-card h2 { color: #333; margin-bottom: 10px; }
            .message-card p { color: #666; margin-bottom: 20px; }
            .btn { display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 8px; }
        </style>
    </head>
    <body>
        <div class="message-card">
            <i class="fas fa-exclamation-circle"></i>
            <h2>Error</h2>
            <p><?php echo htmlspecialchars($error_message); ?></p>
            <a href="admin_login.php" class="btn">Back to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}
?>

