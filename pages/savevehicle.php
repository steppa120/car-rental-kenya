<?php
/**
 * savevehicle.php
 * Handles AJAX requests for vehicle management (add, update, delete, get)
 */

// Ensure absolutely nothing is output before the JSON header
if (ob_get_level()) ob_clean();
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0); // Do not display errors to the client – they break JSON
ob_start();

try {
    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: '');
    define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');

    // Database connection
require_once __DIR__ . '/../config/db.php';
    $conn = db_connect();
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");

    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // ------------------------------------------------------------------
    // Helper functions
    // ------------------------------------------------------------------
    function is_admin() {
        return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    }

    function sanitize($data) {
        global $conn;
        return $conn->real_escape_string(trim(htmlspecialchars($data)));
    }

    // ------------------------------------------------------------------
    // Security: Only allow logged in admin
    // ------------------------------------------------------------------
    if (!is_admin()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // ------------------------------------------------------------------
    // CSRF check
    // ------------------------------------------------------------------
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    // ================================================================
    // Vehicle Class – Integrated (with SQL fixes)
    // ================================================================
    class Vehicle {
        private $conn;

        public function __construct($db) {
            $this->conn = $db;
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
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->fetch_assoc();
        }

        public function addVehicle($data) {
            // Using backticks around `condition` because it's a reserved keyword
            $sql = "INSERT INTO vehicles (
                        registration_number, make, model, year, color, vehicle_type,
                        fuel_type, transmission, seating_capacity, mileage, daily_rate,
                        weekly_rate, monthly_rate, location, county, latitude, longitude,
                        `condition`, features, description, image_url, status, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', NOW()
                    )";

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return false;
            }

            // Correct type string: 21 characters (all fields except status and created_at)
            $stmt->bind_param(
                "sssissssiidddssddssss",  // <-- FIXED: removed one extra 's' at the end
                $data['registration_number'],
                $data['make'],
                $data['model'],
                $data['year'],
                $data['color'],
                $data['vehicle_type'],
                $data['fuel_type'],
                $data['transmission'],
                $data['seating_capacity'],
                $data['mileage'],
                $data['daily_rate'],
                $data['weekly_rate'],
                $data['monthly_rate'],
                $data['location'],
                $data['county'],
                $data['latitude'],
                $data['longitude'],
                $data['condition'],
                $data['features'],
                $data['description'],
                $data['image_url']
            );

            return $stmt->execute();
        }

        public function updateVehicle($id, $data) {
            $sql = "UPDATE vehicles SET
                        registration_number = ?,
                        make = ?,
                        model = ?,
                        year = ?,
                        color = ?,
                        vehicle_type = ?,
                        fuel_type = ?,
                        transmission = ?,
                        seating_capacity = ?,
                        mileage = ?,
                        daily_rate = ?,
                        weekly_rate = ?,
                        monthly_rate = ?,
                        location = ?,
                        county = ?,
                        latitude = ?,
                        longitude = ?,
                        `condition` = ?,
                        features = ?,
                        description = ?,
                        image_url = ?
                    WHERE id = ?";

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return false;
            }

            // Correct type string: 21 data types + 1 i for id = 22 characters
            $stmt->bind_param(
                "sssissssiidddssddssssi",  // <-- FIXED: removed one extra 's' before the final 'i'
                $data['registration_number'],
                $data['make'],
                $data['model'],
                $data['year'],
                $data['color'],
                $data['vehicle_type'],
                $data['fuel_type'],
                $data['transmission'],
                $data['seating_capacity'],
                $data['mileage'],
                $data['daily_rate'],
                $data['weekly_rate'],
                $data['monthly_rate'],
                $data['location'],
                $data['county'],
                $data['latitude'],
                $data['longitude'],
                $data['condition'],
                $data['features'],
                $data['description'],
                $data['image_url'],
                $id
            );

            return $stmt->execute();
        }

        public function deleteVehicle($id) {
            $stmt = $this->conn->prepare("DELETE FROM vehicles WHERE id = ?");
            $stmt->bind_param("i", $id);
            return $stmt->execute();
        }

        public function getVehicleStats() {
            $stats = [
                'total' => 0,
                'by_status' => ['available' => 0, 'rented' => 0, 'maintenance' => 0, 'damaged' => 0],
                'avg_daily_rate' => 0
            ];

            $total = $this->conn->query("SELECT COUNT(*) as count FROM vehicles");
            if ($total) {
                $stats['total'] = $total->fetch_assoc()['count'];
            }

            $statusCounts = $this->conn->query("SELECT status, COUNT(*) as count FROM vehicles GROUP BY status");
            if ($statusCounts) {
                while ($row = $statusCounts->fetch_assoc()) {
                    $stats['by_status'][$row['status']] = $row['count'];
                }
            }

            $avg = $this->conn->query("SELECT AVG(daily_rate) as avg_rate FROM vehicles");
            if ($avg) {
                $stats['avg_daily_rate'] = $avg->fetch_assoc()['avg_rate'] ?? 0;
            }

            return $stats;
        }
    }

    // Instantiate Vehicle class
    $vehicle = new Vehicle($conn);

    // ------------------------------------------------------------------
    // Process actions
    // ------------------------------------------------------------------
    if (!isset($_POST['action'])) {
        echo json_encode(['success' => false, 'message' => 'No action specified']);
        exit;
    }

    $action = $_POST['action'];
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);

    // ---------- DELETE ----------
    if ($action === 'delete' && $vehicle_id > 0) {
        $result = $vehicle->deleteVehicle($vehicle_id);
        echo json_encode(['success' => $result, 'message' => $result ? 'Vehicle deleted' : 'Delete failed']);
        exit;
    }

    // ---------- GET (for editing) ----------
    if ($action === 'get' && $vehicle_id > 0) {
        $data = $vehicle->getVehicleById($vehicle_id);
        echo json_encode($data);
        exit;
    }

    // ---------- UPDATE ----------
    if ($action === 'update') {
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
        ];

        if ($vehicle->updateVehicle($vehicle_id, $data)) {
            echo json_encode(['success' => true, 'message' => 'Vehicle updated']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed']);
        }
        exit;
    }

    // ---------- ADD (with image upload) ----------
    if ($action === 'add') {
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
            'image_url'           => '',
        ];

        // Handle image upload
        if (isset($_FILES['vehicle_image']) && $_FILES['vehicle_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['vehicle_image'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) && $file['size'] <= 5 * 1024 * 1024) {
                $new_filename = uniqid('vehicle_') . '.' . $ext;
                $upload_path = __DIR__ . '/../uploads/vehicle_images/' . $new_filename;
                if (!is_dir(__DIR__ . '/../uploads/vehicle_images/')) {
                    mkdir(__DIR__ . '/../uploads/vehicle_images/', 0777, true);
                }
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $data['image_url'] = 'uploads/vehicle_images/' . $new_filename;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to upload image – check folder permissions']);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid image file. Only JPG, PNG, GIF up to 5MB allowed.']);
                exit;
            }
        }

        if ($vehicle->addVehicle($data)) {
            echo json_encode(['success' => true, 'message' => 'Vehicle added successfully']);
        } else {
            $error_message = 'Failed to add vehicle';
            if (isset($conn->error) && !empty($conn->error)) {
                $error_message .= ': ' . $conn->error;
            } else {
                error_log("Vehicle add failed with no specific error from DB.");
                $error_message .= '. Check PHP error log for details.';
            }
            echo json_encode(['success' => false, 'message' => $error_message]);
        }
        exit;
    }

    // If we reach here, action was invalid
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;

} catch (Throwable $e) {
    // Catch any error or exception and return it as JSON for debugging
    error_log("savevehicle.php fatal error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    // Clear any output that might have been sent
    if (ob_get_level()) ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
    ]);
    exit;
}
