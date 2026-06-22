<?php
/**
 * ============================================================
 * Admin Login & Dashboard – MULTIPLE IMAGES + SLIDESHOW
 * RESTRICTION: Admin cannot book vehicles.
 * ADDED: Discount (%) field for each vehicle (reflects on customer side)
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
ob_start();

// ------------------------------------------------------------------
// Configuration
// ------------------------------------------------------------------
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

// ================================================================
// ENSURE ALL REQUIRED COLUMNS EXIST (RUN ONCE)
// ================================================================
$missing_columns_sql = [
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `weekly_rate` DECIMAL(10,2) DEFAULT 0.00",
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `monthly_rate` DECIMAL(10,2) DEFAULT 0.00",
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `latitude` DECIMAL(10,8) DEFAULT NULL",
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(11,8) DEFAULT NULL",
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `condition` VARCHAR(50) DEFAULT 'good'",
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `features` TEXT DEFAULT NULL",
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `description` TEXT DEFAULT NULL",
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `image_url` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `is_featured` TINYINT(1) DEFAULT 0",
    "ALTER TABLE `vehicles` MODIFY COLUMN `status` ENUM('available','rented','maintenance','damaged') DEFAULT 'available'",
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `images` JSON DEFAULT NULL",
    "ALTER TABLE `vehicles` ADD COLUMN IF NOT EXISTS `discount_percent` DECIMAL(5,2) DEFAULT 0.00"
];
foreach ($missing_columns_sql as $sql) {
    @$conn->query($sql);
}
// Remove any unique constraint on registration_number
$conn->query("ALTER TABLE `vehicles` DROP INDEX IF EXISTS `registration_number`");
$conn->query("ALTER TABLE `vehicles` DROP INDEX IF EXISTS `unique_registration`");

$check_index = $conn->query("SHOW INDEX FROM vehicles WHERE Column_name = 'registration_number' AND Non_unique = 0");
if ($check_index && $check_index->num_rows > 0) {
    $conn->query("ALTER TABLE vehicles DROP INDEX registration_number");
    $conn->query("ALTER TABLE vehicles DROP INDEX `idx_registration`");
    $conn->query("ALTER TABLE vehicles DROP INDEX `unique_registration`");
}
$col_info = $conn->query("SHOW COLUMNS FROM vehicles WHERE Field = 'registration_number'");
if ($col_info && $col_info->num_rows > 0) {
    $row = $col_info->fetch_assoc();
    if ($row['Key'] === 'UNI') {
        $conn->query("ALTER TABLE vehicles MODIFY registration_number VARCHAR(50) NOT NULL");
    }
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
function redirect($url) {
    header("Location: " . $url);
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

function is_admin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function sanitize($data) {
    global $conn;
    return $conn->real_escape_string(trim(htmlspecialchars($data)));
}

function format_currency($amount) {
    return 'Ksh ' . number_format($amount, 2);
}

function format_date_short($date) {
    return date('d M Y', strtotime($date));
}

// ========== SLIDESHOW FUNCTION ==========
function get_vehicle_slideshow($vehicle, $app_url) {
    $images = [];
    if (!empty($vehicle['images'])) {
        $decoded = json_decode($vehicle['images'], true);
        if (is_array($decoded)) $images = $decoded;
    }
    if (empty($images) && !empty($vehicle['image_url'])) {
        $images = [$vehicle['image_url']];
    }
    if (empty($images)) {
        return '<i class="fas fa-car" style="font-size:2rem; color:#9aa0a6;"></i>';
    }
    $indicators = '';
    $slides = '';
    foreach ($images as $idx => $img) {
        $img_path = trim($img, '/');
        $src = $app_url . '/' . $img_path;
        $active = $idx === 0 ? 'active' : '';
        $indicators .= "<li data-target='#carousel-{$vehicle['id']}' data-slide-to='{$idx}' class='{$active}'></li>";
        $slides .= "<div class='carousel-item {$active}'><img src='{$src}' class='d-block w-100' style='height:140px; object-fit:cover;' alt='Slide {$idx}'></div>";
    }
    return "
    <div id='carousel-{$vehicle['id']}' class='carousel slide' data-ride='carousel' data-interval='3000'>
        <ol class='carousel-indicators' style='bottom:0;'>{$indicators}</ol>
        <div class='carousel-inner'>{$slides}</div>
        <a class='carousel-control-prev' href='#carousel-{$vehicle['id']}' role='button' data-slide='prev'>
            <span class='carousel-control-prev-icon' aria-hidden='true'></span>
            <span class='sr-only'>Previous</span>
        </a>
        <a class='carousel-control-next' href='#carousel-{$vehicle['id']}' role='button' data-slide='next'>
            <span class='carousel-control-next-icon' aria-hidden='true'></span>
            <span class='sr-only'>Next</span>
        </a>
    </div>";
}

// ================================================================
// Vehicle Class (UPDATED to include discount_percent)
// ================================================================
class Vehicle {
    private $conn;
    private $lastError = '';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function getAllVehicles() {
        $sql = "SELECT * FROM vehicles ORDER BY id DESC";
        $result = $this->conn->query($sql);
        $vehicles = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $vehicles[] = $row;
            }
        }
        return $vehicles;
    }

    public function getVehicleById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM vehicles WHERE id = ?");
        if (!$stmt) {
            $this->lastError = $this->conn->error;
            return null;
        }
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            $this->lastError = $stmt->error;
            return null;
        }
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    public function addVehicle($data) {
        $this->lastError = '';
        $images_json = json_encode($data['images'] ?? []);
        $sql = "INSERT INTO vehicles (
                    registration_number, make, model, year, color, vehicle_type,
                    fuel_type, transmission, seating_capacity, mileage, daily_rate,
                    weekly_rate, monthly_rate, location, county, latitude, longitude,
                    `condition`, features, description, image_url, images, discount_percent, status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', NOW()
                )";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->lastError = $this->conn->error;
            return false;
        }
        $stmt->bind_param(
            "sssissssiidddssddsssssd",
            $data['registration_number'], $data['make'], $data['model'], $data['year'],
            $data['color'], $data['vehicle_type'], $data['fuel_type'], $data['transmission'],
            $data['seating_capacity'], $data['mileage'], $data['daily_rate'], $data['weekly_rate'],
            $data['monthly_rate'], $data['location'], $data['county'], $data['latitude'],
            $data['longitude'], $data['condition'], $data['features'], $data['description'],
            $data['image_url'], $images_json, $data['discount_percent']
        );
        if ($stmt->execute()) {
            return true;
        } else {
            $this->lastError = $stmt->error;
            return false;
        }
    }

    public function updateVehicle($id, $data) {
        $this->lastError = '';
        $images_json = json_encode($data['images'] ?? []);
        $sql = "UPDATE vehicles SET
                    registration_number = ?, make = ?, model = ?, year = ?, color = ?,
                    vehicle_type = ?, fuel_type = ?, transmission = ?, seating_capacity = ?,
                    mileage = ?, daily_rate = ?, weekly_rate = ?, monthly_rate = ?,
                    location = ?, county = ?, latitude = ?, longitude = ?,
                    `condition` = ?, features = ?, description = ?, image_url = ?, images = ?,
                    discount_percent = ?
                WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->lastError = $this->conn->error;
            return false;
        }
        $stmt->bind_param(
            "sssissssiidddssddsssssdi",
            $data['registration_number'], $data['make'], $data['model'], $data['year'], $data['color'],
            $data['vehicle_type'], $data['fuel_type'], $data['transmission'], $data['seating_capacity'],
            $data['mileage'], $data['daily_rate'], $data['weekly_rate'], $data['monthly_rate'],
            $data['location'], $data['county'], $data['latitude'], $data['longitude'],
            $data['condition'], $data['features'], $data['description'], $data['image_url'],
            $images_json, $data['discount_percent'], $id
        );
        if ($stmt->execute()) {
            return true;
        } else {
            $this->lastError = $stmt->error;
            return false;
        }
    }

    public function deleteVehicle($id) {
        $this->lastError = '';
        $stmt = $this->conn->prepare("DELETE FROM vehicles WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            return true;
        } else {
            $this->lastError = $stmt->error;
            return false;
        }
    }

    public function getVehicleStats() {
        $stats = [
            'total' => 0,
            'by_status' => ['available' => 0, 'rented' => 0, 'maintenance' => 0, 'damaged' => 0],
            'avg_daily_rate' => 0
        ];
        $total = $this->conn->query("SELECT COUNT(*) as count FROM vehicles");
        if ($total && $total->num_rows > 0) {
            $stats['total'] = $total->fetch_assoc()['count'];
        }
        $statusCounts = $this->conn->query("SELECT status, COUNT(*) as count FROM vehicles GROUP BY status");
        if ($statusCounts && $statusCounts->num_rows > 0) {
            while ($row = $statusCounts->fetch_assoc()) {
                if (isset($stats['by_status'][$row['status']])) {
                    $stats['by_status'][$row['status']] = $row['count'];
                }
            }
        }
        $avg = $this->conn->query("SELECT AVG(daily_rate) as avg_rate FROM vehicles");
        if ($avg && $avg->num_rows > 0) {
            $stats['avg_daily_rate'] = $avg->fetch_assoc()['avg_rate'] ?? 0;
        }
        return $stats;
    }

    public function markAsReturned($vehicle_id) {
        $stmt = $this->conn->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
        $stmt->bind_param("i", $vehicle_id);
        return $stmt->execute();
    }
}

$vehicle = new Vehicle($conn);

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==================================================================
// EARLY AJAX HANDLING (UPDATED for multiple images + discount in add/edit)
// ==================================================================
if (isset($_POST['action']) || (isset($_FILES['vehicle_image']) && isset($_POST['upload_image_only'])) || (isset($_FILES['vehicle_images']) && isset($_POST['upload_multiple_images']))) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        echo json_encode(['success' => false, 'message' => "PHP Error: $errstr in $errfile line $errline"]);
        exit;
    });

    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $error['message']]);
        }
    });

    ini_set('display_errors', 0);

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    // ========== Upload multiple images from Image Manager ==========
    if (isset($_FILES['vehicle_images']) && isset($_POST['upload_multiple_images'])) {
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        if ($vehicle_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid vehicle ID']);
            exit;
        }
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/car-rental-kenya/uploads/vehicle_images/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $uploaded_urls = [];
        foreach ($_FILES['vehicle_images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['vehicle_images']['error'][$key] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($_FILES['vehicle_images']['name'][$key], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif'])) continue;
            $new_filename = uniqid('vehicle_') . '.' . $ext;
            $upload_path = $upload_dir . $new_filename;
            if (move_uploaded_file($tmp_name, $upload_path)) {
                $uploaded_urls[] = 'uploads/vehicle_images/' . $new_filename;
            }
        }
        if (empty($uploaded_urls)) {
            echo json_encode(['success' => false, 'message' => 'No valid images uploaded']);
            exit;
        }
        $current = $vehicle->getVehicleById($vehicle_id);
        $existing = json_decode($current['images'] ?? '[]', true);
        if (!is_array($existing)) $existing = [];
        $all_images = array_merge($existing, $uploaded_urls);
        $images_json = json_encode($all_images);
        $first_image = $all_images[0] ?? '';
        $stmt = $conn->prepare("UPDATE vehicles SET images = ?, image_url = ? WHERE id = ?");
        $stmt->bind_param("ssi", $images_json, $first_image, $vehicle_id);
        if ($stmt->execute()) {
            $stats = $vehicle->getVehicleStats();
            echo json_encode(['success' => true, 'message' => count($uploaded_urls) . ' image(s) added', 'images' => $all_images, 'stats' => $stats]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $stmt->error]);
        }
        exit;
    }

    // Handle standard AJAX actions
    $action = $_POST['action'] ?? '';
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);

    if ($action === 'delete' && $vehicle_id > 0) {
        $result = $vehicle->deleteVehicle($vehicle_id);
        $message = $result ? 'Vehicle deleted' : 'Delete failed: ' . $vehicle->getLastError();
        $stats = $vehicle->getVehicleStats();
        echo json_encode(['success' => $result, 'message' => $message, 'stats' => $stats]);
        exit;
    }

    if ($action === 'get' && $vehicle_id > 0) {
        $data = $vehicle->getVehicleById($vehicle_id);
        if ($data === null) {
            echo json_encode(['error' => true, 'message' => 'Vehicle not found in database: ' . $vehicle->getLastError()]);
        } else {
            echo json_encode($data);
        }
        exit;
    }

    if ($action === 'update' && $vehicle_id > 0) {
        // Get images JSON from hidden field
        $images_json = $_POST['images_json'] ?? '[]';
        $images = json_decode($images_json, true);
        if (!is_array($images)) $images = [];
        
        // ========== PROCESS MULTIPLE UPLOADED IMAGES ==========
        if (isset($_FILES['vehicle_images']) && !empty($_FILES['vehicle_images']['name'][0])) {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/car-rental-kenya/uploads/vehicle_images/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $new_uploads = [];
            foreach ($_FILES['vehicle_images']['tmp_name'] as $key => $tmp) {
                if ($_FILES['vehicle_images']['error'][$key] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($_FILES['vehicle_images']['name'][$key], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif'])) continue;
                $new_name = uniqid('vehicle_') . '.' . $ext;
                if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                    $new_uploads[] = 'uploads/vehicle_images/' . $new_name;
                }
            }
            if (!empty($new_uploads)) {
                $images = array_merge($images, $new_uploads);
            }
        }
        
        $data = [
            'registration_number' => sanitize($_POST['registration_number'] ?? ''),
            'make'                => sanitize($_POST['make'] ?? ''),
            'model'               => sanitize($_POST['model'] ?? ''),
            'year'                => (int)($_POST['year'] ?? 0),
            'color'               => sanitize($_POST['color'] ?? ''),
            'vehicle_type'        => sanitize($_POST['vehicle_type'] ?? ''),
            'fuel_type'           => sanitize($_POST['fuel_type'] ?? 'petrol'),
            'transmission'        => sanitize($_POST['transmission'] ?? 'automatic'),
            'seating_capacity'    => (int)($_POST['seating_capacity'] ?? 5),
            'mileage'             => (int)($_POST['mileage'] ?? 0),
            'daily_rate'          => (float)($_POST['daily_rate'] ?? 0),
            'weekly_rate'         => (float)($_POST['weekly_rate'] ?? 0),
            'monthly_rate'        => (float)($_POST['monthly_rate'] ?? 0),
            'location'            => sanitize($_POST['location'] ?? ''),
            'county'              => sanitize($_POST['county'] ?? ''),
            'latitude'            => (float)($_POST['latitude'] ?? 0),
            'longitude'           => (float)($_POST['longitude'] ?? 0),
            'condition'           => sanitize($_POST['condition'] ?? 'good'),
            'features'            => sanitize($_POST['features'] ?? ''),
            'description'         => sanitize($_POST['description'] ?? ''),
            'image_url'           => $images[0] ?? '',
            'images'              => $images,
            'discount_percent'    => (float)($_POST['discount_percent'] ?? 0)
        ];

        if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
            $data['image_url'] = '';
            $data['images'] = [];
        }

        if ($vehicle->updateVehicle($vehicle_id, $data)) {
            $stats = $vehicle->getVehicleStats();
            echo json_encode(['success' => true, 'message' => 'Vehicle updated', 'stats' => $stats]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $vehicle->getLastError()]);
        }
        exit;
    }

    if ($action === 'add') {
        $images_json = $_POST['images_json'] ?? '[]';
        $images = json_decode($images_json, true);
        if (!is_array($images)) $images = [];
        
        // ========== PROCESS MULTIPLE UPLOADED IMAGES ==========
        if (isset($_FILES['vehicle_images']) && !empty($_FILES['vehicle_images']['name'][0])) {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/car-rental-kenya/uploads/vehicle_images/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $new_uploads = [];
            foreach ($_FILES['vehicle_images']['tmp_name'] as $key => $tmp) {
                if ($_FILES['vehicle_images']['error'][$key] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($_FILES['vehicle_images']['name'][$key], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif'])) continue;
                $new_name = uniqid('vehicle_') . '.' . $ext;
                if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                    $new_uploads[] = 'uploads/vehicle_images/' . $new_name;
                }
            }
            if (!empty($new_uploads)) {
                $images = array_merge($images, $new_uploads);
            }
        }
        
        $data = [
            'registration_number' => sanitize($_POST['registration_number'] ?? ''),
            'make'                => sanitize($_POST['make'] ?? ''),
            'model'               => sanitize($_POST['model'] ?? ''),
            'year'                => (int)($_POST['year'] ?? 0),
            'color'               => sanitize($_POST['color'] ?? ''),
            'vehicle_type'        => sanitize($_POST['vehicle_type'] ?? ''),
            'fuel_type'           => sanitize($_POST['fuel_type'] ?? 'petrol'),
            'transmission'        => sanitize($_POST['transmission'] ?? 'automatic'),
            'seating_capacity'    => (int)($_POST['seating_capacity'] ?? 5),
            'mileage'             => (int)($_POST['mileage'] ?? 0),
            'daily_rate'          => (float)($_POST['daily_rate'] ?? 0),
            'weekly_rate'         => (float)($_POST['weekly_rate'] ?? 0),
            'monthly_rate'        => (float)($_POST['monthly_rate'] ?? 0),
            'location'            => sanitize($_POST['location'] ?? ''),
            'county'              => sanitize($_POST['county'] ?? ''),
            'latitude'            => (float)($_POST['latitude'] ?? 0),
            'longitude'           => (float)($_POST['longitude'] ?? 0),
            'condition'           => sanitize($_POST['condition'] ?? 'good'),
            'features'            => sanitize($_POST['features'] ?? ''),
            'description'         => sanitize($_POST['description'] ?? ''),
            'image_url'           => $images[0] ?? '',
            'images'              => $images,
            'discount_percent'    => (float)($_POST['discount_percent'] ?? 0)
        ];

        if ($vehicle->addVehicle($data)) {
            $stats = $vehicle->getVehicleStats();
            echo json_encode(['success' => true, 'message' => 'Vehicle added successfully', 'stats' => $stats]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add vehicle: ' . $vehicle->getLastError()]);
        }
        exit;
    }

    // Handle sending reply to feedback (AJAX)
    if ($action === 'send_reply') {
        $feedback_id = (int)($_POST['feedback_id'] ?? 0);
        $reply = sanitize($_POST['reply'] ?? '');
        if ($feedback_id <= 0 || empty($reply)) {
            echo json_encode(['success' => false, 'message' => 'Invalid feedback ID or reply content.']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE feedback SET reply = ?, reply_date = NOW() WHERE id = ?");
        $stmt->bind_param("si", $reply, $feedback_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Reply sent successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
        }
        exit;
    }

    // ========== Update booking status to completed ==========
    if ($action === 'update_booking_status') {
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        $new_status = sanitize($_POST['status'] ?? '');
        if ($booking_id <= 0 || !in_array($new_status, ['confirmed', 'completed', 'cancelled'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid booking ID or status']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $booking_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => "Booking status updated to $new_status"]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
        }
        exit;
    }

    // ========== Return vehicle (admin action) ==========
    if ($action === 'return_vehicle') {
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        if ($booking_id <= 0 || $vehicle_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid booking or vehicle ID']);
            exit;
        }
        $conn->begin_transaction();
        try {
            $update_vehicle = $conn->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
            $update_vehicle->bind_param("i", $vehicle_id);
            if (!$update_vehicle->execute()) {
                throw new Exception("Failed to update vehicle: " . $update_vehicle->error);
            }
            $update_booking = $conn->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
            $update_booking->bind_param("i", $booking_id);
            if (!$update_booking->execute()) {
                throw new Exception("Failed to update booking: " . $update_booking->error);
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Vehicle returned successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ========== Get features ==========
    if ($action === 'get_features') {
        $result = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'features_list'");
        $features_json = ($result && $result->num_rows > 0) ? $result->fetch_assoc()['setting_value'] : '';
        $features = [];
        if (!empty($features_json)) {
            $features = json_decode($features_json, true);
            if (!is_array($features)) $features = [];
        }
        echo json_encode(['success' => true, 'features' => $features]);
        exit;
    }

    // ========== Save features ==========
    if ($action === 'save_features') {
        $features_json = $_POST['features'] ?? '';
        $features = json_decode($features_json, true);
        if (!is_array($features)) {
            echo json_encode(['success' => false, 'message' => 'Invalid features data']);
            exit;
        }
        foreach ($features as $f) {
            if (!isset($f['icon']) || !isset($f['title']) || !isset($f['description'])) {
                echo json_encode(['success' => false, 'message' => 'Each feature must have icon, title and description']);
                exit;
            }
        }
        $features_json_clean = json_encode($features);
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('features_list', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("ss", $features_json_clean, $features_json_clean);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Features updated']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ==================================================================
// END OF AJAX HANDLING
// ==================================================================

// ------------------------------------------------------------------
// Handle login POST
// ------------------------------------------------------------------
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $hardcoded_password = '120179';
    if (empty($email) || empty($password)) {
        $login_error = 'Please enter both email and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, first_name, last_name, password_hash, user_role FROM users WHERE email = ? AND user_role = 'admin' AND status = 'active'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        if ($user && $password === $hardcoded_password) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_role'] = 'admin';
            $_SESSION['admin_view_only'] = true;
            $update = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update->bind_param("i", $user['id']);
            $update->execute();
            redirect('admin_login.php');
        } else {
            $login_error = 'Invalid admin credentials.';
        }
    }
}

// If not logged in as admin, show login form (unchanged but minimized)
if (!is_admin()) {
    $flash = get_flash();
    ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - FleetKE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-wrapper { max-width: 380px; width: 100%; }
        .login-box {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .logo { text-align: center; margin-bottom: 25px; }
        .logo h1 { color: #667eea; font-size: 1.8rem; margin-bottom: 5px; }
        .logo p { color: #5f6368; font-size: 0.85rem; }
        .alert {
            padding: 10px 12px; border-radius: 8px; margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px; font-size: 0.85rem;
        }
        .alert-danger { background: #fee; color: #c33; border: 1px solid #fcc; }
        .alert-success { background: #e6f4ea; color: #34a853; border: 1px solid #b8e0c5; }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; margin-bottom: 6px; color: #5f6368;
            font-weight: 500; font-size: 0.85rem;
        }
        .form-group label i { margin-right: 4px; color: #667eea; }
        .form-control {
            width: 100%; padding: 10px 12px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 0.9rem; transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #667eea; outline: none;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .btn {
            padding: 10px 20px; border: none; border-radius: 8px;
            font-size: 0.9rem; font-weight: 600; cursor: pointer;
            transition: all 0.3s ease; display: inline-flex; align-items: center;
            justify-content: center; gap: 8px; text-decoration: none; width: 100%;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .back-link { margin-top: 18px; text-align: center; }
        .back-link a {
            color: #5f6368; text-decoration: none; font-size: 0.85rem;
        }
        .back-link a:hover { color: #667eea; text-decoration: underline; }
        .back-link i { margin-right: 4px; }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-box">
            <div class="logo">
                <h1>FleetKE Admin</h1>
                <p>Administrator Login</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type']; ?>">
                    <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo htmlspecialchars($flash['message']); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($login_error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo htmlspecialchars($login_error); ?></div>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Admin Email</label>
                    <input type="email" class="form-control" id="email" name="email" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" name="login" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i> ADMIN LOGIN
                </button>
            </form>

            <div class="back-link">
                <a href="<?php echo APP_URL; ?>/pages/login.php"><i class="fas fa-arrow-left"></i> Back to Customer Login</a>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit();
}

// ------------------------------------------------------------------
// If we reach here, user is logged in as admin – show dashboard
// ------------------------------------------------------------------

// Ensure necessary tables exist (with error suppression to avoid output)
$conn->query("CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    rating INT DEFAULT 5,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reply TEXT NULL,
    reply_date TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)");

$result = $conn->query("SHOW COLUMNS FROM feedback LIKE 'reply'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE feedback ADD COLUMN reply TEXT NULL");
}
$result = $conn->query("SHOW COLUMNS FROM feedback LIKE 'reply_date'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE feedback ADD COLUMN reply_date TIMESTAMP NULL");
}

$conn->query("CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50),
    transaction_reference VARCHAR(100) UNIQUE,
    payment_status ENUM('pending','completed','failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
)");

$result = $conn->query("SHOW COLUMNS FROM payments LIKE 'created_at'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE payments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}
$result = $conn->query("SHOW COLUMNS FROM payments LIKE 'updated_at'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE payments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}
$result = $conn->query("SHOW COLUMNS FROM payments LIKE 'payment_method'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE payments ADD COLUMN payment_method VARCHAR(50)");
}

$conn->query("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$result = $conn->query("SHOW COLUMNS FROM vehicles LIKE 'is_featured'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE vehicles ADD COLUMN is_featured TINYINT(1) DEFAULT 0");
}
$result = $conn->query("SHOW COLUMNS FROM vehicles LIKE 'discount_percent'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE vehicles ADD COLUMN discount_percent DECIMAL(5,2) DEFAULT 0.00");
}

// ================================================================
// Handle System Settings Update
// ================================================================
if (isset($_POST['update_settings'])) {
    $settings = [
        'site_name' => sanitize($_POST['site_name'] ?? 'FleetKE'),
        'contact_email' => sanitize($_POST['contact_email'] ?? ''),
        'contact_phone' => sanitize($_POST['contact_phone'] ?? ''),
        'contact_address' => sanitize($_POST['contact_address'] ?? ''),
        'currency' => sanitize($_POST['currency'] ?? 'Ksh'),
        'tax_rate' => (float)($_POST['tax_rate'] ?? 0)
    ];
    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("sss", $key, $value, $value);
        $stmt->execute();
    }
    set_flash('success', 'System settings updated successfully!');
    redirect('admin_login.php');
}

// ================================================================
// Handle Feedback Status Update (Approve/Reject)
// ================================================================
if (isset($_POST['update_feedback']) && isset($_POST['feedback_id']) && isset($_POST['status'])) {
    $feedback_id = (int)$_POST['feedback_id'];
    $status = sanitize($_POST['status']);
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        set_flash('error', 'Invalid CSRF token.');
        redirect('admin_login.php');
    }
    $stmt = $conn->prepare("UPDATE feedback SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $feedback_id);
    if ($stmt->execute()) {
        set_flash('success', 'Feedback status updated.');
    } else {
        set_flash('error', 'Failed to update status: ' . $stmt->error);
    }
    redirect('admin_login.php');
}

// ================================================================
// Handle Feedback Deletion
// ================================================================
if (isset($_POST['delete_feedback']) && isset($_POST['feedback_id'])) {
    $feedback_id = (int)$_POST['feedback_id'];
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        set_flash('error', 'Invalid CSRF token.');
        redirect('admin_login.php');
    }
    $stmt = $conn->prepare("DELETE FROM feedback WHERE id = ?");
    $stmt->bind_param("i", $feedback_id);
    if ($stmt->execute()) {
        set_flash('success', 'Feedback deleted successfully.');
    } else {
        set_flash('error', 'Failed to delete feedback: ' . $stmt->error);
    }
    redirect('admin_login.php');
}

// ================================================================
// Handle Toggle Featured Vehicle
// ================================================================
if (isset($_GET['toggle_featured']) && isset($_GET['vehicle_id'])) {
    $vehicle_id = (int)$_GET['vehicle_id'];
    $current = $conn->query("SELECT is_featured FROM vehicles WHERE id = $vehicle_id");
    if ($current && $current->num_rows > 0) {
        $row = $current->fetch_assoc();
        $new_value = $row['is_featured'] ? 0 : 1;
        $conn->query("UPDATE vehicles SET is_featured = $new_value WHERE id = $vehicle_id");
        set_flash('success', 'Featured status updated!');
    } else {
        set_flash('error', 'Vehicle not found.');
    }
    redirect('admin_login.php');
}

// ================================================================
// Handle User Status Toggle (Active/Inactive)
// ================================================================
if (isset($_GET['toggle_user_status']) && isset($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
    if ($user_id == $_SESSION['user_id']) {
        set_flash('error', 'You cannot change your own status!');
        redirect('admin_login.php');
    }
    $current = $conn->query("SELECT status FROM users WHERE id = $user_id");
    if ($current && $current->num_rows > 0) {
        $row = $current->fetch_assoc();
        $new_status = ($row['status'] == 'active') ? 'inactive' : 'active';
        $conn->query("UPDATE users SET status = '$new_status' WHERE id = $user_id");
        set_flash('success', 'User status updated!');
    } else {
        set_flash('error', 'User not found.');
    }
    redirect('admin_login.php');
}

// ================================================================
// Handle User Deletion
// ================================================================
if (isset($_GET['delete_user']) && isset($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
    if ($user_id == $_SESSION['user_id']) {
        set_flash('error', 'You cannot delete your own account!');
        redirect('admin_login.php');
    }
    $conn->query("DELETE FROM users WHERE id = $user_id");
    set_flash('success', 'User deleted successfully!');
    redirect('admin_login.php');
}

// ================================================================
// Handle Report Generation (CSV)
// ================================================================
if (isset($_GET['generate_report']) && isset($_GET['report_type'])) {
    $report_type = $_GET['report_type'];
    $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
    $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
    
    $filename = $report_type . '_report_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    switch ($report_type) {
        case 'bookings':
            fputcsv($output, ['Booking ID', 'Reference', 'Customer', 'Vehicle', 'Pickup Date', 'Return Date', 'Total Amount', 'Status', 'Payment Status']);
            $result = $conn->query("
                SELECT b.id, b.booking_reference, CONCAT(u.first_name, ' ', u.last_name) as customer,
                       CONCAT(v.make, ' ', v.model) as vehicle, b.pickup_date, b.return_date,
                       b.total_amount, b.status, b.payment_status
                FROM bookings b
                LEFT JOIN users u ON b.user_id = u.id
                LEFT JOIN vehicles v ON b.vehicle_id = v.id
                ORDER BY b.created_at DESC
            ");
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    fputcsv($output, $row);
                }
            } else {
                fputcsv($output, ['No data found', '']);
            }
            break;
        case 'payments':
            fputcsv($output, ['Payment ID', 'Booking Ref', 'Customer', 'Amount', 'Transaction Ref', 'Payment Method', 'Status', 'Date']);
            $result = $conn->query("
                SELECT p.id, b.booking_reference, CONCAT(u.first_name, ' ', u.last_name) as customer,
                       p.amount, p.transaction_reference, p.payment_method, p.payment_status, p.created_at
                FROM payments p
                JOIN bookings b ON p.booking_id = b.id
                JOIN users u ON b.user_id = u.id
                ORDER BY p.created_at DESC
            ");
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    fputcsv($output, $row);
                }
            } else {
                fputcsv($output, ['No data found', '']);
            }
            break;
        case 'vehicles':
            fputcsv($output, ['ID', 'Registration', 'Make', 'Model', 'Year', 'Color', 'Type', 'Daily Rate', 'Discount %', 'Status', 'Featured']);
            $result = $conn->query("
                SELECT id, registration_number, make, model, year, color, vehicle_type,
                       daily_rate, discount_percent, status, is_featured
                FROM vehicles ORDER BY id DESC
            ");
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    fputcsv($output, $row);
                }
            } else {
                fputcsv($output, ['No data found', '']);
            }
            break;
        case 'users':
            fputcsv($output, ['ID', 'Name', 'Email', 'Phone', 'Role', 'Status', 'Registered Date']);
            $result = $conn->query("
                SELECT id, CONCAT(first_name, ' ', last_name) as name, email, phone, user_role, status, created_at
                FROM users ORDER BY created_at DESC
            ");
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    fputcsv($output, $row);
                }
            } else {
                fputcsv($output, ['No data found', '']);
            }
            break;
        default:
            fputcsv($output, ['Error', 'Invalid report type']);
    }
    fclose($output);
    exit();
}

// ================================================================
// FETCH PAYMENT STATS AND LIST FOR DASHBOARD (SAFE QUERIES)
// ================================================================
$total_payments = 0;
$pending_payments = 0;
$failed_payments = 0;
$completed_count = 0;
$pending_count = 0;
$failed_count = 0;
$success_rate = 0;

$total_payments_query = $conn->query("SELECT SUM(amount) as total FROM payments WHERE payment_status = 'completed'");
if ($total_payments_query && $total_payments_query->num_rows > 0) {
    $total_payments = $total_payments_query->fetch_assoc()['total'] ?? 0;
}

$pending_payments_query = $conn->query("SELECT SUM(amount) as total FROM payments WHERE payment_status = 'pending'");
if ($pending_payments_query && $pending_payments_query->num_rows > 0) {
    $pending_payments = $pending_payments_query->fetch_assoc()['total'] ?? 0;
}

$failed_payments_query = $conn->query("SELECT SUM(amount) as total FROM payments WHERE payment_status = 'failed'");
if ($failed_payments_query && $failed_payments_query->num_rows > 0) {
    $failed_payments = $failed_payments_query->fetch_assoc()['total'] ?? 0;
}

$completed_count_query = $conn->query("SELECT COUNT(*) as cnt FROM payments WHERE payment_status = 'completed'");
if ($completed_count_query && $completed_count_query->num_rows > 0) {
    $completed_count = $completed_count_query->fetch_assoc()['cnt'];
}
$pending_count_query = $conn->query("SELECT COUNT(*) as cnt FROM payments WHERE payment_status = 'pending'");
if ($pending_count_query && $pending_count_query->num_rows > 0) {
    $pending_count = $pending_count_query->fetch_assoc()['cnt'];
}
$failed_count_query = $conn->query("SELECT COUNT(*) as cnt FROM payments WHERE payment_status = 'failed'");
if ($failed_count_query && $failed_count_query->num_rows > 0) {
    $failed_count = $failed_count_query->fetch_assoc()['cnt'];
}
$total_count = $completed_count + $pending_count + $failed_count;
if ($total_count > 0) {
    $success_rate = round(($completed_count / $total_count) * 100, 1);
}

// Fetch all payments for the table
$payments_list = [];
$payments_result = $conn->query("
    SELECT p.id, b.booking_reference, CONCAT(u.first_name, ' ', u.last_name) as customer,
           p.amount, p.transaction_reference, p.payment_method, p.payment_status, p.created_at
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN users u ON b.user_id = u.id
    ORDER BY p.created_at DESC
    LIMIT 50
");
if ($payments_result && $payments_result->num_rows > 0) {
    while ($row = $payments_result->fetch_assoc()) {
        $payments_list[] = $row;
    }
}

// ================================================================
// FETCH ALL BOOKINGS (for the new section)
// ================================================================
$all_bookings = [];
$bookings_query = "
    SELECT 
        b.id,
        b.booking_reference,
        b.pickup_date,
        b.return_date,
        b.total_amount,
        b.status,
        b.payment_status,
        b.created_at,
        CONCAT(u.first_name, ' ', u.last_name) as customer_name,
        u.email as customer_email,
        u.phone as customer_phone,
        CONCAT(v.make, ' ', v.model, ' (', v.registration_number, ')') as vehicle_name
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN vehicles v ON b.vehicle_id = v.id
    ORDER BY b.created_at DESC
";
$bookings_result = $conn->query($bookings_query);
if ($bookings_result && $bookings_result->num_rows > 0) {
    while ($row = $bookings_result->fetch_assoc()) {
        $all_bookings[] = $row;
    }
}

// Fetch settings
$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
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

// Ensure features_list exists with default features
$features_check = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'features_list'");
if (!$features_check || $features_check->num_rows == 0) {
    $default_features = [
        ['icon' => '🚗', 'title' => 'Wide Vehicle Selection', 'description' => 'Choose from economy cars to luxury SUVs, all well-maintained and ready to drive.'],
        ['icon' => '📍', 'title' => 'Multiple Locations', 'description' => 'Pickup and drop-off across all major counties in Kenya – Nairobi, Mombasa, Kisumu and more.'],
        ['icon' => '💳', 'title' => 'Easy Payment', 'description' => 'Pay securely via M-Pesa, credit card, or cash at our offices. No hidden fees.'],
        ['icon' => '🛡️', 'title' => 'Comprehensive Insurance', 'description' => 'All rentals include third-party insurance. Optional comprehensive cover available.'],
        ['icon' => '⚡', 'title' => 'Instant Booking', 'description' => 'Book online in minutes and receive instant confirmation. No waiting.'],
        ['icon' => '📞', 'title' => '24/7 Support', 'description' => 'Our customer service team is available around the clock to assist you.']
    ];
    $features_json = json_encode($default_features);
    $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('features_list', '$features_json')");
}

// Fetch featured vehicles
$featured_vehicles = [];
$fv_query = $conn->query("SELECT * FROM vehicles WHERE is_featured = 1 ORDER BY id DESC");
if ($fv_query && $fv_query->num_rows > 0) {
    $featured_vehicles = $fv_query->fetch_all(MYSQLI_ASSOC);
}

// Fetch all users
$users = [];
$users_query = $conn->query("SELECT id, first_name, last_name, email, phone, user_role, status, created_at FROM users ORDER BY created_at DESC");
if ($users_query && $users_query->num_rows > 0) {
    $users = $users_query->fetch_all(MYSQLI_ASSOC);
}

// Existing queries
$feedbacks = [];
$feedback_query = $conn->query("SELECT f.*, u.first_name, u.last_name FROM feedback f LEFT JOIN users u ON f.user_id = u.id ORDER BY f.created_at DESC");
if ($feedback_query && $feedback_query->num_rows > 0) {
    $feedbacks = $feedback_query->fetch_all(MYSQLI_ASSOC);
}

// Rented cars query
$rented_cars = [];
$rented_cars_query = "
    SELECT 
        v.*,
        b.id as booking_id,
        b.booking_reference,
        b.pickup_date,
        b.return_date,
        u.first_name,
        u.last_name,
        u.email,
        u.phone
    FROM vehicles v
    INNER JOIN bookings b ON v.id = b.vehicle_id
    INNER JOIN users u ON b.user_id = u.id
    WHERE v.status = 'rented' 
      AND b.status IN ('pending', 'confirmed', 'active')
    GROUP BY v.id
    ORDER BY v.id DESC
";
$rc_result = $conn->query($rented_cars_query);
if ($rc_result && $rc_result->num_rows > 0) {
    $rented_cars = $rc_result->fetch_all(MYSQLI_ASSOC);
}

$available_cars = [];
$ac_result = $conn->query("SELECT * FROM vehicles WHERE status = 'available' ORDER BY id DESC");
if ($ac_result && $ac_result->num_rows > 0) {
    $available_cars = $ac_result->fetch_all(MYSQLI_ASSOC);
}

$all_vehicles = $vehicle->getAllVehicles();
$stats = $vehicle->getVehicleStats();

$KENYA_COUNTIES = [
    'nairobi' => 'Nairobi', 'mombasa' => 'Mombasa', 'kisumu' => 'Kisumu',
    'nakuru' => 'Nakuru', 'eldoret' => 'Eldoret', 'kericho' => 'Kericho',
    'naivasha' => 'Naivasha', 'nyeri' => 'Nyeri', 'muranga' => 'Murang\'a', 'thika' => 'Thika'
];

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Bootstrap CSS + JS for carousel -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        /* ========== RESET & GLOBAL ========== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0b2b40 0%, #0a1a2e 100%);
            min-height: 100vh;
            color: #ffffff;
        }

        /* Ocean Glassmorphism Effect - COMPACT VERSION */
        .glass-panel, .admin-sidebar, .top-bar, .stat-card, .table-section, .payment-stats, .modal-content, .vehicle-card, .feedback-card {
            background: rgba(15, 40, 55, 0.65);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 20px;
            border: 1px solid rgba(64, 224, 208, 0.25);
            box-shadow: 0 6px 20px 0 rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }

        .glass-panel:hover, .vehicle-card:hover, .stat-card:hover {
            border-color: rgba(64, 224, 208, 0.6);
            box-shadow: 0 8px 25px 0 rgba(64, 224, 208, 0.15);
            transform: translateY(-2px);
        }

        .admin-sidebar::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 80px;
            background: linear-gradient(0deg, rgba(64,224,208,0.1) 0%, rgba(64,224,208,0) 100%);
            pointer-events: none;
        }

        .admin-wrapper { display: flex; min-height: 100vh; }
        .admin-sidebar {
            width: 260px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            margin: 12px 0 12px 12px;
        }
        .sidebar-header { padding: 1.2rem 1rem; border-bottom: 2px solid rgba(64, 224, 208, 0.3); text-align: center; }
        .sidebar-header h2 { font-size: 1.5rem; margin-bottom: 0.2rem; background: linear-gradient(135deg, #7fffd4, #40e0d0); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .sidebar-header p { font-size: 0.7rem; opacity: 0.7; }
        .admin-profile {
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            border-bottom: 2px solid rgba(64, 224, 208, 0.3);
        }
        .profile-image {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, #7fffd4, #40e0d0);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #0a1a2e;
        }
        .profile-info h4 { font-size: 0.85rem; margin-bottom: 0.1rem; }
        .profile-info p { font-size: 0.7rem; opacity: 0.8; }
        .sidebar-nav { padding: 0.5rem 0; }
        .nav-item {
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            color: rgba(255,255,255,0.8);
            transition: all 0.3s;
            cursor: pointer;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }
        .nav-item:hover { 
            background: rgba(64, 224, 208, 0.15); 
            border-left-color: #40e0d0;
            color: #ffffff;
        }
        .nav-item i { width: 20px; font-size: 1rem; }
        .admin-main {
            flex: 1;
            margin-left: 276px;
            padding: 1.2rem;
        }
        .top-bar {
            padding: 0.8rem 1.2rem;
            border-radius: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 0.8rem;
        }
        .page-title h1 { font-size: 1.5rem; color: #ffffff; margin-bottom: 0.1rem; }
        .page-title p { color: rgba(255,255,255,0.6); font-size: 0.8rem; }
        .top-bar-actions { display: flex; gap: 0.8rem; align-items: center; flex-wrap: wrap; }
        .date-display {
            background: rgba(255,255,255,0.1);
            padding: 0.4rem 0.8rem;
            border-radius: 30px;
            font-size: 0.8rem;
        }
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            background: rgba(64, 224, 208, 0.2);
            color: #ffffff;
            backdrop-filter: blur(8px);
            border: 1px solid rgba(64, 224, 208, 0.3);
        }
        .btn-primary { background: rgba(64, 224, 208, 0.3); border-color: #40e0d0; }
        .btn-primary:hover { background: rgba(64, 224, 208, 0.5); transform: translateY(-2px); }
        .btn-success { background: rgba(46, 204, 113, 0.3); border-color: #2ecc71; }
        .btn-success:hover { background: rgba(46, 204, 113, 0.5); transform: translateY(-2px); }
        .btn-danger { background: rgba(231, 76, 60, 0.3); border-color: #e74c3c; }
        .btn-danger:hover { background: rgba(231, 76, 60, 0.5); transform: translateY(-2px); }
        .btn-outline { background: transparent; border: 1px solid #40e0d0; color: #40e0d0; }
        .btn-outline:hover { background: rgba(64, 224, 208, 0.2); color: #ffffff; }
        .btn-sm { padding: 3px 8px; font-size: 0.7rem; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            padding: 1rem;
            text-align: center;
        }
        .stat-card h3 { font-size: 1.5rem; color: #40e0d0; margin-bottom: 0.3rem; }
        .stat-card p { color: rgba(255,255,255,0.7); font-size: 0.8rem; }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.8rem;
            flex-wrap: wrap;
            gap: 0.8rem;
        }
        .section-header h2 { font-size: 1.1rem; color: #ffffff; }
        .table-section {
            padding: 1rem;
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th {
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            color: rgba(255,255,255,0.9);
            border-bottom: 2px solid rgba(64, 224, 208, 0.3);
            font-size: 0.8rem;
        }
        td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.8);
            font-size: 0.8rem;
        }
        tr:hover { background: rgba(64, 224, 208, 0.1); }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-paid, .badge-completed { background: rgba(46, 204, 113, 0.3); color: #2ecc71; border: 1px solid #2ecc71; }
        .badge-pending { background: rgba(241, 196, 15, 0.3); color: #f1c40f; border: 1px solid #f1c40f; }
        .badge-cancelled { background: rgba(231, 76, 60, 0.3); color: #e74c3c; border: 1px solid #e74c3c; }
        .badge-available { background: rgba(46, 204, 113, 0.3); color: #2ecc71; border: 1px solid #2ecc71; }
        .badge-rented { background: rgba(231, 76, 60, 0.3); color: #e74c3c; border: 1px solid #e74c3c; }
        .badge-active { background: rgba(46, 204, 113, 0.3); color: #2ecc71; border: 1px solid #2ecc71; }
        .badge-inactive { background: rgba(231, 76, 60, 0.3); color: #e74c3c; border: 1px solid #e74c3c; }
        .badge-admin { background: rgba(64, 224, 208, 0.3); color: #40e0d0; border: 1px solid #40e0d0; }
        .badge-customer { background: rgba(155, 89, 182, 0.3); color: #9b59b6; border: 1px solid #9b59b6; }
        .filter-buttons { display: flex; gap: 0.4rem; margin-bottom: 0.8rem; flex-wrap: wrap; }
        .filter-btn {
            padding: 4px 10px;
            border: none;
            border-radius: 20px;
            background: rgba(255,255,255,0.1);
            cursor: pointer;
            font-weight: 500;
            color: #ffffff;
            font-size: 0.75rem;
        }
        .filter-btn.active { background: #40e0d0; color: #0a1a2e; }
        .filters-bar {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .search-box {
            flex: 1;
            min-width: 200px;
            position: relative;
        }
        .search-box i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.6);
            font-size: 0.8rem;
        }
        .search-box input {
            width: 100%;
            padding: 6px 10px 6px 30px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(64, 224, 208, 0.3);
            border-radius: 12px;
            font-size: 0.8rem;
            color: #ffffff;
        }
        .search-box input::placeholder { color: rgba(255,255,255,0.5); }
        .filter-select {
            padding: 6px 10px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(64, 224, 208, 0.3);
            border-radius: 12px;
            font-size: 0.8rem;
            color: #ffffff;
        }
        .filter-select option { background: #0a1a2e; }
        .vehicles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 0.8rem;
        }
        .vehicle-card {
            overflow: hidden;
        }
        .vehicle-image {
            height: 140px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            cursor: pointer;
            background: rgba(0,0,0,0.2);
        }
        .vehicle-image img { width: 100%; height: 100%; object-fit: cover; }
        .vehicle-image i { font-size: 2.5rem; color: rgba(255,255,255,0.5); }
        .vehicle-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            color: white;
            padding: 2px 6px;
            border-radius: 20px;
            font-size: 0.65rem;
            z-index: 10;
        }
        .vehicle-info { padding: 0.8rem; }
        .vehicle-title { font-weight: 600; margin-bottom: 0.3rem; font-size: 0.9rem; }
        .vehicle-details { color: rgba(255,255,255,0.6); font-size: 0.7rem; margin-bottom: 0.3rem; display: flex; flex-wrap: wrap; gap: 0.3rem; }
        .vehicle-details span { background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 12px; }
        .vehicle-price { font-size: 1rem; font-weight: 700; color: #40e0d0; margin: 0.3rem 0; }
        .vehicle-actions { display: flex; gap: 0.4rem; margin-top: 0.8rem; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 0.6rem; }
        .vehicle-actions .btn { flex: 1; text-align: center; justify-content: center; }
        .feedback-grid { display: flex; flex-direction: column; gap: 0.8rem; }
        .feedback-card {
            padding: 0.8rem;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.8rem;
            border-left: 4px solid #40e0d0;
        }
        .feedback-info { flex: 1; }
        .feedback-meta { display: flex; gap: 0.8rem; flex-wrap: wrap; color: rgba(255,255,255,0.6); font-size: 0.7rem; margin-bottom: 0.3rem; }
        .feedback-message { color: rgba(255,255,255,0.9); margin-bottom: 0.3rem; font-size: 0.8rem; }
        .feedback-reply {
            background: rgba(255,255,255,0.05);
            padding: 0.4rem;
            border-radius: 12px;
            margin-top: 0.4rem;
            border-left: 3px solid #2ecc71;
        }
        .feedback-status {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }
        .status-pending { background: rgba(241, 196, 15, 0.3); color: #f1c40f; }
        .status-approved { background: rgba(46, 204, 113, 0.3); color: #2ecc71; }
        .status-rejected { background: rgba(231, 76, 60, 0.3); color: #e74c3c; }
        .payment-stats {
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .payment-summary {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-bottom: 0.8rem;
        }
        .payment-summary-item {
            flex: 1;
            background: rgba(255,255,255,0.05);
            padding: 0.8rem;
            border-radius: 16px;
            text-align: center;
        }
        .payment-summary-item h4 { font-size: 0.7rem; color: rgba(255,255,255,0.7); margin-bottom: 0.2rem; }
        .payment-summary-item .amount { font-size: 1.2rem; font-weight: bold; color: #40e0d0; }
        .progress-container {
            background: rgba(255,255,255,0.1);
            border-radius: 30px;
            height: 24px;
            overflow: hidden;
            display: flex;
        }
        .progress-completed { background: #2ecc71; color: white; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; }
        .progress-pending { background: #f1c40f; color: #2c3e50; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; }
        .progress-failed { background: #e74c3c; color: white; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; }
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(8px);
            overflow-y: auto;
        }
        .modal-content {
            max-width: 750px;
            margin: 40px auto;
        }
        .modal-header {
            padding: 0.8rem 1.2rem;
            border-bottom: 1px solid rgba(64, 224, 208, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { color: #ffffff; font-size: 1.1rem; }
        .modal-close { font-size: 1.3rem; cursor: pointer; color: rgba(255,255,255,0.7); }
        .modal-close:hover { color: #40e0d0; }
        .modal-body { padding: 1rem; max-height: 60vh; overflow-y: auto; }
        .modal-footer {
            padding: 0.8rem 1rem;
            border-top: 1px solid rgba(64, 224, 208, 0.3);
            display: flex;
            gap: 0.8rem;
            justify-content: flex-end;
        }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.8rem; }
        .form-group { margin-bottom: 0.8rem; }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label { display: block; margin-bottom: 0.2rem; font-weight: 500; color: rgba(255,255,255,0.9); font-size: 0.75rem; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 6px 10px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(64, 224, 208, 0.3);
            border-radius: 10px;
            font-size: 0.8rem;
            color: #ffffff;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #40e0d0;
        }
        .current-image-preview {
            max-width: 180px;
            max-height: 100px;
            margin-top: 8px;
            border-radius: 8px;
            border: 1px solid rgba(64, 224, 208, 0.3);
        }
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(15, 40, 55, 0.95);
            backdrop-filter: blur(12px);
            border-radius: 16px;
            padding: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            z-index: 3000;
            border-left: 4px solid;
            border: 1px solid rgba(64, 224, 208, 0.3);
        }
        .toast.success { border-left-color: #2ecc71; }
        .toast.error { border-left-color: #e74c3c; }
        .toast.info { border-left-color: #40e0d0; }
        .toast i { font-size: 1rem; }
        .toast.success i { color: #2ecc71; }
        .toast.error i { color: #e74c3c; }
        .toast.info i { color: #40e0d0; }
        .toast-content { flex: 1; color: #ffffff; font-size: 0.8rem; }
        .toast-close { cursor: pointer; color: rgba(255,255,255,0.7); }
        .featured-vehicles-grid { display: flex; flex-wrap: wrap; gap: 0.8rem; margin-bottom: 0.8rem; }
        .featured-card {
            background: rgba(255,255,255,0.05);
            border-radius: 16px;
            width: 200px;
            overflow: hidden;
            border: 1px solid rgba(64, 224, 208, 0.3);
        }
        .featured-card-info { padding: 0.6rem; }
        @media (max-width: 1024px) {
            .admin-sidebar { width: 70px; margin: 8px 0 8px 8px; }
            .sidebar-header h2, .sidebar-header p, .profile-info, .nav-item span { display: none; }
            .admin-profile { justify-content: center; }
            .nav-item { justify-content: center; }
            .admin-main { margin-left: 86px; }
        }
        @media (max-width: 768px) {
            .admin-main { padding: 1rem; margin-left: 0; }
            .admin-sidebar { display: none; }
            .stats-grid { grid-template-columns: 1fr; }
            .vehicles-grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: rgba(64, 224, 208, 0.5); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(64, 224, 208, 0.8); }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-sidebar glass-panel">
            <div class="sidebar-header">
                <h2><?php echo htmlspecialchars($settings['site_name']); ?></h2>
                <p>Admin Panel</p>
            </div>
            <div class="admin-profile">
                <div class="profile-image"><i class="fas fa-user-shield"></i></div>
                <div class="profile-info">
                    <h4><?php echo htmlspecialchars($_SESSION['user_name']); ?></h4>
                    <p>Administrator</p>
                </div>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-item" onclick="document.getElementById('vehiclesSection').scrollIntoView({behavior:'smooth'})"><i class="fas fa-car"></i><span>All Vehicles</span></div>
                <div class="nav-item" onclick="document.getElementById('rentedSection').scrollIntoView({behavior:'smooth'})"><i class="fas fa-key"></i><span>Rented Cars</span></div>
                <div class="nav-item" onclick="document.getElementById('availableSection').scrollIntoView({behavior:'smooth'})"><i class="fas fa-check-circle"></i><span>Available Cars</span></div>
                <div class="nav-item" onclick="document.getElementById('paymentsSection').scrollIntoView({behavior:'smooth'})"><i class="fas fa-credit-card"></i><span>Payments</span></div>
                <div class="nav-item" onclick="document.getElementById('bookingsSection').scrollIntoView({behavior:'smooth'})"><i class="fas fa-calendar-alt"></i><span>All Bookings</span></div>
                <div class="nav-item" onclick="document.getElementById('settingsSection').scrollIntoView({behavior:'smooth'})"><i class="fas fa-cog"></i><span>System Settings</span></div>
                <div class="nav-item" onclick="document.getElementById('featuredSection').scrollIntoView({behavior:'smooth'})"><i class="fas fa-star"></i><span>Featured Vehicles</span></div>
                <div class="nav-item" onclick="document.getElementById('usersSection').scrollIntoView({behavior:'smooth'})"><i class="fas fa-users"></i><span>Users</span></div>
                <div class="nav-item" onclick="document.getElementById('feedbackSection').scrollIntoView({behavior:'smooth'})"><i class="fas fa-comments"></i><span>Feedback</span></div>
                <div class="nav-item" onclick="if(confirm('⚠️ ADMIN BOOKING RESTRICTION: As an administrator, you cannot make bookings. You will be redirected to view-only mode.')) window.location.href='<?php echo APP_URL; ?>/pages/browse.php?admin_view=1';">
                    <i class="fas fa-globe"></i><span>View Site (Read-only)</span>
                </div>
                <div class="nav-item" onclick="window.location.href='<?php echo APP_URL; ?>/pages/logout.php'"><i class="fas fa-sign-out-alt"></i><span>Logout</span></div>
            </nav>
        </aside>

        <main class="admin-main">
            <div class="top-bar glass-panel">
                <div class="page-title">
                    <h1>Dashboard Overview</h1>
                    <p>Manage your fleet and customer feedback</p>
                </div>
                <div class="top-bar-actions">
                    <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('d M Y'); ?></div>
                    <button class="btn btn-primary" onclick="document.getElementById('bookingsSection').scrollIntoView({behavior:'smooth'})"><i class="fas fa-calendar-alt"></i> All Bookings</button>
                    <button class="btn btn-primary" onclick="openReportModal()"><i class="fas fa-chart-line"></i> Generate Report</button>
                    <a href="admin_login.php" class="btn btn-outline" title="Refresh Dashboard"><i class="fas fa-sync-alt"></i> Refresh</a>
                </div>
            </div>

            <div class="stats-grid" id="statsGrid">
                <div class="stat-card glass-panel"><h3 id="totalVehicles"><?php echo $stats['total'] ?? 0; ?></h3><p>Total Vehicles</p></div>
                <div class="stat-card glass-panel"><h3 id="availableVehicles"><?php echo $stats['by_status']['available'] ?? 0; ?></h3><p>Available</p></div>
                <div class="stat-card glass-panel"><h3 id="rentedVehicles"><?php echo $stats['by_status']['rented'] ?? 0; ?></h3><p>Rented</p></div>
                <div class="stat-card glass-panel"><h3 id="avgRate"><?php echo format_currency($stats['avg_daily_rate'] ?? 0); ?></h3><p>Avg. Daily Rate</p></div>
            </div>

            <div class="payment-stats glass-panel" id="paymentsSection">
                <div class="section-header">
                    <h2><i class="fas fa-credit-card"></i> Payment Overview</h2>
                    <span class="badge badge-info">Real-time tracking</span>
                </div>
                <div class="payment-summary">
                    <div class="payment-summary-item"><h4>Total Collected</h4><div class="amount"><?php echo format_currency($total_payments); ?></div></div>
                    <div class="payment-summary-item"><h4>Pending</h4><div class="amount"><?php echo format_currency($pending_payments); ?></div></div>
                    <div class="payment-summary-item"><h4>Failed</h4><div class="amount"><?php echo format_currency($failed_payments); ?></div></div>
                    <div class="payment-summary-item"><h4>Success Rate</h4><div class="amount"><?php echo $success_rate; ?>%</div></div>
                </div>
                <div class="progress-container">
                    <div class="progress-completed" style="width: <?php echo $total_count ? ($completed_count / $total_count) * 100 : 0; ?>%;"><?php echo $completed_count > 0 ? $completed_count . ' completed' : ''; ?></div>
                    <div class="progress-pending" style="width: <?php echo $total_count ? ($pending_count / $total_count) * 100 : 0; ?>%;"><?php echo $pending_count > 0 ? $pending_count . ' pending' : ''; ?></div>
                    <div class="progress-failed" style="width: <?php echo $total_count ? ($failed_count / $total_count) * 100 : 0; ?>%;"><?php echo $failed_count > 0 ? $failed_count . ' failed' : ''; ?></div>
                </div>
            </div>

            <div class="table-section glass-panel">
                <div class="section-header"><h2><i class="fas fa-list"></i> Recent Payments</h2><button class="btn btn-primary btn-sm" onclick="openReportModal('payments')"><i class="fas fa-download"></i> Export All</button></div>
                <?php if (empty($payments_list)): ?>
                    <p style="text-align:center; color:rgba(255,255,255,0.6); font-size:0.8rem;">No payment records found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="payments-table">
                            <thead>
                                <tr><th>ID</th><th>Booking Ref</th><th>Customer</th><th>Amount</th><th>Transaction Ref</th><th>Method</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments_list as $payment): ?>
                                <tr>
                                    <td><?php echo $payment['id']; ?></td>
                                    <td><?php echo htmlspecialchars($payment['booking_reference']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['customer']); ?></td>
                                    <td><?php echo format_currency($payment['amount']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['transaction_reference'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['payment_method'] ?? '—'); ?></td>
                                    <td><span class="badge <?php echo $payment['payment_status'] == 'completed' ? 'badge-paid' : ($payment['payment_status'] == 'pending' ? 'badge-pending' : 'badge-cancelled'); ?>"><?php echo ucfirst($payment['payment_status']); ?></span></td>
                                    <td><?php echo format_date_short($payment['created_at']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-section glass-panel" id="rentedSection">
                <div class="section-header"><h2><i class="fas fa-key"></i> Currently Rented Cars</h2><span class="badge badge-rented"><?php echo count($rented_cars); ?> Rented</span></div>
                <?php if (empty($rented_cars)): ?>
                    <p style="text-align:center; color:rgba(255,255,255,0.6); font-size:0.8rem;">No cars are currently rented.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="rented-table">
                            <thead>
                                <tr><th>Vehicle</th><th>Registration</th><th>Customer</th><th>Contact</th><th>Booking Ref</th><th>Pick-up Date</th><th>Return Date</th><th>Daily Rate</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rented_cars as $car): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($car['make'] . ' ' . $car['model'] . ' (' . $car['year'] . ')'); ?><br><small><?php echo htmlspecialchars($car['color']); ?></small></td>
                                    <td><?php echo htmlspecialchars($car['registration_number']); ?></td>
                                    <td><?php echo htmlspecialchars($car['first_name'] . ' ' . $car['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($car['email']); ?><br><small><?php echo htmlspecialchars($car['phone']); ?></small></td>
                                    <td><strong><?php echo htmlspecialchars($car['booking_reference']); ?></strong></td>
                                    <td><?php echo format_date_short($car['pickup_date']); ?></td>
                                    <td><?php echo format_date_short($car['return_date']); ?></td>
                                    <td><?php echo format_currency($car['daily_rate']); ?>/day</div>
                                    <td>
                                        <button class="btn btn-success btn-sm" onclick="returnVehicle(<?php echo $car['booking_id']; ?>, <?php echo $car['id']; ?>)">
                                            <i class="fas fa-undo-alt"></i> Return
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-section glass-panel" id="availableSection">
                <div class="section-header"><h2><i class="fas fa-check-circle"></i> Available Cars</h2><span class="badge badge-available"><?php echo count($available_cars); ?> Available</span></div>
                <?php if (empty($available_cars)): ?>
                    <p style="text-align:center; color:rgba(255,255,255,0.6); font-size:0.8rem;">No cars are currently available.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="available-table">
                            <thead>
                                <tr><th>Vehicle</th><th>Registration</th><th>Year</th><th>Color</th><th>Daily Rate</th><th>Discount</th><th>Type</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($available_cars as $car): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($car['make'] . ' ' . $car['model']); ?></td>
                                    <td><?php echo htmlspecialchars($car['registration_number']); ?></td>
                                    <td><?php echo $car['year']; ?></td>
                                    <td><?php echo htmlspecialchars($car['color']); ?></td>
                                    <td><?php echo format_currency($car['daily_rate']); ?>/day</div>
                                    <td><?php echo $car['discount_percent'] > 0 ? '<span class="badge badge-success">'.$car['discount_percent'].'% OFF</span>' : '—'; ?></div>
                                    <td><?php echo ucfirst($car['vehicle_type']); ?></div>
                                    <td><a href="<?php echo APP_URL; ?>/pages/browse.php?admin_view=1&vehicle=<?php echo $car['id']; ?>" class="btn btn-primary btn-sm" target="_blank"><i class="fas fa-eye"></i> View (Read-only)</a></div>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-section glass-panel" id="bookingsSection">
                <div class="section-header">
                    <h2><i class="fas fa-calendar-alt"></i> All Bookings</h2>
                    <span class="badge badge-info"><?php echo count($all_bookings); ?> Total</span>
                </div>
                <div class="filters-bar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="bookingSearch" placeholder="Search by ref, customer, vehicle..." onkeyup="filterBookings()">
                    </div>
                    <select class="filter-select" id="bookingStatusFilter" onchange="filterBookings()">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <?php if (empty($all_bookings)): ?>
                    <p style="text-align:center; color:rgba(255,255,255,0.6); font-size:0.8rem;">No bookings found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table id="bookingsTable">
                            <thead>
                                <tr><th>ID</th><th>Reference</th><th>Customer</th><th>Vehicle</th><th>Pickup Date</th><th>Return Date</th><th>Total Amount</th><th>Status</th><th>Payment</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_bookings as $booking): ?>
                                <tr data-status="<?php echo strtolower($booking['status']); ?>" data-search="<?php echo strtolower($booking['booking_reference'] . ' ' . $booking['customer_name'] . ' ' . $booking['vehicle_name']); ?>">
                                    <td><?php echo $booking['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($booking['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['vehicle_name']); ?></td>
                                    <td><?php echo format_date_short($booking['pickup_date']); ?></td>
                                    <td><?php echo format_date_short($booking['return_date']); ?></td>
                                    <td><?php echo format_currency($booking['total_amount']); ?></td>
                                    <td><span class="badge <?php echo $booking['status'] == 'pending' ? 'badge-pending' : ($booking['status'] == 'completed' ? 'badge-paid' : 'badge-cancelled'); ?>"><?php echo ucfirst($booking['status']); ?></span></td>
                                    <td><span class="badge <?php echo $booking['payment_status'] == 'paid' ? 'badge-paid' : ($booking['payment_status'] == 'pending' ? 'badge-pending' : 'badge-cancelled'); ?>"><?php echo ucfirst($booking['payment_status']); ?></span></td>
                                    <td style="white-space: nowrap;">
                                        <button class="btn btn-info btn-sm" onclick="viewBookingDetails(<?php echo $booking['id']; ?>, '<?php echo addslashes($booking['booking_reference']); ?>', '<?php echo addslashes($booking['customer_name']); ?>', '<?php echo addslashes($booking['customer_email']); ?>', '<?php echo addslashes($booking['customer_phone']); ?>', '<?php echo addslashes($booking['vehicle_name']); ?>', '<?php echo $booking['pickup_date']; ?>', '<?php echo $booking['return_date']; ?>', '<?php echo $booking['total_amount']; ?>', '<?php echo $booking['status']; ?>', '<?php echo $booking['payment_status']; ?>')"><i class="fas fa-eye"></i> View</button>
                                        <?php if ($booking['payment_status'] === 'paid' && $booking['status'] !== 'completed'): ?>
                                            <button class="btn btn-success btn-sm" onclick="markBookingCompleted(<?php echo $booking['id']; ?>)"><i class="fas fa-check-circle"></i> Mark as Completed</button>
                                        <?php endif; ?>
                                    </div>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-section glass-panel" id="settingsSection">
                <div class="section-header"><h2><i class="fas fa-cog"></i> System Settings</h2><span class="badge badge-info">Configure Application (Trademark & Contact)</span></div>
                <form method="post" action="" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-grid">
                        <div class="form-group"><label for="site_name">Site Name (Trademark)</label><input type="text" name="site_name" id="site_name" class="form-control" value="<?php echo htmlspecialchars($settings['site_name']); ?>" required></div>
                        <div class="form-group"><label for="contact_email">Contact Email</label><input type="email" name="contact_email" id="contact_email" class="form-control" value="<?php echo htmlspecialchars($settings['contact_email']); ?>" required></div>
                        <div class="form-group"><label for="contact_phone">Contact Phone</label><input type="text" name="contact_phone" id="contact_phone" class="form-control" value="<?php echo htmlspecialchars($settings['contact_phone']); ?>" required></div>
                        <div class="form-group"><label for="contact_address">Contact Address</label><input type="text" name="contact_address" id="contact_address" class="form-control" value="<?php echo htmlspecialchars($settings['contact_address']); ?>" required></div>
                        <div class="form-group"><label for="currency">Currency Symbol</label><input type="text" name="currency" id="currency" class="form-control" value="<?php echo htmlspecialchars($settings['currency']); ?>" required></div>
                        <div class="form-group"><label for="tax_rate">Tax Rate (%)</label><input type="number" step="0.01" name="tax_rate" id="tax_rate" class="form-control" value="<?php echo htmlspecialchars($settings['tax_rate']); ?>" required></div>
                    </div>
                    <div class="modal-footer" style="justify-content: flex-start; padding: 0.8rem 0 0 0;"><button type="submit" name="update_settings" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button></div>
                </form>
                <div class="alert alert-info" style="margin-top:0.8rem; background:rgba(64,224,208,0.1); padding:0.6rem; border-radius:12px; font-size:0.75rem;">
                    <i class="fas fa-info-circle"></i> <strong>Note:</strong> Changes here will automatically reflect on all customer pages if they read from the <code>settings</code> table.
                </div>
            </div>

            <div class="table-section glass-panel" id="featuresSection">
                <div class="section-header">
                    <h2><i class="fas fa-list-ul"></i> Manage Features Page</h2>
                    <button class="btn btn-success" onclick="addFeature()"><i class="fas fa-plus"></i> Add New Feature</button>
                </div>
                <div id="featuresListContainer">
                    <p>Loading features...</p>
                </div>
            </div>

            <script>
            let featuresData = [];

            function loadFeatures() {
                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'get_features',
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success && Array.isArray(data.features)) {
                        featuresData = data.features;
                        renderFeaturesList();
                    } else {
                        document.getElementById('featuresListContainer').innerHTML = '<p class="text-danger">Failed to load features.</p>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    document.getElementById('featuresListContainer').innerHTML = '<p class="text-danger">Error loading features.</p>';
                });
            }

            function renderFeaturesList() {
                const container = document.getElementById('featuresListContainer');
                if (!featuresData.length) {
                    container.innerHTML = '<p>No features added yet. Click "Add New Feature" to start.</p>';
                    return;
                }
                let html = '<div style="display: flex; flex-direction: column; gap: 0.8rem;">';
                featuresData.forEach((feature, index) => {
                    html += `
                        <div style="background: rgba(255,255,255,0.05); border-radius: 16px; padding: 0.8rem; border: 1px solid rgba(64, 224, 208, 0.3);">
                            <div style="display: flex; gap: 0.8rem; flex-wrap: wrap; align-items: flex-start;">
                                <div style="flex: 1;">
                                    <div class="form-group">
                                        <label>Icon (emoji or Font Awesome class)</label>
                                        <input type="text" class="form-control" id="feature_icon_${index}" value="${escapeHtml(feature.icon)}" placeholder="e.g., 🚗 or fas fa-car">
                                    </div>
                                    <div class="form-group">
                                        <label>Title</label>
                                        <input type="text" class="form-control" id="feature_title_${index}" value="${escapeHtml(feature.title)}">
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea class="form-control" id="feature_desc_${index}" rows="2">${escapeHtml(feature.description)}</textarea>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 0.4rem;">
                                    <button class="btn btn-sm btn-primary" onclick="updateFeature(${index})">Update</button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteFeature(${index})">Delete</button>
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                container.innerHTML = html;
            }

            function addFeature() {
                featuresData.push({
                    icon: "🚗",
                    title: "New Feature",
                    description: "Describe this feature..."
                });
                saveAllFeatures();
            }

            function updateFeature(index) {
                const icon = document.getElementById(`feature_icon_${index}`).value;
                const title = document.getElementById(`feature_title_${index}`).value;
                const description = document.getElementById(`feature_desc_${index}`).value;
                featuresData[index] = { icon, title, description };
                saveAllFeatures();
            }

            function deleteFeature(index) {
                if (confirm('Delete this feature permanently?')) {
                    featuresData.splice(index, 1);
                    saveAllFeatures();
                }
            }

            function saveAllFeatures() {
                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'save_features',
                        features: JSON.stringify(featuresData),
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('Success', 'Features saved successfully!', 'success');
                        loadFeatures();
                    } else {
                        showToast('Error', data.message || 'Failed to save features.', 'error');
                    }
                })
                .catch(err => {
                    showToast('Error', 'Network error: ' + err.message, 'error');
                });
            }

            function escapeHtml(str) {
                if (!str) return '';
                return str.replace(/[&<>]/g, function(m) {
                    if (m === '&') return '&amp;';
                    if (m === '<') return '&lt;';
                    if (m === '>') return '&gt;';
                    return m;
                });
            }

            document.addEventListener('DOMContentLoaded', loadFeatures);
            </script>

            <div class="table-section glass-panel" id="featuredSection">
                <div class="section-header"><h2><i class="fas fa-star"></i> Featured Vehicles</h2><span class="badge badge-info"><?php echo count($featured_vehicles); ?> Featured</span></div>
                <?php if (empty($featured_vehicles)): ?>
                    <p style="text-align:center; color:rgba(255,255,255,0.6); font-size:0.8rem;">No vehicles are currently featured. Mark vehicles as featured below.</p>
                <?php else: ?>
                    <div class="featured-vehicles-grid">
                        <?php foreach ($featured_vehicles as $fv): ?>
                            <div class="featured-card">
                                <div class="vehicle-image" style="height:130px;"><?php echo get_vehicle_slideshow($fv, APP_URL); ?></div>
                                <div class="featured-card-info"><div class="vehicle-title"><?php echo htmlspecialchars($fv['make'] . ' ' . $fv['model']); ?></div><div><small><?php echo $fv['registration_number']; ?></small></div><div class="featured-actions"><span class="badge badge-available">Featured</span><a href="?toggle_featured=1&vehicle_id=<?php echo $fv['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Remove from featured?')"><i class="fas fa-star"></i> Remove</a></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div style="margin-top: 0.8rem;"><h4 style="font-size:0.9rem;">Mark Vehicles as Featured</h4><div class="vehicles-grid" style="margin-top: 0.5rem;">
                    <?php foreach ($all_vehicles as $v): if ($v['is_featured']) continue; ?>
                    <div class="vehicle-card" style="margin:0;"><div class="vehicle-image" style="height:110px;"><?php echo get_vehicle_slideshow($v, APP_URL); ?></div><div class="vehicle-info"><div class="vehicle-title"><?php echo htmlspecialchars($v['make'] . ' ' . $v['model']); ?></div><div><small><?php echo $v['registration_number']; ?></small></div><a href="?toggle_featured=1&vehicle_id=<?php echo $v['id']; ?>" class="btn btn-success btn-sm" style="margin-top:4px;"><i class="fas fa-star"></i> Make Featured</a></div></div>
                    <?php endforeach; ?>
                </div></div>
            </div>

            <div class="table-section glass-panel" id="usersSection">
                <div class="section-header"><h2><i class="fas fa-users"></i> System Users</h2><span class="badge badge-info"><?php echo count($users); ?> Registered</span></div>
                <?php if (empty($users)): ?>
                    <p style="text-align:center; color:rgba(255,255,255,0.6); font-size:0.8rem;">No users found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="users-table">
                            <thead>
                                <tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Registered On</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                    <td><?php echo htmlspecialchars($user['email']); ?></div>
                                    <td><?php echo htmlspecialchars($user['phone'] ?? '—'); ?></div>
                                    <td><span class="badge <?php echo $user['user_role'] == 'admin' ? 'badge-admin' : 'badge-customer'; ?>"><?php echo ucfirst($user['user_role']); ?></span></td>
                                    <td><span class="badge <?php echo $user['status'] == 'active' ? 'badge-active' : 'badge-inactive'; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                                    <td><?php echo format_date_short($user['created_at']); ?></div>
                                    <td class="actions" style="white-space: nowrap;">
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <a href="?toggle_user_status=1&user_id=<?php echo $user['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Change user status?')"><i class="fas fa-ban"></i> <?php echo $user['status'] == 'active' ? 'Deactivate' : 'Activate'; ?></a>
                                            <a href="?delete_user=1&user_id=<?php echo $user['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this user permanently?')"><i class="fas fa-trash"></i> Delete</a>
                                        <?php else: ?>
                                            <span class="badge badge-info">Current Admin</span>
                                        <?php endif; ?>
                                    </div>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-section glass-panel" id="vehiclesSection">
                <div class="section-header"><h2>All Vehicles (Management)</h2><button class="btn btn-success" onclick="openAddModal()"><i class="fas fa-plus"></i> Add New Vehicle</button></div>
                <div class="filter-buttons"><button class="filter-btn active" onclick="filterByStatus('all')">All</button><button class="filter-btn" onclick="filterByStatus('available')">Available</button><button class="filter-btn" onclick="filterByStatus('rented')">Rented</button><button class="filter-btn" onclick="filterByStatus('damaged')">Damaged</button></div>
                <div class="filters-bar"><div class="search-box"><i class="fas fa-search"></i><input type="text" id="searchInput" placeholder="Search by reg, make, model..." onkeyup="filterVehicles()"></div><select class="filter-select" id="statusFilter" onchange="filterVehicles()"><option value="">All Status</option><option value="available">Available</option><option value="rented">Rented</option><option value="maintenance">Maintenance</option><option value="damaged">Damaged</option></select><select class="filter-select" id="typeFilter" onchange="filterVehicles()"><option value="">All Types</option><option value="economy">Economy</option><option value="compact">Compact</option><option value="sedan">Sedan</option><option value="suv">SUV</option><option value="luxury">Luxury</option><option value="van">Van</option><option value="truck">Truck</option></select></div>
                <div class="vehicles-grid" id="vehiclesGrid">
                    <?php if (empty($all_vehicles)): ?>
                        <p style="text-align: center; grid-column: 1/-1; color:rgba(255,255,255,0.6); font-size:0.8rem;">No vehicles found. Click "Add New Vehicle" to get started.</p>
                    <?php else: ?>
                        <?php foreach ($all_vehicles as $v): ?>
                            <div class="vehicle-card" data-status="<?php echo $v['status']; ?>" data-type="<?php echo $v['vehicle_type']; ?>" data-search="<?php echo strtolower($v['registration_number'] . ' ' . $v['make'] . ' ' . $v['model']); ?>" data-id="<?php echo $v['id']; ?>">
                                <div class="vehicle-image" onclick="openImageManager(<?php echo $v['id']; ?>)">
                                    <?php echo get_vehicle_slideshow($v, APP_URL); ?>
                                    <span class="vehicle-badge"><?php echo ucfirst($v['status']); ?></span>
                                    <div style="position:absolute; bottom:4px; left:4px; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); color:white; padding:2px 4px; border-radius:3px; font-size:0.6rem;"><i class="fas fa-images"></i> Manage Images</div>
                                </div>
                                <div class="vehicle-info"><div class="vehicle-title"><?php echo htmlspecialchars($v['make'] . ' ' . $v['model'] . ' (' . $v['year'] . ')'); ?></div><div class="vehicle-details"><span><?php echo $v['registration_number']; ?></span><span><?php echo ucfirst($v['vehicle_type']); ?></span><span><?php echo $v['seating_capacity']; ?> seats</span><?php if($v['discount_percent'] > 0): ?><span class="badge badge-success"><?php echo $v['discount_percent']; ?>% OFF</span><?php endif; ?></div><div class="vehicle-price"><?php echo format_currency($v['daily_rate']); ?>/day</div><div class="vehicle-actions"><button class="btn btn-outline btn-sm" onclick="editVehicle(<?php echo $v['id']; ?>)"><i class="fas fa-edit"></i> Edit</button><button class="btn btn-danger btn-sm" onclick="deleteVehicle(<?php echo $v['id']; ?>)"><i class="fas fa-trash"></i> Delete</button></div></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-section glass-panel" id="feedbackSection">
                <h2 style="margin-bottom:0.8rem; font-size:1.1rem;">Customer Feedback</h2>
                <div class="feedback-grid">
                    <?php if (empty($feedbacks)): ?>
                        <p style="text-align:center; color:rgba(255,255,255,0.6); font-size:0.8rem;">No feedback yet.</p>
                    <?php else: ?>
                        <?php foreach ($feedbacks as $fb): ?>
                            <div class="feedback-card">
                                <div class="feedback-info">
                                    <div class="feedback-meta"><span><i class="fas fa-user"></i> <?php echo htmlspecialchars($fb['first_name'] ?? $fb['name']); ?></span><span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($fb['email']); ?></span><span><i class="fas fa-calendar"></i> <?php echo date('d M Y', strtotime($fb['created_at'])); ?></span><span><i class="fas fa-star"></i> <?php echo $fb['rating']; ?>/5</span></div>
                                    <div class="feedback-message"><?php echo nl2br(htmlspecialchars($fb['message'])); ?></div>
                                    <?php if (!empty($fb['reply'])): ?>
                                        <div class="feedback-reply"><strong><i class="fas fa-reply"></i> Admin Reply:</strong><br><?php echo nl2br(htmlspecialchars($fb['reply'])); ?><div style="font-size:0.65rem; color:rgba(255,255,255,0.5); margin-top:4px;"><?php echo date('d M Y H:i', strtotime($fb['reply_date'])); ?></div></div>
                                    <?php endif; ?>
                                    <div style="margin-top:6px;"><span class="feedback-status status-<?php echo $fb['status']; ?>"><?php echo ucfirst($fb['status']); ?></span></div>
                                </div>
                                <div class="feedback-actions" style="display: flex; gap: 4px; flex-direction: column;">
                                    <form method="post" style="display:inline;"><input type="hidden" name="feedback_id" value="<?php echo $fb['id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><select name="status" onchange="this.form.submit()" style="padding:4px; border-radius:8px; background:rgba(255,255,255,0.1); color:#fff; border:1px solid rgba(64,224,208,0.3); font-size:0.7rem;"><option value="pending" <?php echo $fb['status']=='pending'?'selected':''; ?>>Pending</option><option value="approved" <?php echo $fb['status']=='approved'?'selected':''; ?>>Approved</option><option value="rejected" <?php echo $fb['status']=='rejected'?'selected':''; ?>>Rejected</option></select><input type="hidden" name="update_feedback" value="1"></form>
                                    <button class="btn btn-primary btn-sm" onclick="openReplyModal(<?php echo $fb['id']; ?>, '<?php echo addslashes($fb['name']); ?>')"><i class="fas fa-reply"></i> Reply</button>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this feedback permanently? This will also delete any reply.');"><input type="hidden" name="feedback_id" value="<?php echo $fb['id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><button type="submit" name="delete_feedback" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button></form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div style="text-align: center; margin-top: 1rem; margin-bottom: 1rem;">
                <a href="<?php echo APP_URL; ?>/pages/logout.php" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <div id="bookingModal" class="modal">
        <div class="modal-content glass-panel">
            <div class="modal-header"><h3><i class="fas fa-calendar-check"></i> Booking Details</h3><span class="modal-close" onclick="closeBookingModal()">&times;</span></div>
            <div class="modal-body" id="bookingModalBody"></div>
            <div class="modal-footer"><button class="btn btn-outline" onclick="closeBookingModal()">Close</button></div>
        </div>
    </div>

    <div id="reportModal" class="modal">
        <div class="modal-content glass-panel report-modal">
            <div class="modal-header"><h3><i class="fas fa-chart-line"></i> Generate Report</h3><span class="modal-close" onclick="closeReportModal()">&times;</span></div>
            <div class="modal-body">
                <form id="reportForm" method="get" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group"><label for="report_type">Report Type</label><select name="report_type" id="report_type" class="form-control" required><option value="bookings">Bookings Report</option><option value="payments">Payments Report</option><option value="vehicles">Vehicles Report</option><option value="users">Users Report</option></select></div>
                    <div class="form-group"><label for="from_date">From Date (optional)</label><input type="date" name="from_date" id="from_date" class="form-control"></div>
                    <div class="form-group"><label for="to_date">To Date (optional)</label><input type="date" name="to_date" id="to_date" class="form-control"></div>
                    <div class="form-group"><label><input type="checkbox" id="use_date_range"> Filter by date range</label></div>
                </form>
            </div>
            <div class="modal-footer"><button class="btn btn-outline" onclick="closeReportModal()">Cancel</button><button class="btn btn-primary" onclick="generateReport()"><i class="fas fa-download"></i> Download CSV</button></div>
        </div>
    </div>

    <div id="replyModal" class="modal">
        <div class="modal-content glass-panel">
            <div class="modal-header"><h3><i class="fas fa-reply"></i> Reply to Customer</h3><span class="modal-close" onclick="closeReplyModal()">&times;</span></div>
            <div class="modal-body">
                <p><strong>Customer:</strong> <span id="replyCustomerName"></span></p>
                <div class="form-group"><label for="replyMessage">Your Reply</label><textarea id="replyMessage" class="form-control" rows="4" placeholder="Type your reply here..."></textarea></div>
                <input type="hidden" id="replyFeedbackId">
            </div>
            <div class="modal-footer"><button class="btn btn-outline" onclick="closeReplyModal()">Cancel</button><button class="btn btn-primary" onclick="sendReply()"><i class="fas fa-paper-plane"></i> Send Reply</button></div>
        </div>
    </div>

    <!-- Vehicle Add/Edit Modal (updated for discount percent) -->
    <div id="vehicleModal" class="modal">
        <div class="modal-content glass-panel">
            <div class="modal-header"><h3 id="modalTitle">Edit Vehicle</h3><span class="modal-close" onclick="closeModal()">&times;</span></div>
            <div class="modal-body">
                <form id="vehicleForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="vehicle_id" id="vehicle_id" value="0">
                    <input type="hidden" name="existing_image" id="existing_image" value="">
                    <input type="hidden" name="images_json" id="images_json" value="[]">
                    <div class="form-grid">
                        <div class="form-group"><label for="registration_number">Registration *</label><input type="text" id="registration_number" name="registration_number" required></div>
                        <div class="form-group"><label for="make">Make *</label><input type="text" id="make" name="make" required></div>
                        <div class="form-group"><label for="model">Model *</label><input type="text" id="model" name="model" required></div>
                        <div class="form-group"><label for="year">Year *</label><input type="number" id="year" name="year" min="2000" max="2026" required></div>
                        <div class="form-group"><label for="color">Color *</label><input type="text" id="color" name="color" required></div>
                        <div class="form-group"><label for="vehicle_type">Type *</label><select id="vehicle_type" name="vehicle_type" required><option value="">Select</option><option value="economy">Economy</option><option value="compact">Compact</option><option value="sedan">Sedan</option><option value="suv">SUV</option><option value="luxury">Luxury</option><option value="van">Van</option><option value="truck">Truck</option></select></div>
                        <div class="form-group"><label for="fuel_type">Fuel *</label><select id="fuel_type" name="fuel_type" required><option value="petrol">Petrol</option><option value="diesel">Diesel</option><option value="hybrid">Hybrid</option><option value="electric">Electric</option></select></div>
                        <div class="form-group"><label for="transmission">Transmission *</label><select id="transmission" name="transmission" required><option value="manual">Manual</option><option value="automatic">Automatic</option></select></div>
                        <div class="form-group"><label for="seating_capacity">Seats *</label><input type="number" id="seating_capacity" name="seating_capacity" min="2" max="50" required></div>
                        <div class="form-group"><label for="mileage">Mileage (km) *</label><input type="number" id="mileage" name="mileage" min="0" step="100" required></div>
                        <div class="form-group"><label for="daily_rate">Daily Rate (KES) *</label><input type="number" id="daily_rate" name="daily_rate" min="500" step="100" required></div>
                        <div class="form-group"><label for="discount_percent">Discount (%)</label><input type="number" id="discount_percent" name="discount_percent" min="0" max="100" step="1" value="0"><small style="color:rgba(255,255,255,0.6);">Enter percentage discount (e.g., 10 for 10% off)</small></div>
                        <div class="form-group"><label for="weekly_rate">Weekly Rate (KES)</label><input type="number" id="weekly_rate" name="weekly_rate" min="0" step="100"></div>
                        <div class="form-group"><label for="monthly_rate">Monthly Rate (KES)</label><input type="number" id="monthly_rate" name="monthly_rate" min="0" step="100"></div>
                        <div class="form-group"><label for="county">County *</label><select id="county" name="county" required><option value="">Select</option><?php foreach ($KENYA_COUNTIES as $key => $county): ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($county); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label for="location">Location</label><input type="text" id="location" name="location" placeholder="e.g., Westlands"></div>
                        <div class="form-group"><label for="condition">Condition *</label><select id="condition" name="condition" required><option value="excellent">Excellent</option><option value="good">Good</option><option value="fair">Fair</option><option value="poor">Poor</option></select></div>
                        <div class="form-group full-width"><label for="features">Features (comma separated)</label><textarea id="features" name="features" rows="2"></textarea></div>
                        <div class="form-group full-width"><label for="description">Description</label><textarea id="description" name="description" rows="2"></textarea></div>
                        <!-- Multiple image upload -->
                        <div class="form-group full-width"><label for="vehicle_images">Upload Images (select multiple, up to 5 or more)</label><input type="file" id="vehicle_images" name="vehicle_images[]" multiple accept="image/*"><small style="color:rgba(255,255,255,0.6);">JPG, PNG, GIF up to 30MB each</small></div>
                        <div class="form-group full-width" id="current_image_container" style="display:none;"><label>Current Images</label><div id="current_images_list" style="display:flex; flex-wrap:wrap; gap:8px;"></div><label><input type="checkbox" name="remove_image" value="1"> Remove all images</label></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer"><button class="btn btn-outline" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="saveVehicle()">Save Vehicle</button></div>
        </div>
    </div>

    <!-- Image Manager Modal -->
    <div id="imageManagerModal" class="modal">
        <div class="modal-content glass-panel" style="max-width:600px;">
            <div class="modal-header"><h3>Manage Vehicle Images</h3><span class="modal-close" onclick="closeImageManager()">&times;</span></div>
            <div class="modal-body">
                <div id="currentImagesList" class="row mb-3"></div>
                <hr>
                <form id="multiImageForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="vehicle_id" id="imgManagerVehicleId">
                    <div class="form-group">
                        <label>Add more images (select multiple)</label>
                        <input type="file" name="vehicle_images[]" multiple accept="image/*" class="form-control-file">
                    </div>
                    <button type="button" class="btn btn-primary" onclick="uploadMultipleImages()">Upload Images</button>
                </form>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-content glass-panel" style="max-width:400px;">
            <div class="modal-header"><h3>Confirm Delete</h3><span class="modal-close" onclick="closeDeleteModal()">&times;</span></div>
            <div class="modal-body"><p>Are you sure you want to delete this vehicle? This action cannot be undone.</p><input type="hidden" id="delete_id"></div>
            <div class="modal-footer"><button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button><button class="btn btn-danger" onclick="confirmDelete()">Delete</button></div>
        </div>
    </div>

    <input type="file" id="imageUploadInput" style="display:none;" accept="image/*">

    <script>
        // Existing functions (viewBookingDetails, closeBookingModal, markBookingCompleted, returnVehicle, filterBookings, openReplyModal, closeReplyModal, sendReply, openReportModal, closeReportModal, generateReport, showToast, updateStats, filterByStatus, filterVehicles, openAddModal, closeModal, closeDeleteModal, editVehicle, saveVehicle, deleteVehicle, confirmDelete, window.onclick, etc.)
        // We will keep them all, but update editVehicle to load discount_percent and images.

        function viewBookingDetails(id, ref, customer, email, phone, vehicle, pickup, returnDate, amount, status, paymentStatus) {
            const modalBody = document.getElementById('bookingModalBody');
            modalBody.innerHTML = `
                <p><strong>Booking ID:</strong> ${id}</p>
                <p><strong>Reference:</strong> ${ref}</p>
                <p><strong>Customer:</strong> ${customer}</p>
                <p><strong>Email:</strong> ${email}</p>
                <p><strong>Phone:</strong> ${phone || '—'}</p>
                <p><strong>Vehicle:</strong> ${vehicle}</p>
                <p><strong>Pickup Date:</strong> ${pickup}</p>
                <p><strong>Return Date:</strong> ${returnDate}</p>
                <p><strong>Total Amount:</strong> <?php echo $settings['currency']; ?> ${parseFloat(amount).toFixed(2)}</p>
                <p><strong>Status:</strong> <span class="badge ${status === 'pending' ? 'badge-pending' : (status === 'completed' ? 'badge-paid' : 'badge-cancelled')}">${status}</span></p>
                <p><strong>Payment Status:</strong> <span class="badge ${paymentStatus === 'paid' ? 'badge-paid' : 'badge-pending'}">${paymentStatus}</span></p>
            `;
            document.getElementById('bookingModal').style.display = 'block';
        }
        function closeBookingModal() { document.getElementById('bookingModal').style.display = 'none'; }

        function markBookingCompleted(bookingId) {
            if (!confirm('Mark this booking as completed? This will update the booking status.')) return;
            const formData = new URLSearchParams();
            formData.append('action', 'update_booking_status');
            formData.append('booking_id', bookingId);
            formData.append('status', 'completed');
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
            fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Success', 'Booking marked as completed!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Error', data.message || 'Failed to update status.', 'error');
                }
            })
            .catch(err => { console.error(err); showToast('Error', 'Network error: ' + err.message, 'error'); });
        }

        function returnVehicle(bookingId, vehicleId) {
            if (!confirm('Mark this vehicle as returned? This will update the vehicle status to available and the booking to completed.')) return;
            const formData = new URLSearchParams();
            formData.append('action', 'return_vehicle');
            formData.append('booking_id', bookingId);
            formData.append('vehicle_id', vehicleId);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
            fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Success', data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Error', data.message || 'Failed to return vehicle.', 'error');
                }
            })
            .catch(err => { console.error(err); showToast('Error', 'Network error: ' + err.message, 'error'); });
        }

        function filterBookings() {
            const search = document.getElementById('bookingSearch')?.value.toLowerCase() || '';
            const status = document.getElementById('bookingStatusFilter')?.value || '';
            const rows = document.querySelectorAll('#bookingsTable tbody tr');
            rows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                const rowSearch = row.getAttribute('data-search');
                const matchesSearch = search === '' || rowSearch.includes(search);
                const matchesStatus = status === '' || rowStatus === status;
                row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
            });
        }

        function openReplyModal(feedbackId, customerName) {
            document.getElementById('replyFeedbackId').value = feedbackId;
            document.getElementById('replyCustomerName').innerText = customerName;
            document.getElementById('replyMessage').value = '';
            document.getElementById('replyModal').style.display = 'block';
        }
        function closeReplyModal() { document.getElementById('replyModal').style.display = 'none'; }
        function sendReply() {
            const feedbackId = document.getElementById('replyFeedbackId').value;
            const reply = document.getElementById('replyMessage').value.trim();
            if (!reply) { showToast('Error', 'Please enter a reply message.', 'error'); return; }
            const formData = new URLSearchParams();
            formData.append('action', 'send_reply');
            formData.append('feedback_id', feedbackId);
            formData.append('reply', reply);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
            fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) { showToast('Success', 'Reply sent successfully!', 'success'); closeReplyModal(); setTimeout(() => location.reload(), 1500); }
                else { showToast('Error', data.message || 'Failed to send reply.', 'error'); }
            })
            .catch(error => { console.error('Reply error:', error); showToast('Error', 'Network error: ' + error.message, 'error'); });
        }

        function openReportModal(presetType) { if (presetType) document.getElementById('report_type').value = presetType; document.getElementById('reportModal').style.display = 'block'; }
        function closeReportModal() { document.getElementById('reportModal').style.display = 'none'; }
        function generateReport() {
            const reportType = document.getElementById('report_type').value;
            const useDateRange = document.getElementById('use_date_range').checked;
            let fromDate = '', toDate = '';
            if (useDateRange) {
                fromDate = document.getElementById('from_date').value;
                toDate = document.getElementById('to_date').value;
                if (!fromDate || !toDate) { showToast('Error', 'Please select both from and to dates for date range filter.', 'error'); return; }
            }
            let url = '?generate_report=1&report_type=' + encodeURIComponent(reportType);
            if (fromDate) url += '&from_date=' + encodeURIComponent(fromDate);
            if (toDate) url += '&to_date=' + encodeURIComponent(toDate);
            window.location.href = url;
            closeReportModal();
        }

        function showToast(title, message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i><div class="toast-content"><h4>${title}</h4><p>${message}</p></div><i class="fas fa-times toast-close" onclick="this.parentElement.remove()"></i>`;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 5000);
        }

        function updateStats(stats) {
            document.getElementById('totalVehicles').innerText = stats.total || 0;
            document.getElementById('availableVehicles').innerText = stats.by_status?.available || 0;
            document.getElementById('rentedVehicles').innerText = stats.by_status?.rented || 0;
            let avgRate = parseFloat(stats.avg_daily_rate);
            if (isNaN(avgRate)) avgRate = 0;
            document.getElementById('avgRate').innerText = '<?php echo $settings['currency']; ?> ' + avgRate.toFixed(2);
        }

        function filterByStatus(status) {
            const buttons = document.querySelectorAll('.filter-btn');
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            const cards = document.querySelectorAll('.vehicle-card');
            cards.forEach(card => { if (status === 'all' || card.dataset.status === status) card.style.display = ''; else card.style.display = 'none'; });
        }

        function filterVehicles() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            const cards = document.querySelectorAll('.vehicle-card');
            cards.forEach(card => {
                const cardStatus = card.dataset.status;
                const cardType = card.dataset.type;
                const cardSearch = card.dataset.search;
                const matchesSearch = search === '' || cardSearch.includes(search);
                const matchesStatus = status === '' || cardStatus === status;
                const matchesType = type === '' || cardType === type;
                card.style.display = (matchesSearch && matchesStatus && matchesType) ? '' : 'none';
            });
        }

        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Add New Vehicle';
            document.getElementById('vehicleForm').reset();
            document.getElementById('vehicle_id').value = '0';
            document.getElementById('existing_image').value = '';
            document.getElementById('images_json').value = '[]';
            document.getElementById('discount_percent').value = '0';
            document.getElementById('current_image_container').style.display = 'none';
            document.getElementById('vehicleModal').style.display = 'block';
        }
        function closeModal() { document.getElementById('vehicleModal').style.display = 'none'; }
        function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }

        // FIXED: editVehicle now loads discount_percent and images
        function editVehicle(id) {
            showToast('Loading', 'Fetching vehicle data...', 'info');
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'get',
                    vehicle_id: id,
                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                })
            })
            .then(async response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch(e) {
                    console.error('Invalid JSON:', text);
                    throw new Error('Server returned invalid JSON. Check PHP error logs.');
                }
            })
            .then(data => {
                if (data.error) {
                    showToast('Error', data.message || 'Vehicle not found', 'error');
                    return;
                }
                if (!data || !data.id) {
                    showToast('Error', 'No vehicle data received', 'error');
                    return;
                }
                // Populate form fields
                document.getElementById('modalTitle').innerText = 'Edit Vehicle';
                document.getElementById('vehicle_id').value = data.id;
                document.getElementById('registration_number').value = data.registration_number || '';
                document.getElementById('make').value = data.make || '';
                document.getElementById('model').value = data.model || '';
                document.getElementById('year').value = data.year || '';
                document.getElementById('color').value = data.color || '';
                document.getElementById('vehicle_type').value = data.vehicle_type || '';
                document.getElementById('fuel_type').value = data.fuel_type || 'petrol';
                document.getElementById('transmission').value = data.transmission || 'automatic';
                document.getElementById('seating_capacity').value = data.seating_capacity || 5;
                document.getElementById('mileage').value = data.mileage || 0;
                document.getElementById('daily_rate').value = data.daily_rate || '';
                document.getElementById('discount_percent').value = data.discount_percent || 0;
                document.getElementById('weekly_rate').value = data.weekly_rate || '';
                document.getElementById('monthly_rate').value = data.monthly_rate || '';
                document.getElementById('county').value = data.county || '';
                document.getElementById('location').value = data.location || '';
                document.getElementById('condition').value = data.condition || 'good';
                document.getElementById('features').value = data.features || '';
                document.getElementById('description').value = data.description || '';
                // Load images
                let images = [];
                if (data.images) {
                    try { images = JSON.parse(data.images); } catch(e) { images = []; }
                } else if (data.image_url) {
                    images = [data.image_url];
                }
                document.getElementById('images_json').value = JSON.stringify(images);
                // Render preview
                renderImagePreview(images);
                document.getElementById('current_image_container').style.display = images.length ? 'block' : 'none';
                document.getElementById('vehicleModal').style.display = 'block';
            })
            .catch(error => {
                console.error('Edit error:', error);
                showToast('Error', 'Could not load vehicle data: ' + error.message, 'error');
            });
        }

        function renderImagePreview(images) {
            const container = document.getElementById('current_images_list');
            if (!container) return;
            container.innerHTML = '';
            if (images.length === 0) {
                container.innerHTML = '<p>No images.</p>';
                return;
            }
            images.forEach((img, idx) => {
                const div = document.createElement('div');
                div.style.position = 'relative';
                div.style.display = 'inline-block';
                div.style.margin = '4px';
                div.innerHTML = `
                    <img src="<?php echo APP_URL; ?>/${img}" style="height:70px; width:70px; object-fit:cover; border-radius:6px;">
                    <br>
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeImageByUrl('${img}')">Remove</button>
                `;
                container.appendChild(div);
            });
        }

        function removeImageByUrl(imgUrl) {
            let current = document.getElementById('images_json').value;
            let images = JSON.parse(current);
            images = images.filter(i => i !== imgUrl);
            document.getElementById('images_json').value = JSON.stringify(images);
            renderImagePreview(images);
            if (images.length === 0) {
                document.getElementById('current_image_container').style.display = 'none';
            }
            showToast('Removed', 'Image removed from list', 'info');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const removeAllCheckbox = document.querySelector('input[name="remove_image"]');
            if (removeAllCheckbox) {
                removeAllCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        document.getElementById('images_json').value = '[]';
                        renderImagePreview([]);
                        document.getElementById('current_image_container').style.display = 'none';
                        showToast('Cleared', 'All images will be removed on save', 'info');
                    }
                });
            }
        });

        function saveVehicle() {
            const form = document.getElementById('vehicleForm');
            const vehicleId = document.getElementById('vehicle_id').value;
            const isEdit = vehicleId !== '0';
            const saveBtn = document.querySelector('#vehicleModal .btn-primary');
            if (!form.checkValidity()) { form.reportValidity(); return; }
            const dailyRate = parseFloat(document.getElementById('daily_rate').value);
            if (dailyRate < 500) { showToast('Validation Error', 'Daily rate must be at least Ksh 500', 'error'); return; }
            const originalText = saveBtn.innerText;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            const formData = new FormData(form);
            if (isEdit) { formData.append('action', 'update'); formData.append('vehicle_id', vehicleId); }
            else { formData.append('action', 'add'); }
            fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(async response => { if (!response.ok) throw new Error(`HTTP error ${response.status}`); const text = await response.text(); try { return JSON.parse(text); } catch (e) { throw new Error('Server returned invalid JSON'); } })
                .then(data => {
                    if (data.success) { showToast('🎉 Success', data.message, 'success'); closeModal(); if (data.stats) updateStats(data.stats); setTimeout(() => location.reload(), 1500); }
                    else { showToast('❌ Error', data.message || 'Operation failed', 'error'); saveBtn.disabled = false; saveBtn.innerHTML = originalText; }
                }).catch(error => { console.error('AJAX error:', error); showToast('⚠️ Network Error', error.message, 'error'); saveBtn.disabled = false; saveBtn.innerHTML = originalText; });
        }

        function deleteVehicle(id) { document.getElementById('delete_id').value = id; document.getElementById('deleteModal').style.display = 'block'; }
        function confirmDelete() {
            const id = document.getElementById('delete_id').value;
            fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'delete', vehicle_id: id, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>' }) })
            .then(res => res.json()).then(data => {
                if (data.success) { showToast('Success', data.message, 'success'); closeDeleteModal(); if (data.stats) updateStats(data.stats); setTimeout(() => location.reload(), 1500); }
                else { showToast('Error', data.message, 'error'); closeDeleteModal(); }
            }).catch(error => { console.error('Delete error:', error); showToast('Error', 'Delete failed: ' + error.message, 'error'); closeDeleteModal(); });
        }

        // Image Manager functions
        let currentVehicleId = 0;
        function openImageManager(vehicleId) {
            currentVehicleId = vehicleId;
            document.getElementById('imgManagerVehicleId').value = vehicleId;
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'get',
                    vehicle_id: vehicleId,
                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data && !data.error) {
                    let images = [];
                    if (data.images) try { images = JSON.parse(data.images); } catch(e) {}
                    else if (data.image_url) images = [data.image_url];
                    const container = document.getElementById('currentImagesList');
                    if (!container) return;
                    if (images.length === 0) {
                        container.innerHTML = '<p>No images yet. Upload some below.</p>';
                    } else {
                        let html = '';
                        images.forEach((img, idx) => {
                            html += `
                                <div class="col-md-4 mb-2">
                                    <img src="<?php echo APP_URL; ?>/${img}" class="img-fluid rounded" style="height:90px; object-fit:cover;">
                                    <button class="btn btn-danger btn-sm mt-1" onclick="removeImageFromManager(${idx}, '${img}')">Remove</button>
                                </div>
                            `;
                        });
                        container.innerHTML = html;
                    }
                    document.getElementById('imageManagerModal').style.display = 'block';
                } else {
                    showToast('Error', 'Could not load images', 'error');
                }
            });
        }
        function closeImageManager() { document.getElementById('imageManagerModal').style.display = 'none'; }
        function removeImageFromManager(index, imgUrl) {
            if (!confirm('Remove this image?')) return;
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'get',
                    vehicle_id: currentVehicleId,
                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                })
            })
            .then(res => res.json())
            .then(data => {
                let images = [];
                if (data.images) try { images = JSON.parse(data.images); } catch(e) {}
                else if (data.image_url) images = [data.image_url];
                images = images.filter(img => img !== imgUrl);
                const formData = new URLSearchParams();
                formData.append('action', 'update');
                formData.append('vehicle_id', currentVehicleId);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
                formData.append('images_json', JSON.stringify(images));
                fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast('Removed', 'Image deleted', 'success');
                        openImageManager(currentVehicleId);
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast('Error', res.message, 'error');
                    }
                });
            });
        }
        function uploadMultipleImages() {
            const formData = new FormData(document.getElementById('multiImageForm'));
            formData.append('upload_multiple_images', '1');
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Uploaded', data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Error', data.message, 'error');
                }
            });
        }

        window.onclick = function(event) { if (event.target.classList.contains('modal')) event.target.style.display = 'none'; }
        document.getElementById('use_date_range')?.addEventListener('change', function() { const fromDate = document.getElementById('from_date'); const toDate = document.getElementById('to_date'); fromDate.disabled = !this.checked; toDate.disabled = !this.checked; });
        document.getElementById('from_date').disabled = true; document.getElementById('to_date').disabled = true;
    </script>
</body>
</html>
<?php $conn->close(); ?>

