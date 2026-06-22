```php
<?php
/**
 * ============================================================
 * Book Now Page - Vehicle Booking (BUBBLES + GLASSMORPHISM)
 * Fully aligned, spaced, and confirm button centered.
 * ADDED: Admin view‑only restriction – redirects to admin login.
 * ADDED: Location fields now only accept valid place names (autocheck).
 * UPDATED: Added Roysambu and Safari Park to allowed locations.
 * MODIFIED: Booking declines if total amount exceeds 480,000 Ksh.
 * ADDED: Dedicated visible space showing discount offered by admin.
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
ob_start();

// ========== ADMIN VIEW‑ONLY RESTRICTION ==========
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_admin_view_only() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'
           && isset($_SESSION['admin_view_only']) && $_SESSION['admin_view_only'] === true;
}

if (is_admin_view_only()) {
    header('Location: admin_login.php');
    exit();
}
// ========== END ADMIN RESTRICTION ==========

// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');
define('APP_URL', getenv('APP_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: 'http://localhost/car-rental-kenya'));

// ---------- ALLOWED PLACE NAMES (for pickup/return location) ----------
$ALLOWED_LOCATIONS = [
    // Major towns & estates
    'Westlands', 'karatina', 'Kilimani', 'Karen', 'nyahururu',
    'nanyuki', 'kenol', 'kisumu town', 'nyali', 'gilgil', 'thika',
    'kiambu town', 'ruiru', 'roysambu', 'safaripark', 'kitengela',
];
$ALLOWED_LOCATIONS = array_map('strtolower', $ALLOWED_LOCATIONS); // case-insensitive check

// Kenya Counties (for dropdowns)
$KENYA_COUNTIES = [
    'nairobi' => 'Nairobi',
    'kiambu' => 'kiambu',
    'nyeri' => 'Nyeri',
    'laikipia' => 'laikipia',
    'mombasa' => 'Mombasa',
    'kisumu' => 'Kisumu',
    'nakuru' => 'Nakuru',
    'nyandarua' => 'nyandarua',
    'muranga' => 'muranga',
    'kajiado' => 'Kajiado',   
];

// Database Connection
try {
require_once __DIR__ . '/../config/db.php';
    $conn = db_connect();
    if ($conn->connect_error) throw new Exception("Connection failed: " . $conn->connect_error);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// ============================================================
// ENSURE BOOKINGS TABLE HAS ALL REQUIRED COLUMNS
// ============================================================
$table_check = $conn->query("SHOW TABLES LIKE 'bookings'");
if (!$table_check || $table_check->num_rows == 0) {
    $create_sql = "CREATE TABLE `bookings` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `booking_reference` VARCHAR(20) NOT NULL UNIQUE,
        `user_id` INT(11) NOT NULL,
        `vehicle_id` INT(11) NOT NULL,
        `pickup_date` DATETIME NOT NULL,
        `return_date` DATETIME NOT NULL,
        `pickup_location` VARCHAR(255) DEFAULT NULL,
        `return_location` VARCHAR(255) DEFAULT NULL,
        `pickup_county` VARCHAR(50) NOT NULL,
        `return_county` VARCHAR(50) NOT NULL,
        `daily_rate` DECIMAL(10,2) NOT NULL,
        `number_of_days` INT(11) NOT NULL,
        `base_amount` DECIMAL(10,2) NOT NULL,
        `discount_amount` DECIMAL(10,2) DEFAULT 0,
        `total_amount` DECIMAL(10,2) NOT NULL,
        `payment_status` ENUM('pending','paid','failed') DEFAULT 'pending',
        `booking_status` ENUM('pending','confirmed','active','completed','cancelled') DEFAULT 'pending',
        `notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_vehicle_dates` (`vehicle_id`, `pickup_date`, `return_date`),
        KEY `idx_booking_status` (`booking_status`),
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conn->query($create_sql)) die("Failed to create bookings table: " . $conn->error);
} else {
    $columns_to_add = [
        'pickup_location' => "ALTER TABLE `bookings` ADD COLUMN `pickup_location` VARCHAR(255) DEFAULT NULL AFTER `return_date`",
        'return_location' => "ALTER TABLE `bookings` ADD COLUMN `return_location` VARCHAR(255) DEFAULT NULL AFTER `pickup_location`",
        'pickup_county' => "ALTER TABLE `bookings` ADD COLUMN `pickup_county` VARCHAR(50) NOT NULL AFTER `return_location`",
        'return_county' => "ALTER TABLE `bookings` ADD COLUMN `return_county` VARCHAR(50) NOT NULL AFTER `pickup_county`",
        'daily_rate' => "ALTER TABLE `bookings` ADD COLUMN `daily_rate` DECIMAL(10,2) NOT NULL AFTER `return_county`",
        'number_of_days' => "ALTER TABLE `bookings` ADD COLUMN `number_of_days` INT(11) NOT NULL AFTER `daily_rate`",
        'base_amount' => "ALTER TABLE `bookings` ADD COLUMN `base_amount` DECIMAL(10,2) NOT NULL AFTER `number_of_days`",
        'discount_amount' => "ALTER TABLE `bookings` ADD COLUMN `discount_amount` DECIMAL(10,2) DEFAULT 0 AFTER `base_amount`",
        'notes' => "ALTER TABLE `bookings` ADD COLUMN `notes` TEXT AFTER `booking_status`"
    ];
    foreach ($columns_to_add as $col => $sql) {
        $check = $conn->query("SHOW COLUMNS FROM `bookings` LIKE '$col'");
        if (!$check || $check->num_rows == 0) $conn->query($sql);
    }
    $status_check = $conn->query("SHOW COLUMNS FROM `bookings` LIKE 'booking_status'");
    if ($status_check && $status_check->num_rows == 0) {
        $conn->query("ALTER TABLE `bookings` ADD COLUMN `booking_status` ENUM('pending','confirmed','active','completed','cancelled') DEFAULT 'pending' AFTER `payment_status`");
    }
    $payment_check = $conn->query("SHOW COLUMNS FROM `bookings` LIKE 'payment_status'");
    if ($payment_check && $payment_check->num_rows == 0) {
        $conn->query("ALTER TABLE `bookings` ADD COLUMN `payment_status` ENUM('pending','paid','failed') DEFAULT 'pending' AFTER `total_amount`");
    }
}

// ============================================================
// ENSURE VEHICLES TABLE HAS DISCOUNT COLUMN
// ============================================================
$discount_col_check = $conn->query("SHOW COLUMNS FROM `vehicles` LIKE 'discount_percent'");
if (!$discount_col_check || $discount_col_check->num_rows == 0) {
    $conn->query("ALTER TABLE `vehicles` ADD COLUMN `discount_percent` DECIMAL(5,2) DEFAULT 0 AFTER `daily_rate`");
}

// Fetch dynamic settings
$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($result) {
    while ($row = $result->fetch_assoc()) $settings[$row['setting_key']] = $row['setting_value'];
}
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
define('APP_NAME', $settings['site_name']);
define('CURRENCY', $settings['currency']);
define('CURRENCY_SYMBOL', $settings['currency']);

// Helper functions
function sanitize($data) {
    global $conn;
    return $conn->real_escape_string(trim(htmlspecialchars($data)));
}
function redirect($url) {
    header("Location: " . APP_URL . $url);
    exit();
}
function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}
function get_flash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
function is_logged_in() {
    return isset($_SESSION['user_id']);
}
function verify_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function format_currency($amount) {
    global $settings;
    return $settings['currency'] . ' ' . number_format($amount, 2);
}
function get_vehicle_image_html($vehicle, $app_url) {
    if (empty($vehicle['image_url'])) {
        return '<i class="fas fa-car" style="font-size:6rem; color:#1a73e8;"></i>';
    }
    $image_path = trim($vehicle['image_url'], '/');
    $image_path = str_replace('\\', '/', $image_path);
    $src = $app_url . '/' . $image_path;
    return '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) . '" style="width:100%; height:100%; object-fit:cover;">';
}
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Classes
class Vehicle {
    private $conn;
    private $table = 'vehicles';
    public function __construct($database) { $this->conn = $database; }
    public function getVehicleById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM " . $this->table . " WHERE id = ?");
        if (!$stmt) return null;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $vehicle = $stmt->get_result()->fetch_assoc();
        // Ensure discount_percent is set, default 0
        if (!isset($vehicle['discount_percent'])) $vehicle['discount_percent'] = 0;
        return $vehicle;
    }
}

class User {
    private $conn;
    private $table = 'users';
    public function __construct($database) { $this->conn = $database; }
    public function getUserById($id) {
        $stmt = $this->conn->prepare("SELECT id, first_name, last_name, email, phone, county FROM " . $this->table . " WHERE id = ?");
        if (!$stmt) return null;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

class Booking {
    private $conn;
    private $table = 'bookings';
    private $lastError = '';
    public function __construct($database) { $this->conn = $database; }
    public function getLastError() { return $this->lastError; }

    public function isVehicleAvailable($vehicle_id, $pickup_date, $return_date) {
        $pickup = date('Y-m-d H:i:s', strtotime($pickup_date));
        $return = date('Y-m-d H:i:s', strtotime($return_date));
        $query = "SELECT COUNT(*) as count FROM " . $this->table . " 
                  WHERE vehicle_id = ? 
                    AND booking_status IN ('pending', 'confirmed', 'active')
                    AND pickup_date < ? 
                    AND return_date > ?";
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            $this->lastError = "Prepare failed: " . $this->conn->error;
            return false;
        }
        $stmt->bind_param("iss", $vehicle_id, $return, $pickup);
        if (!$stmt->execute()) {
            $this->lastError = "Execute failed: " . $stmt->error;
            $stmt->close();
            return false;
        }
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $count = $row['count'];
        $stmt->close();
        return ($count == 0);
    }

    public function createBooking($data) {
        try {
            if (empty($data['user_id']) || empty($data['vehicle_id']) || empty($data['pickup_date']) || 
                empty($data['return_date']) || empty($data['pickup_county']) || empty($data['return_county'])) {
                return false;
            }
            $booking_reference = 'BK' . date('YmdHis') . rand(1000, 9999);
            $query = "INSERT INTO " . $this->table . " 
                      (booking_reference, user_id, vehicle_id, pickup_date, return_date, 
                       pickup_location, return_location, pickup_county, return_county,
                       daily_rate, number_of_days, base_amount, discount_amount, total_amount, 
                       payment_status, booking_status, notes, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, NOW())";
            $stmt = $this->conn->prepare($query);
            if (!$stmt) return false;
            $stmt->bind_param("siissssssidddss", 
                $booking_reference, $data['user_id'], $data['vehicle_id'], 
                $data['pickup_date'], $data['return_date'],
                $data['pickup_location'], $data['return_location'], 
                $data['pickup_county'], $data['return_county'],
                $data['daily_rate'], $data['number_of_days'], 
                $data['base_amount'], $data['discount_amount'], $data['total_amount'],
                $data['notes']
            );
            if ($stmt->execute()) {
                return $this->conn->insert_id;
            }
            return false;
        } catch (Exception $e) {
            error_log("Booking exception: " . $e->getMessage());
            return false;
        }
    }
}

// Main logic
if (!is_logged_in()) {
    set_flash('error', 'Please login to book a vehicle');
    redirect('/pages/login.php');
}

$vehicle = new Vehicle($conn);
$booking = new Booking($conn);
$user = new User($conn);

$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
if ($vehicle_id <= 0) {
    set_flash('error', 'Please select a vehicle to book');
    redirect('/pages/browse.php');
}

$vehicle_details = $vehicle->getVehicleById($vehicle_id);
if (!$vehicle_details) {
    set_flash('error', 'Vehicle not found');
    redirect('/pages/browse.php');
}
if ($vehicle_details['status'] !== 'available') {
    set_flash('error', 'Vehicle is not available for booking');
    redirect('/pages/browse.php');
}

$user_details = $user->getUserById($_SESSION['user_id']);
if (!$user_details) {
    session_destroy();
    set_flash('error', 'User session expired. Please login again.');
    redirect('/pages/login.php');
}

$error = '';

// Helper function to validate location name
function isValidLocation($location) {
    global $ALLOWED_LOCATIONS;
    if (empty($location)) return true; // empty is allowed (optional field)
    return in_array(strtolower(trim($location)), $ALLOWED_LOCATIONS);
}

// Get discount percent from vehicle (admin set)
$discount_percent = floatval($vehicle_details['discount_percent'] ?? 0);
$discount_label = $discount_percent > 0 ? $discount_percent . '% OFF' : 'No discount';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_token($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $pickup_date = trim($_POST['pickup_date'] ?? '');
        $pickup_time = trim($_POST['pickup_time'] ?? '');
        $return_date = trim($_POST['return_date'] ?? '');
        $return_time = trim($_POST['return_time'] ?? '');
        $pickup_county = sanitize($_POST['pickup_county'] ?? '');
        $return_county = sanitize($_POST['return_county'] ?? '');
        $pickup_location = sanitize($_POST['pickup_location'] ?? '');
        $return_location = sanitize($_POST['return_location'] ?? '');
        $notes = sanitize($_POST['notes'] ?? '');

        // Validate location names (if provided)
        if (!empty($pickup_location) && !isValidLocation($pickup_location)) {
            $error = 'Invalid pickup location. Please enter a valid place name (e.g., Nairobi, Westlands, Roysambu, Safari Park).';
        } elseif (!empty($return_location) && !isValidLocation($return_location)) {
            $error = 'Invalid return location. Please enter a valid place name.';
        } elseif (empty($pickup_date) || empty($pickup_time) || empty($return_date) || empty($return_time) || empty($pickup_county) || empty($return_county)) {
            $error = 'Please fill in all required fields';
        } else {
            $pickup_datetime = $pickup_date . ' ' . $pickup_time . ':00';
            $return_datetime = $return_date . ' ' . $return_time . ':00';
            try {
                $pickup = new DateTime($pickup_datetime);
                $return = new DateTime($return_datetime);
                $now = new DateTime();
                if ($pickup < $now) {
                    $error = 'Pickup date cannot be in the past';
                } elseif ($return <= $pickup) {
                    $error = 'Return date must be after pickup date';
                } else {
                    $is_available = $booking->isVehicleAvailable($vehicle_id, $pickup_datetime, $return_datetime);
                    if (!$is_available) {
                        $error = 'Vehicle is not available for the selected dates.';
                    } else {
                        $interval = $pickup->diff($return);
                        $days = $interval->days;
                        if ($interval->h > 0 || $interval->i > 0) $days += 1;
                        $daily_rate = floatval($vehicle_details['daily_rate']);
                        $base_amount = $daily_rate * $days;
                        // Apply discount if any
                        $discount_amount = $base_amount * ($discount_percent / 100);
                        $total_amount = $base_amount - $discount_amount;
                        
                        // ========== DECLINE IF TOTAL EXCEEDS 480,000 KES ==========
                        if ($total_amount > 480000) {
                            $error = 'Booking declined: Total amount after discount exceeds Ksh 480,000. Please contact us for alternative arrangements or reduce the rental duration.';
                        } else {
                            $booking_data = [
                                'user_id' => $_SESSION['user_id'],
                                'vehicle_id' => $vehicle_id,
                                'pickup_date' => $pickup_datetime,
                                'return_date' => $return_datetime,
                                'pickup_location' => $pickup_location,
                                'return_location' => $return_location,
                                'pickup_county' => $pickup_county,
                                'return_county' => $return_county,
                                'daily_rate' => $daily_rate,
                                'number_of_days' => $days,
                                'base_amount' => $base_amount,
                                'discount_amount' => $discount_amount,
                                'total_amount' => $total_amount,
                                'notes' => $notes
                            ];
                            $booking_id = $booking->createBooking($booking_data);
                            if ($booking_id) {
                                $_SESSION['last_booking'] = [
                                    'booking_id' => $booking_id,
                                    'vehicle_name' => $vehicle_details['make'] . ' ' . $vehicle_details['model'],
                                    'total_amount' => $total_amount,
                                    'pickup_date' => $pickup_datetime,
                                    'return_date' => $return_datetime
                                ];
                                set_flash('success', 'Booking created successfully! Please complete payment.');
                                redirect('/pages/confirm_booking.php?booking_id=' . $booking_id);
                            } else {
                                $error = 'Failed to create booking. Please try again.';
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $error = 'Invalid date format. Please try again.';
                error_log("Date error: " . $e->getMessage());
            }
        }
    }
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Now - <?php echo htmlspecialchars($vehicle_details['make'] . ' ' . $vehicle_details['model']); ?> | <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ===== BUBBLES + GLASSMORPHISM THEME ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0d0e0d 0%, #07f743 100%);
            min-height: 100vh;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated bubbles */
        .bubbles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .bubble {
            position: absolute;
            bottom: -100px;
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(3px);
            border-radius: 50%;
            animation: rise 20s infinite ease-in;
            box-shadow: 0 0 15px rgba(255, 255, 255, 0.5);
        }

        @keyframes rise {
            0% { transform: translateY(0) scale(0.8); opacity: 0.6; }
            50% { transform: translateY(-50vh) scale(1.2); opacity: 0.3; }
            100% { transform: translateY(-100vh) scale(0.5); opacity: 0; }
        }

        /* Glassmorphism base */
        .glass-card {
            background: rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(12px);
            border-radius: 28px;
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.2);
        }

        .booking-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        /* Header */
        .booking-header {
            background: rgba(46, 125, 50, 0.6);
            backdrop-filter: blur(12px);
            border-radius: 30px;
            padding: 20px 30px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.5);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .logo h1 {
            color: #fff;
            font-size: 1.9rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        .logo p {
            color: #f9f9f9;
            font-size: 0.95rem;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 50px;
        }
        .user-name {
            color: #fff;
            font-weight: 600;
        }
        .user-avatar {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #66bb6a, #2e7d32);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: bold;
            font-size: 1.2rem;
        }

        /* Two column layout */
        .booking-content {
            display: grid;
            grid-template-columns: 1fr 1.2fr;
            gap: 30px;
        }

        /* Vehicle summary card */
        .vehicle-summary, .booking-form {
            background: rgba(255, 255, 255, 0.35);
            backdrop-filter: blur(15px);
            border-radius: 28px;
            overflow: hidden;
            transition: transform 0.2s ease;
        }
        .vehicle-summary:hover, .booking-form:hover {
            transform: translateY(-4px);
        }
        .vehicle-image {
            height: 260px;
            background: rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .vehicle-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            padding: 6px 14px;
            border-radius: 40px;
            font-weight: 600;
            color: #c8e6c9;
            font-size: 0.9rem;
        }
        .vehicle-info {
            padding: 25px;
        }
        .vehicle-title {
            font-size: 1.9rem;
            font-weight: 700;
            color: #1b5e20;
            margin-bottom: 8px;
        }
        .vehicle-reg {
            color: #2c6e2f;
            font-size: 0.95rem;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(46,125,50,0.3);
        }
        .vehicle-specs {
            display: grid;
            grid-template-columns: repeat(2,1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .spec-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,240,0.5);
            padding: 6px 12px;
            border-radius: 40px;
            font-size: 0.9rem;
        }
        .spec-item i {
            width: 22px;
            color: #2e7d32;
        }
        .price-section {
            background: rgba(46, 125, 50, 0.2);
            padding: 18px;
            border-radius: 20px;
            margin: 15px 0;
        }
        .daily-rate {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .rate-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1b5e20;
        }
        .rate-value small {
            font-size: 0.9rem;
        }
        .discount-badge {
            background: #ff9800;
            color: #1b5e20;
            font-weight: bold;
            padding: 5px 12px;
            border-radius: 40px;
            font-size: 0.85rem;
            display: inline-block;
            margin-top: 10px;
        }
        /* NEW: Dedicated discount info space */
        .discount-info {
            background: rgba(255, 152, 0, 0.2);
            backdrop-filter: blur(8px);
            border-radius: 20px;
            padding: 15px;
            margin: 15px 0;
            border-left: 4px solid #ff9800;
            text-align: center;
        }
        .discount-info i {
            font-size: 1.3rem;
            margin-right: 8px;
            color: #ff9800;
        }
        .discount-info .save-amount {
            font-weight: bold;
            font-size: 1.1rem;
            color: #1b5e20;
        }
        .feature-tag {
            display: inline-block;
            background: rgba(70, 120, 70, 0.3);
            backdrop-filter: blur(2px);
            padding: 4px 12px;
            border-radius: 30px;
            margin: 4px;
            font-size: 0.8rem;
        }

        /* Booking form */
        .booking-form {
            padding: 25px 30px;
        }
        .form-header h2 {
            color: #1b5e20;
            margin-bottom: 8px;
            font-size: 1.7rem;
        }
        .form-header p {
            color: #2c3a2b;
            margin-bottom: 20px;
        }
        .form-section {
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(46,125,50,0.2);
        }
        .form-section:last-child {
            border-bottom: none;
        }
        .form-section h3 {
            color: #1b5e20;
            font-size: 1.25rem;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 15px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #1f3b1a;
        }
        .required::after {
            content: "*";
            color: #dc3545;
            margin-left: 4px;
        }
        .form-control {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(30, 70, 30, 0.3);
            border-radius: 16px;
            font-size: 1rem;
            transition: 0.2s;
        }
        .form-control:focus {
            background: rgba(255, 255, 255, 0.98);
            border-color: #2e7d32;
            outline: none;
            box-shadow: 0 0 0 3px rgba(46,125,50,0.2);
        }
        .form-control.location-invalid {
            border-color: #dc3545;
            background-color: rgba(220, 53, 69, 0.1);
        }
        .location-error {
            color: #dc3545;
            font-size: 0.75rem;
            margin-top: 5px;
            display: block;
        }
        .help-text {
            font-size: 0.75rem;
            color: #3c5a38;
            margin-top: 5px;
        }
        .user-details {
            background: rgba(200, 230, 200, 0.5);
            backdrop-filter: blur(4px);
            border-radius: 20px;
            padding: 15px;
        }
        .user-detail-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .user-detail-item:last-child {
            border-bottom: none;
        }
        .terms-section {
            background: rgba(180, 210, 170, 0.5);
            backdrop-filter: blur(4px);
            padding: 15px 20px;
            border-radius: 20px;
            margin: 20px 0;
        }
        .terms-checkbox {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .terms-checkbox input {
            margin-top: 3px;
            width: 18px;
            height: 18px;
        }
        /* Button group - centered confirm button */
        .button-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-top: 20px;
        }
        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            font-size: 1rem;
        }
        .btn-outline {
            background: rgba(255,255,240,0.8);
            border: 1px solid #2e7d32;
            color: #1b5e20;
        }
        .btn-outline:hover {
            background: #fff;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .btn-primary:hover {
            transform: scale(1.02);
            background: linear-gradient(135deg, #1b5e20, #0a4a0e);
        }
        .price-breakdown {
            background: rgba(255, 250, 210, 0.8);
            padding: 15px;
            border-radius: 20px;
            margin-top: 15px;
        }
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        .breakdown-total {
            font-weight: 800;
            font-size: 1.2rem;
            border-top: 2px dashed #aaa;
            margin-top: 5px;
            padding-top: 12px;
        }
        .alert {
            padding: 12px 18px;
            border-radius: 20px;
            margin-bottom: 20px;
            backdrop-filter: blur(8px);
            background: rgba(255,255,255,0.8);
        }
        .alert-danger {
            color: #b33;
            border-left: 4px solid #b33;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #2e7d32;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 8px;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        @media (max-width: 968px) {
            .booking-content { grid-template-columns: 1fr; }
            .vehicle-summary { position: sticky; top: 10px; }
        }
        @media (max-width: 768px) {
            .booking-header { flex-direction: column; text-align: center; }
            .user-info { justify-content: center; }
            .form-row { grid-template-columns: 1fr; }
            .button-group { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<div class="bubbles" id="bubblesContainer"></div>

<div class="booking-wrapper">
    <div class="booking-header">
        <div class="logo">
            <h1><?php echo htmlspecialchars($settings['site_name']); ?></h1>
            <p>Car Rental Kenya</p>
        </div>
        <div class="user-info">
            <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
            <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
        </div>
    </div>

    <div class="booking-content">
        <!-- Vehicle Summary -->
        <div class="vehicle-summary glass-card">
            <div class="vehicle-image">
                <?php echo get_vehicle_image_html($vehicle_details, APP_URL); ?>
                <div class="vehicle-badge"><i class="fas fa-check-circle"></i> Available Now</div>
            </div>
            <div class="vehicle-info">
                <h2 class="vehicle-title"><?php echo htmlspecialchars($vehicle_details['make'] . ' ' . $vehicle_details['model']); ?></h2>
                <p class="vehicle-reg"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($vehicle_details['registration_number']); ?></p>
                <div class="vehicle-specs">
                    <div class="spec-item"><i class="fas fa-calendar"></i><span><?php echo htmlspecialchars($vehicle_details['year']); ?></span></div>
                    <div class="spec-item"><i class="fas fa-palette"></i><span><?php echo htmlspecialchars(ucfirst($vehicle_details['color'])); ?></span></div>
                    <div class="spec-item"><i class="fas fa-gas-pump"></i><span><?php echo htmlspecialchars(ucfirst($vehicle_details['fuel_type'])); ?></span></div>
                    <div class="spec-item"><i class="fas fa-cog"></i><span><?php echo htmlspecialchars(ucfirst($vehicle_details['transmission'])); ?></span></div>
                    <div class="spec-item"><i class="fas fa-chair"></i><span><?php echo (int)$vehicle_details['seating_capacity']; ?> Seats</span></div>
                    <div class="spec-item"><i class="fas fa-tachometer-alt"></i><span><?php echo number_format((int)$vehicle_details['mileage']); ?> km</span></div>
                </div>
                <?php if (!empty($vehicle_details['features'])): ?>
                <div class="features-list" style="margin-bottom:15px;">
                    <?php $features = explode(',', $vehicle_details['features']); foreach ($features as $feature): ?>
                    <span class="feature-tag"><i class="fas fa-check"></i> <?php echo htmlspecialchars(trim($feature)); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="price-section">
                    <div class="daily-rate">
                        <span class="rate-label">Daily Rate</span>
                        <span class="rate-value"><?php echo format_currency($vehicle_details['daily_rate']); ?> <small>/day</small></span>
                    </div>
                    <?php if ($discount_percent > 0): ?>
                    <div class="discount-badge">
                        <i class="fas fa-tag"></i> <?php echo $discount_percent; ?>% OFF!
                    </div>
                    <?php endif; ?>
                </div>

                <!-- NEW DEDICATED DISPLAY SPACE FOR DISCOUNT OFFERED -->
                <?php if ($discount_percent > 0): ?>
                <div class="discount-info">
                    <i class="fas fa-gift"></i> 
                    <strong>Special Discount!</strong> You get <strong><?php echo $discount_percent; ?>% OFF</strong> on this vehicle.
                    <div class="save-amount" id="saveAmountPreview">
                        <!-- Will be filled by JavaScript when dates are selected -->
                        Estimated savings: <span id="estimatedSave"><?php echo format_currency(0); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <div id="priceCalculator" style="display: none;">
                    <div class="price-breakdown">
                        <h4 style="margin-bottom:12px;">Price Breakdown</h4>
                        <div class="breakdown-item"><span>Daily Rate × <span id="daysCount">0</span> days</span><span id="basePrice"><?php echo format_currency(0); ?></span></div>
                        <?php if ($discount_percent > 0): ?>
                        <div class="breakdown-item"><span>Discount (<?php echo $discount_percent; ?>%)</span><span id="discountAmount"><?php echo format_currency(0); ?></span></div>
                        <?php endif; ?>
                        <div class="breakdown-item breakdown-total"><span>Total Amount</span><span id="totalPrice"><?php echo format_currency(0); ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Booking Form -->
        <div class="booking-form glass-card">
            <div class="form-header">
                <h2>Complete Your Booking</h2>
                <p>Please fill in the details below to reserve this vehicle</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="bookingForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <!-- Rental Dates -->
                <div class="form-section">
                    <h3><i class="fas fa-calendar-alt"></i> Rental Period</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required">Pickup Date</label>
                            <input type="date" name="pickup_date" id="pickup_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>" onchange="calculateTotal()">
                        </div>
                        <div class="form-group">
                            <label class="required">Pickup Time</label>
                            <input type="time" name="pickup_time" id="pickup_time" class="form-control" required value="10:00" onchange="calculateTotal()">
                            <div class="help-text">24hr format</div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required">Return Date</label>
                            <input type="date" name="return_date" id="return_date" class="form-control" required min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" onchange="calculateTotal()">
                        </div>
                        <div class="form-group">
                            <label class="required">Return Time</label>
                            <input type="time" name="return_time" id="return_time" class="form-control" required value="10:00" onchange="calculateTotal()">
                            <div class="help-text">24hr format</div>
                        </div>
                    </div>
                </div>

                <!-- Locations -->
                <div class="form-section">
                    <h3><i class="fas fa-map-marker-alt"></i> Pickup & Return Locations</h3>
                    <div class="form-group">
                        <label class="required">Pickup County</label>
                        <select name="pickup_county" id="pickup_county" class="form-control" required onchange="updateSameLocation()">
                            <option value="">Select pickup county</option>
                            <?php foreach ($KENYA_COUNTIES as $key => $county): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($county); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Pickup Location (Optional, but must be a valid place name)</label>
                        <input type="text" name="pickup_location" id="pickup_location" class="form-control" placeholder="e.g., Westlands, Roysambu, Safari Park" list="locationSuggestions" autocomplete="off">
                        <datalist id="locationSuggestions">
                            <?php 
                            $unique_locations = array_unique($ALLOWED_LOCATIONS);
                            foreach ($unique_locations as $loc): 
                                $display = ucwords(str_replace('_', ' ', $loc));
                            ?>
                                <option value="<?php echo htmlspecialchars($display); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div id="pickupLocationError" class="location-error"></div>
                        <div class="help-text">Start typing – suggestions will appear. Allowed: Roysambu, Safari Park, etc.</div>
                    </div>
                    <div class="form-group">
                        <label class="required">Return County</label>
                        <select name="return_county" id="return_county" class="form-control" required>
                            <option value="">Select return county</option>
                            <?php foreach ($KENYA_COUNTIES as $key => $county): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($county); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Return Location (Optional, but must be a valid place name)</label>
                        <input type="text" name="return_location" id="return_location" class="form-control" placeholder="e.g., CBD, Roysambu" list="locationSuggestions" autocomplete="off">
                        <div id="returnLocationError" class="location-error"></div>
                    </div>
                    <div style="margin-top:12px;">
                        <input type="checkbox" id="same_location" onchange="sameLocation()">
                        <label for="same_location" style="margin-left:8px; cursor:pointer;">Return to same location as pickup</label>
                    </div>
                </div>

                <!-- Renter Info -->
                <div class="form-section">
                    <h3><i class="fas fa-user"></i> Renter Information</h3>
                    <div class="user-details">
                        <div class="user-detail-item"><i class="fas fa-user"></i> <?php echo htmlspecialchars($user_details['first_name'] . ' ' . $user_details['last_name']); ?></div>
                        <div class="user-detail-item"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user_details['email']); ?></div>
                        <div class="user-detail-item"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($user_details['phone']); ?></div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="form-section">
                    <h3><i class="fas fa-pen"></i> Additional Information</h3>
                    <div class="form-group">
                        <label>Special Requests or Notes</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Any special requirements..."></textarea>
                    </div>
                </div>

                <!-- Terms -->
                <div class="terms-section">
                    <div class="terms-checkbox">
                        <input type="checkbox" id="terms" required>
                        <label for="terms">I agree to the Terms and Conditions and Privacy Policy. I confirm that I have a valid driver's license and meet the age requirements (18+ years).</label>
                    </div>
                </div>

                <!-- Buttons - Centered Confirm Booking -->
                <div class="button-group">
                    <a href="<?php echo APP_URL; ?>/pages/browse.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
                    <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-check-circle"></i> Confirm Booking</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Allowed locations (case-insensitive list generated from PHP)
    const allowedLocations = <?php echo json_encode(array_map('strtolower', $ALLOWED_LOCATIONS)); ?>;
    const discountPercent = <?php echo $discount_percent; ?>;

    // Helper: check if a location is valid
    function isValidLocation(location) {
        if (!location.trim()) return true; // empty is allowed (optional)
        return allowedLocations.includes(location.trim().toLowerCase());
    }

    // Real‑time validation for pickup location
    const pickupInput = document.getElementById('pickup_location');
    const pickupError = document.getElementById('pickupLocationError');
    const returnInput = document.getElementById('return_location');
    const returnError = document.getElementById('returnLocationError');

    function validatePickupLocation() {
        const val = pickupInput.value;
        if (!isValidLocation(val)) {
            pickupInput.classList.add('location-invalid');
            pickupError.textContent = 'Please enter a valid Kenyan place name (e.g., Nairobi, Westlands, Roysambu, Safari Park).';
            return false;
        } else {
            pickupInput.classList.remove('location-invalid');
            pickupError.textContent = '';
            return true;
        }
    }

    function validateReturnLocation() {
        const val = returnInput.value;
        if (!isValidLocation(val)) {
            returnInput.classList.add('location-invalid');
            returnError.textContent = 'Please enter a valid Kenyan place name.';
            return false;
        } else {
            returnInput.classList.remove('location-invalid');
            returnError.textContent = '';
            return true;
        }
    }

    pickupInput.addEventListener('input', validatePickupLocation);
    returnInput.addEventListener('input', validateReturnLocation);
    pickupInput.addEventListener('blur', validatePickupLocation);
    returnInput.addEventListener('blur', validateReturnLocation);

    // Bubble animation
    function createBubbles() {
        const container = document.getElementById('bubblesContainer');
        const bubbleCount = 50;
        for(let i = 0; i < bubbleCount; i++) {
            const bubble = document.createElement('div');
            bubble.classList.add('bubble');
            const size = Math.random() * 80 + 20;
            bubble.style.width = size + 'px';
            bubble.style.height = size + 'px';
            bubble.style.left = Math.random() * 100 + '%';
            bubble.style.animationDuration = Math.random() * 15 + 8 + 's';
            bubble.style.animationDelay = Math.random() * 10 + 's';
            bubble.style.background = `rgba(255, 255, 245, ${Math.random() * 0.4 + 0.1})`;
            container.appendChild(bubble);
        }
    }
    window.addEventListener('load', createBubbles);

    function calculateTotal() {
        const pickupDate = document.getElementById('pickup_date').value;
        const pickupTime = document.getElementById('pickup_time').value;
        const returnDate = document.getElementById('return_date').value;
        const returnTime = document.getElementById('return_time').value;
        const dailyRate = <?php echo (float)$vehicle_details['daily_rate']; ?>;
        if (pickupDate && returnDate && pickupTime && returnTime) {
            const pickup = new Date(pickupDate + 'T' + pickupTime);
            const ret = new Date(returnDate + 'T' + returnTime);
            if (ret > pickup) {
                const diffDays = Math.ceil((ret - pickup) / (1000 * 60 * 60 * 24));
                const baseAmount = dailyRate * diffDays;
                const discountAmount = baseAmount * (discountPercent / 100);
                const totalAmount = baseAmount - discountAmount;
                document.getElementById('daysCount').innerText = diffDays;
                document.getElementById('basePrice').innerText = formatCurrency(baseAmount);
                if (discountPercent > 0) {
                    document.getElementById('discountAmount').innerText = formatCurrency(discountAmount);
                    // Update the estimated savings in the dedicated discount info box
                    const saveSpan = document.getElementById('estimatedSave');
                    if (saveSpan) saveSpan.innerText = formatCurrency(discountAmount);
                }
                document.getElementById('totalPrice').innerText = formatCurrency(totalAmount);
                document.getElementById('priceCalculator').style.display = 'block';
            } else {
                document.getElementById('priceCalculator').style.display = 'none';
            }
        }
    }
    function formatCurrency(amount) {
        return '<?php echo $settings['currency']; ?> ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function sameLocation() {
        const checked = document.getElementById('same_location').checked;
        const pickupCounty = document.getElementById('pickup_county').value;
        const pickupLocation = document.getElementById('pickup_location').value;
        const returnCounty = document.getElementById('return_county');
        const returnLocation = document.getElementById('return_location');
        if (checked) {
            returnCounty.value = pickupCounty;
            returnLocation.value = pickupLocation;
            returnCounty.disabled = true;
            returnLocation.disabled = true;
            validateReturnLocation();
        } else {
            returnCounty.disabled = false;
            returnLocation.disabled = false;
            returnCounty.value = '';
            returnLocation.value = '';
            returnError.textContent = '';
            returnInput.classList.remove('location-invalid');
        }
    }
    function updateSameLocation() {
        if (document.getElementById('same_location').checked) {
            document.getElementById('return_county').value = document.getElementById('pickup_county').value;
        }
    }
    document.getElementById('bookingForm').addEventListener('submit', function(e) {
        const isPickupValid = validatePickupLocation();
        const isReturnValid = validateReturnLocation();
        if (!isPickupValid || !isReturnValid) {
            e.preventDefault();
            alert('Please correct the location fields: they must be valid Kenyan place names.');
            return;
        }
        if (!document.getElementById('terms').checked) {
            e.preventDefault();
            alert('Please agree to the Terms and Conditions');
            return;
        }
        const pickupDate = document.getElementById('pickup_date').value;
        const pickupTime = document.getElementById('pickup_time').value;
        const returnDate = document.getElementById('return_date').value;
        const returnTime = document.getElementById('return_time').value;
        const pickupCounty = document.getElementById('pickup_county').value;
        const returnCounty = document.getElementById('return_county').value;
        if (!pickupDate || !pickupTime || !returnDate || !returnTime || !pickupCounty || !returnCounty) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return;
        }
        const pickup = new Date(pickupDate + 'T' + pickupTime);
        const ret = new Date(returnDate + 'T' + returnTime);
        if (pickup < new Date()) {
            e.preventDefault();
            alert('Pickup date cannot be in the past');
            return;
        }
        if (ret <= pickup) {
            e.preventDefault();
            alert('Return date must be after pickup date');
            return;
        }
        const btn = document.getElementById('submitBtn');
        btn.innerHTML = '<span class="spinner"></span> Processing...';
        btn.disabled = true;
    });
    document.getElementById('pickup_date').addEventListener('change', function() {
        const nextDay = new Date(this.value);
        nextDay.setDate(nextDay.getDate() + 1);
        document.getElementById('return_date').min = nextDay.toISOString().split('T')[0];
        calculateTotal();
    });
    document.getElementById('pickup_time').addEventListener('change', calculateTotal);
    document.getElementById('return_time').addEventListener('change', calculateTotal);
    document.getElementById('return_date').addEventListener('change', calculateTotal);
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('pickup_date').min = new Date().toISOString().split('T')[0];
        calculateTotal();
    });
</script>
</body>
</html>
```

