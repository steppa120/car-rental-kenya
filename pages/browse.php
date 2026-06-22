<?php
/**
 * ============================================================
 * FleetKE – Combined Single‑File Website (Router)
 * ============================================================
 * Now reads contact info, site settings, features, and LOGO from DB.
 * DISPLAYS FEATURED VEHICLES (is_featured = 1) ON HOME PAGE.
 * ADDED: Admin view‑only mode – booking buttons are completely removed.
 * ADDED: Horizontal 3D flip (rotateY) on hover for all vehicle images.
 * ADDED: Raindrops glassmorphism effect in background.
 * ADDED: Slideshow (carousel) for multiple vehicle images.
 * ADDED: Site name displays in static rainbow multicolor gradient (no animation).
 * UPDATED: Google Map location changed to Safari Park, Nairobi.
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ------------------------------------------------------------------
// Block POST requests from admin view‑only mode (optional)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('is_admin_view_only') && is_admin_view_only()) {
    if (isset($_POST['book_vehicle']) || isset($_POST['confirm_booking'])) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Admins cannot book vehicles in view‑only mode.'];
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?page=home'));
        exit();
    }
}

// ------------------------------------------------------------------
// Configuration & Database Connection
// ------------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');
define('APP_URL', getenv('APP_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: 'http://localhost/car-rental-kenya')); // kept for absolute URLs (images, etc.)

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

// ------------------------------------------------------------------
// Helper Functions
// ------------------------------------------------------------------
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if current user is an admin in view‑only mode.
 */
function is_admin_view_only() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'
           && isset($_SESSION['admin_view_only']) && $_SESSION['admin_view_only'] === true;
}

function format_currency($amount) {
    return 'Ksh ' . number_format($amount, 2);
}

/**
 * Get a setting value from the settings table
 */
function get_setting($key, $default = '') {
    global $conn;
    static $settings_cache = null;
    if ($settings_cache === null) {
        $settings_cache = [];
        $result = $conn->query("SELECT setting_key, setting_value FROM settings");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings_cache[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
    return isset($settings_cache[$key]) ? $settings_cache[$key] : $default;
}

/**
 * Get features list from settings
 */
function get_features() {
    global $conn;
    static $features_cache = null;
    if ($features_cache === null) {
        $result = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'features_list'");
        if ($result && $result->num_rows) {
            $features_json = $result->fetch_assoc()['setting_value'];
            $features_cache = json_decode($features_json, true);
            if (!is_array($features_cache)) $features_cache = [];
        } else {
            $features_cache = [];
        }
    }
    return $features_cache;
}

/**
 * Display vehicle images as a carousel (slideshow) if multiple images exist,
 * otherwise fallback to a single image or icon.
 */
function vehicle_image_slideshow($vehicle, $app_url) {
    $images = [];
    if (!empty($vehicle['images'])) {
        $decoded = json_decode($vehicle['images'], true);
        if (is_array($decoded)) $images = $decoded;
    }
    // Fallback to single image_url
    if (empty($images) && !empty($vehicle['image_url'])) {
        $images = [$vehicle['image_url']];
    }
    if (empty($images)) {
        return '<i class="fas fa-car" style="font-size:4rem; color:#9aa0a6;"></i>';
    }
    // If only one image, display it without carousel controls
    if (count($images) === 1) {
        $img_path = trim($images[0], '/');
        $src = $app_url . '/' . $img_path;
        return '<img src="' . htmlspecialchars($src) . '" 
                      alt="' . htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) . '"
                      style="width:100%; height:100%; object-fit:cover;">';
    }
    // Build Bootstrap carousel
    $indicators = '';
    $slides = '';
    foreach ($images as $idx => $img) {
        $img_path = trim($img, '/');
        $src = $app_url . '/' . $img_path;
        $active = $idx === 0 ? 'active' : '';
        $indicators .= "<li data-target='#carousel-{$vehicle['id']}' data-slide-to='{$idx}' class='{$active}'></li>";
        $slides .= "<div class='carousel-item {$active}' style='height:200px;'>
                        <img src='" . htmlspecialchars($src) . "' class='d-block w-100' style='height:100%; object-fit:cover;' alt='Slide " . ($idx+1) . "'>
                    </div>";
    }
    return "
    <div id='carousel-{$vehicle['id']}' class='carousel slide' data-ride='carousel' data-interval='4000'>
        <ol class='carousel-indicators' style='bottom:5px;'>{$indicators}</ol>
        <div class='carousel-inner' style='height:200px;'>{$slides}</div>
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

// ------------------------------------------------------------------
// Vehicle Class
// ------------------------------------------------------------------
class Vehicle {
    private $conn;
    public function __construct($db) { $this->conn = $db; }

    public function getAvailableVehicles($filters = []) {
        $sql = "SELECT * FROM vehicles WHERE status = 'available'";
        if (!empty($filters['county'])) {
            $sql .= " AND county = '" . $this->conn->real_escape_string($filters['county']) . "'";
        }
        if (!empty($filters['vehicle_type'])) {
            $sql .= " AND vehicle_type = '" . $this->conn->real_escape_string($filters['vehicle_type']) . "'";
        }
        if (!empty($filters['search'])) {
            $search = $this->conn->real_escape_string($filters['search']);
            $sql .= " AND (make LIKE '%$search%' OR model LIKE '%$search%')";
        }
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . intval($filters['limit']);
        }
        $result = $this->conn->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getFeaturedVehicles($limit = 6) {
        $sql = "SELECT * FROM vehicles WHERE is_featured = 1 AND status = 'available' ORDER BY id DESC LIMIT " . intval($limit);
        $result = $this->conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getVehicleStats() {
        $stats = ['total' => 0, 'by_status' => [], 'avg_daily_rate' => 0];
        $res = $this->conn->query("SELECT COUNT(*) as total FROM vehicles");
        if ($res) $stats['total'] = $res->fetch_assoc()['total'];

        $res = $this->conn->query("SELECT status, COUNT(*) as count FROM vehicles GROUP BY status");
        while ($row = $res->fetch_assoc()) {
            $stats['by_status'][$row['status']] = $row['count'];
        }

        $res = $this->conn->query("SELECT AVG(daily_rate) as avg FROM vehicles WHERE status='available'");
        if ($res) $stats['avg_daily_rate'] = $res->fetch_assoc()['avg'] ?? 0;
        return $stats;
    }
}

$vehicle = new Vehicle($conn);

// ------------------------------------------------------------------
// Contact Form Handling
// ------------------------------------------------------------------
$contact_success = '';
$contact_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (empty($name) || empty($email) || empty($message)) {
        $contact_error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $contact_error = 'Invalid email address.';
    } else {
        $user_id = $_SESSION['user_id'] ?? null;
        $stmt = $conn->prepare("INSERT INTO feedback (user_id, name, email, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $user_id, $name, $email, $message);
        if ($stmt->execute()) {
            $contact_success = 'Thank you for your message. We\'ll get back to you soon.';
            $_POST = array();
        } else {
            $contact_error = 'Failed to send message. Please try again.';
        }
    }
}

// ------------------------------------------------------------------
// Routing – Determine which page to show
// ------------------------------------------------------------------
$page = isset($_GET['page']) ? $_GET['page'] : 'home';
$allowed_pages = ['home', 'vehicles', 'features', 'contact'];
if (!in_array($page, $allowed_pages)) {
    $page = 'home';
}

if ($page === 'vehicles') {
    $filters = [];
    if (isset($_GET['county'])) $filters['county'] = $_GET['county'];
    if (isset($_GET['vehicle_type'])) $filters['vehicle_type'] = $_GET['vehicle_type'];
    if (isset($_GET['search'])) $filters['search'] = $_GET['search'];
    $vehicles = $vehicle->getAvailableVehicles($filters);
    $stats = $vehicle->getVehicleStats();
}

if ($page === 'home') {
    $stats = $vehicle->getVehicleStats();
    $recent = $vehicle->getAvailableVehicles(['limit' => 6]);
    $featured_vehicles = $vehicle->getFeaturedVehicles(6);
}

// Fetch dynamic settings
$site_name = get_setting('site_name', 'FleetKE');
$site_logo = get_setting('site_logo', '');
$contact_email = get_setting('contact_email', 'info@fleetke.com');
$contact_phone = get_setting('contact_phone', '+254 700 000 000');
$contact_address = get_setting('contact_address', 'Westlands, Nairobi, Kenya');
$currency = get_setting('currency', 'Ksh');
$tax_rate = get_setting('tax_rate', 16);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_name); ?> - Car Rental Kenya</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Bootstrap CSS for carousel -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        /* ========== GLASSMORPHISM GLOBAL STYLES ========== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
            background: radial-gradient(circle at 10% 20%, rgba(9, 240, 78, 0.8), rgba(46, 73, 109, 0.95)), 
                        url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI4MCIgaGVpZ2h0PSI4MCIgdmlld0JveD0iMCAwIDQwIDQwIj48cGF0aCBmaWxsPSIjZmZmIiBmaWxsLW9wYWNpdHk9IjAuMDMiIGQ9Ik0wIDBoNDB2NDBIMHoiLz48cGF0aCBkPSJNMjAgMjBhMjAgMjAgMCAwIDEgMjAgMjAgMjAgMjAgMCAwIDEtNDAgMCAyMCAyMCAwIDAgMSAyMC0yMHoiIGZpbGw9IiNmZmYiIGZpbGwtb3BhY2l0eT0iMC4wNSIvPjwvc3ZnPg==');
            background-repeat: repeat;
            min-height: 100vh;
            line-height: 1.5;
            position: relative;
            overflow-x: hidden;
        }

        /* ========== RAINDROPS GLASSMORPHISM ========== */
        .raindrop {
            position: fixed;
            top: -20px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            backdrop-filter: blur(2px);
            animation: fall linear infinite;
        }

        @keyframes fall {
            0% {
                transform: translateY(-20px) translateX(0);
                opacity: 0.6;
            }
            100% {
                transform: translateY(100vh) translateX(20px);
                opacity: 0;
            }
        }

        /* ========== GLASS NAVBAR ========== */
        .navbar-top {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(213, 235, 135, 0.2);
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            z-index: 1500;
            padding: 0.75rem 2rem;
        }

        .navbar-content {
            max-width: 1700px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            font-size: 1.8rem;
            font-weight: 800;
            margin-right: 2rem;
        }

        /* Static rainbow multicolor text (no animation) */
        .multicolor-text {
            background: linear-gradient(90deg, #ff2400, #e81d1d, #e8b71d, #e3e81d, #1de840, #1ddde8, #2b1de8, #dd00f3, #dd00f3);
            background-size: 200% auto;
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent !important;
            display: inline-block;
            font-weight: inherit;
        }

        .navbar-brand img {
            max-height: 45px;
            width: auto;
            border-radius: 12px;
            filter: drop-shadow(0 2px 6px rgba(0,0,0,0.2));
        }

        .nav-links {
            display: flex;
            gap: 1.8rem;
            flex: 1;
            justify-content: center;
        }

        .nav-links a {
            text-decoration: none;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.9);
            transition: all 0.2s;
            padding: 0.5rem 0;
            border-bottom: 2px solid transparent;
            letter-spacing: 0.3px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: #FFE484;
            border-bottom-color: #FFD966;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .navbar-right span {
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
        }

        /* Glass Buttons */
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            backdrop-filter: blur(4px);
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.4);
            color: white;
        }

        .btn-primary {
            background: linear-gradient(115deg, rgba(100, 110, 250, 0.8), rgba(52, 180, 235, 0.8));
            border: 1px solid rgba(255,255,255,0.5);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            background: linear-gradient(115deg, rgba(130, 145, 255, 0.9), rgba(72, 200, 255, 0.9));
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.2);
        }

        .btn-outline {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.8);
        }

        .btn-sm {
            padding: 6px 16px;
            font-size: 0.85rem;
        }

        .main-container {
            max-width: 1400px;
            margin: 90px auto 0;
            padding: 2rem;
            position: relative;
            z-index: 1;
        }

        /* Glass Footer */
        .footer {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(12px);
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            color: rgba(255, 255, 255, 0.9);
            padding: 3rem 0;
            margin-top: 4rem;
            position: relative;
            z-index: 1;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 1rem;
        }

        .footer-links a {
            color: #FFE0A3;
            text-decoration: none;
            transition: 0.2s;
        }

        .footer-links a:hover {
            color: white;
        }

        /* Glass Hero */
        .hero {
            background: rgba(0, 0, 0, 0.25);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 48px;
            padding: 4rem 2rem;
            margin-bottom: 3rem;
            text-align: center;
            color: white;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }

        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .hero p {
            font-size: 1.2rem;
            opacity: 0.9;
        }

        .section-title {
            font-size: 2rem;
            margin: 2rem 0;
            color: white;
            text-shadow: 0 2px 5px rgba(0,0,0,0.2);
            border-left: 5px solid #FFD966;
            padding-left: 1rem;
        }

        /* Glass Stat Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 32px;
            padding: 2rem;
            text-align: center;
            transition: 0.2s;
        }

        .stat-card:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-5px);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: #FFE484;
        }

        .stat-label {
            color: rgba(255, 255, 255, 0.9);
            margin-top: 0.5rem;
        }

        /* Glass Vehicle Cards */
        .vehicles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
        }

        .vehicle-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 32px;
            overflow: hidden;
            transition: 0.25s ease;
            display: flex;
            flex-direction: column;
        }

        .vehicle-card:hover {
            transform: translateY(-8px);
            background: rgba(255, 255, 255, 0.22);
            box-shadow: 0 20px 30px rgba(0,0,0,0.2);
        }

        .vehicle-image {
            height: 200px;
            background: rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        .vehicle-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s ease-in-out;
            backface-visibility: hidden;
        }

        /* Horizontal 3D flip on hover (left-to-right rotation) */
        .vehicle-image img:hover {
            transform: rotateY(180deg);
        }

        /* Carousel specific – ensure images inside carousel also flip on hover */
        .carousel-item img {
            transition: transform 0.6s ease-in-out;
            backface-visibility: hidden;
        }
        .carousel-item img:hover {
            transform: rotateY(180deg);
        }

        .vehicle-image i {
            font-size: 4rem;
            color: rgba(255,255,255,0.6);
        }

        .featured-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(255, 193, 7, 0.9);
            backdrop-filter: blur(4px);
            color: #5a3e00;
            padding: 5px 12px;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: bold;
            z-index: 10;
        }

        .vehicle-info {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .vehicle-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .vehicle-reg {
            font-size: 0.8rem;
            background: rgba(0,0,0,0.3);
            padding: 2px 8px;
            border-radius: 20px;
            color: #FFE0A3;
        }

        .vehicle-details {
            color: #FFE0A3;
            font-size: 0.9rem;
            margin-bottom: 0.75rem;
        }

        .vehicle-specs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .vehicle-price {
            font-size: 1.5rem;
            font-weight: 800;
            color: #FFE484;
            margin-bottom: 1rem;
            margin-top: auto;
        }

        .vehicle-price small {
            font-size: 0.8rem;
            color: rgba(255,255,255,0.7);
        }

        .vehicle-card .btn {
            width: 100%;
            margin-top: 0.5rem;
        }

        /* Browse Page Layout */
        .browse-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 2rem;
            margin-top: 1rem;
        }

        /* Glass Sidebar */
        .sidebar {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 32px;
            padding: 1.5rem;
            height: fit-content;
            position: sticky;
            top: 100px;
            max-height: calc(100vh - 120px);
            overflow-y: auto;
        }

        .sidebar h3 {
            margin-bottom: 1.5rem;
            color: #FFE484;
            border-bottom: 1px solid rgba(255,255,255,0.3);
            padding-bottom: 0.5rem;
        }

        .filter-group {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: rgba(255,255,255,0.9);
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 14px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 60px;
            font-size: 0.9rem;
            color: white;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #FFD966;
            background: rgba(255,255,255,0.25);
        }

        .stats-box {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(255,255,255,0.2);
        }

        .stats-box h4 {
            color: #FFE484;
            margin-bottom: 0.8rem;
        }

        .stats-box p {
            color: rgba(255,255,255,0.8);
            margin-bottom: 0.4rem;
        }

        .filter-toggle {
            display: none;
            margin-bottom: 1rem;
        }

        .filter-toggle button {
            width: 100%;
            padding: 12px;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 40px;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }

        .no-results {
            grid-column: 1 / -1;
            text-align: center;
            padding: 4rem;
            background: rgba(0,0,0,0.3);
            backdrop-filter: blur(12px);
            border-radius: 32px;
            color: rgba(255,255,255,0.8);
        }

        /* Features Page Glass Cards */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 32px;
            padding: 2rem;
            text-align: center;
            transition: 0.2s;
        }

        .feature-card:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-5px);
        }

        .feature-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .feature-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: white;
        }

        .feature-desc {
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.6;
        }

        /* Contact Page Glass */
        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        .contact-info, .contact-form {
            background: rgba(0, 0, 0, 0.25);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 32px;
            padding: 2rem;
        }

        .contact-info h2, .contact-form h2 {
            color: #FFE484;
            margin-bottom: 1.5rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-icon {
            width: 44px;
            height: 44px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFD966;
            font-size: 1.2rem;
        }

        .info-text {
            color: rgba(255,255,255,0.9);
        }

        .info-text strong {
            color: white;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            color: rgba(255,255,255,0.9);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 60px;
            color: white;
            font-size: 0.95rem;
        }

        .form-control:focus {
            outline: none;
            border-color: #FFD966;
            background: rgba(255,255,255,0.25);
        }

        textarea.form-control {
            border-radius: 28px;
            resize: vertical;
        }

        .map-container {
            margin-top: 2rem;
            border-radius: 32px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .alert {
            padding: 1rem;
            border-radius: 60px;
            margin-bottom: 1.5rem;
            backdrop-filter: blur(8px);
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.3);
            border: 1px solid #8affa3;
            color: #e8ffe8;
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.3);
            border: 1px solid #ff8a8a;
            color: #ffe6e6;
        }

        @media (max-width: 968px) {
            .browse-container {
                grid-template-columns: 1fr;
            }
            .filter-toggle {
                display: block;
            }
            .sidebar {
                display: none;
            }
            .sidebar.show {
                display: block;
            }
            .contact-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .navbar-top {
                padding: 0.5rem 1rem;
            }
            .nav-links {
                display: none;
            }
            .main-container {
                padding: 1rem;
            }
            .navbar-brand {
                font-size: 1.2rem;
            }
            .hero h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Admin view‑only warning banner -->
    <?php if (is_admin_view_only()): ?>
        <div style="background: #ffc107; color: #333; text-align: center; padding: 10px; margin-top: 70px; position: relative; z-index: 1600;">
            <i class="fas fa-eye"></i> <strong>Admin View‑Only Mode:</strong> You can browse vehicles, but booking is disabled.
        </div>
    <?php endif; ?>

    <!-- Display flash message if any (from POST block) -->
    <?php if (isset($_SESSION['flash'])): ?>
        <div style="background: #dc3545; color: white; text-align: center; padding: 10px; margin-top: 70px;">
            <?php 
                echo htmlspecialchars($_SESSION['flash']['message']);
                unset($_SESSION['flash']);
            ?>
        </div>
    <?php endif; ?>

    <!-- ========== GLASS FIXED NAVIGATION ========== -->
    <nav class="navbar-top">
        <div class="navbar-content">
            <a href="?page=home" class="navbar-brand">
                <?php if (!empty($site_logo)): ?>
                    <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_name); ?> Logo" 
                         onerror="this.onerror=null; this.style.display='none'; this.parentElement.querySelector('.brand-text').style.display='inline-block';">
                    <span class="brand-text multicolor-text" style="display: none;">🚗 <?php echo htmlspecialchars($site_name); ?></span>
                <?php else: ?>
                    <span class="brand-text multicolor-text">🚗 <?php echo htmlspecialchars($site_name); ?></span>
                <?php endif; ?>
            </a>
            <div class="nav-links">
                <a href="?page=home" class="<?php echo $page === 'home' ? 'active' : ''; ?>">HOME</a>
                <a href="?page=vehicles" class="<?php echo $page === 'vehicles' ? 'active' : ''; ?>">VEHICLES</a>
                <a href="?page=features" class="<?php echo $page === 'features' ? 'active' : ''; ?>">FEATURES</a>
                <a href="?page=contact" class="<?php echo $page === 'contact' ? 'active' : ''; ?>">CONTACTS</a>
                <?php if (is_logged_in() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                    <a href="admin_login.php" style="color:#FFE484;"><i class="fas fa-cog"></i> MANAGE VEHICLES </a>
                <?php endif; ?>
            </div>
            <div class="navbar-right">
                <?php if (is_logged_in()): ?>
                    <span>Welcome <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                    <a href="logout.php" class="btn btn-outline btn-sm">Logout</a>
                <?php else: ?>
                    <a href="pages/login.php" class="btn btn-primary btn-sm">Login</a>
                    <a href="pages/register.php" class="btn btn-outline btn-sm">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- ========== MAIN CONTENT ========== -->
    <div class="main-container">
        <?php if ($page === 'home'): ?>
            <!-- ========== HOME PAGE ========== -->
            <div class="hero">
                <h1><i><b>Welcome to <span class="multicolor-text"><?php echo htmlspecialchars($site_name); ?></span></b></i></h1>
                <p><i><b>Your trusted car rental partner in Kenya</b></i></p>
                <a href="?page=vehicles" class="btn btn-primary" style="margin-top: 2rem; padding: 1rem 2rem;">Browse Vehicles</a>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Vehicles</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo isset($stats['by_status']['available']) ? $stats['by_status']['available'] : 0; ?></div>
                    <div class="stat-label">Available Now</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo format_currency($stats['avg_daily_rate']); ?></div>
                    <div class="stat-label">Avg. Daily Rate</div>
                </div>
            </div>

            <!-- ========== FEATURED VEHICLES SECTION ========== -->
            <?php if (!empty($featured_vehicles)): ?>
            <h2 class="section-title"><i class="fas fa-star" style="color: gold;"></i> Featured Vehicles</h2>
            <div class="vehicles-grid">
                <?php foreach ($featured_vehicles as $fv): ?>
                <div class="vehicle-card">
                    <div class="vehicle-image" style="position: relative;">
                        <?php echo vehicle_image_slideshow($fv, APP_URL); ?>
                        <div class="featured-badge"><i class="fas fa-star"></i> Featured</div>
                    </div>
                    <div class="vehicle-info">
                        <div class="vehicle-name">
                            <?php echo htmlspecialchars($fv['make'] . ' ' . $fv['model']); ?>
                            <span class="vehicle-reg"><?php echo htmlspecialchars($fv['registration_number']); ?></span>
                        </div>
                        <div class="vehicle-details">
                            <?php echo $fv['year']; ?> • <?php echo ucfirst($fv['vehicle_type']); ?>
                        </div>
                        <div class="vehicle-specs">
                            <div>🪑 <?php echo $fv['seating_capacity']; ?> Seats</div>
                            <div>📍 <?php echo htmlspecialchars($fv['county']); ?></div>
                            <div>⛽ <?php echo ucfirst($fv['fuel_type']); ?></div>
                            <div>⚙️ <?php echo ucfirst($fv['transmission']); ?></div>
                        </div>
                        <div class="vehicle-price">
                            <?php echo format_currency($fv['daily_rate']); ?><small>/day</small>
                        </div>
                        <?php if (!is_admin_view_only()): ?>
                            <?php if (is_logged_in()): ?>
                                <a href="booking.php?vehicle_id=<?php echo $fv['id']; ?>" class="btn btn-primary">Book Now</a>
                            <?php else: ?>
                                <a href="pages/login.php" class="btn btn-primary">Login to Book</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <h2 class="section-title">Recently Added</h2>
            <div class="vehicles-grid">
                <?php if (empty($recent)): ?>
                    <div class="no-results" style="grid-column:1/-1;">
                        <h3>😕 No vehicles available</h3>
                        <p>Check back soon for new arrivals!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent as $v): ?>
                        <div class="vehicle-card">
                            <div class="vehicle-image">
                                <?php echo vehicle_image_slideshow($v, APP_URL); ?>
                            </div>
                            <div class="vehicle-info">
                                <div class="vehicle-name">
                                    <?php echo htmlspecialchars($v['make'] . ' ' . $v['model']); ?>
                                    <span class="vehicle-reg"><?php echo htmlspecialchars($v['registration_number']); ?></span>
                                </div>
                                <div class="vehicle-details">
                                    <?php echo $v['year']; ?> • <?php echo ucfirst($v['vehicle_type']); ?>
                                </div>
                                <div class="vehicle-specs">
                                    <div>🪑 <?php echo $v['seating_capacity']; ?> Seats</div>
                                    <div>📍 <?php echo htmlspecialchars($v['county']); ?></div>
                                    <div>⛽ <?php echo ucfirst($v['fuel_type']); ?></div>
                                    <div>⚙️ <?php echo ucfirst($v['transmission']); ?></div>
                                </div>
                                <div class="vehicle-price">
                                    <?php echo format_currency($v['daily_rate']); ?><small>/day</small>
                                </div>
                                <?php if (!is_admin_view_only()): ?>
                                    <?php if (is_logged_in()): ?>
                                        <a href="booking.php?vehicle_id=<?php echo $v['id']; ?>" class="btn btn-primary">Book Now</a>
                                    <?php else: ?>
                                        <a href="pages/login.php" class="btn btn-primary">Login to Book</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($page === 'vehicles'): ?>
            <!-- ========== VEHICLES PAGE ========== -->
            <h1 style="color: white; margin-bottom: 0.5rem;">Available Vehicles</h1>
            <p style="color: rgba(255,255,255,0.8); margin-bottom: 2rem;">Found <?php echo count($vehicles); ?> vehicles matching your criteria</p>

            <div class="filter-toggle">
                <button onclick="document.querySelector('.sidebar').classList.toggle('show')">
                    <i class="fas fa-filter"></i> Show/Hide Filters
                </button>
            </div>

            <div class="browse-container">
                <aside class="sidebar">
                    <h3>Filter Vehicles</h3>
                    <form method="GET" action="">
                        <input type="hidden" name="page" value="vehicles">
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" placeholder="Make, Model..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                        </div>
                        <div class="filter-group">
                            <label>County</label>
                            <select name="county">
                                <option value="">All Counties</option>
                                <option value="nairobi" <?php echo isset($_GET['county']) && $_GET['county'] == 'nairobi' ? 'selected' : ''; ?>>Nairobi</option>
                                <option value="mombasa" <?php echo isset($_GET['county']) && $_GET['county'] == 'mombasa' ? 'selected' : ''; ?>>Mombasa</option>
                                <option value="kisumu" <?php echo isset($_GET['county']) && $_GET['county'] == 'kisumu' ? 'selected' : ''; ?>>Kisumu</option>
                                <option value="nakuru" <?php echo isset($_GET['county']) && $_GET['county'] == 'nakuru' ? 'selected' : ''; ?>>Nakuru</option>
                                <option value="eldoret" <?php echo isset($_GET['county']) && $_GET['county'] == 'eldoret' ? 'selected' : ''; ?>>Eldoret</option>
                                <option value="kericho" <?php echo isset($_GET['county']) && $_GET['county'] == 'kericho' ? 'selected' : ''; ?>>Kericho</option>
                                <option value="naivasha" <?php echo isset($_GET['county']) && $_GET['county'] == 'naivasha' ? 'selected' : ''; ?>>Naivasha</option>
                                <option value="nyeri" <?php echo isset($_GET['county']) && $_GET['county'] == 'nyeri' ? 'selected' : ''; ?>>Nyeri</option>
                                <option value="muranga" <?php echo isset($_GET['county']) && $_GET['county'] == 'muranga' ? 'selected' : ''; ?>>Murang'a</option>
                                <option value="thika" <?php echo isset($_GET['county']) && $_GET['county'] == 'thika' ? 'selected' : ''; ?>>Thika</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Vehicle Type</label>
                            <select name="vehicle_type">
                                <option value="">All Types</option>
                                <option value="economy" <?php echo isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'economy' ? 'selected' : ''; ?>>Economy</option>
                                <option value="compact" <?php echo isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'compact' ? 'selected' : ''; ?>>Compact</option>
                                <option value="sedan" <?php echo isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'sedan' ? 'selected' : ''; ?>>Sedan</option>
                                <option value="suv" <?php echo isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'suv' ? 'selected' : ''; ?>>SUV</option>
                                <option value="luxury" <?php echo isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'luxury' ? 'selected' : ''; ?>>Luxury</option>
                                <option value="van" <?php echo isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'van' ? 'selected' : ''; ?>>Van</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Apply Filters</button>
                        <a href="?page=vehicles" class="btn btn-outline" style="width: 100%; margin-top: 0.5rem;">Clear Filters</a>
                    </form>

                    <div class="stats-box">
                        <h4>📊 Statistics</h4>
                        <p><strong>Total Vehicles:</strong> <?php echo $stats['total']; ?></p>
                        <p><strong>Available:</strong> <?php echo isset($stats['by_status']['available']) ? $stats['by_status']['available'] : 0; ?></p>
                        <p><strong>Avg Daily Rate:</strong> <?php echo format_currency($stats['avg_daily_rate']); ?></p>
                    </div>
                </aside>

                <main>
                    <div class="vehicles-grid">
                        <?php if (count($vehicles) > 0): ?>
                            <?php foreach ($vehicles as $v): ?>
                                <div class="vehicle-card">
                                    <div class="vehicle-image">
                                        <?php echo vehicle_image_slideshow($v, APP_URL); ?>
                                    </div>
                                    <div class="vehicle-info">
                                        <div class="vehicle-name">
                                            <?php echo htmlspecialchars($v['make'] . ' ' . $v['model']); ?>
                                            <span class="vehicle-reg"><?php echo htmlspecialchars($v['registration_number']); ?></span>
                                        </div>
                                        <div class="vehicle-details">
                                            <?php echo $v['year']; ?> • <?php echo ucfirst($v['vehicle_type']); ?> • <?php echo ucfirst($v['fuel_type']); ?>
                                        </div>
                                        <div class="vehicle-specs">
                                            <div>🪑 <?php echo $v['seating_capacity']; ?> Seats</div>
                                            <div>📍 <?php echo htmlspecialchars($v['county']); ?></div>
                                            <div>⚙️ <?php echo ucfirst($v['transmission']); ?></div>
                                            <?php if (!empty($v['mileage'])): ?>
                                                <div>📊 <?php echo number_format($v['mileage']); ?> km</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="vehicle-price">
                                            <?php echo format_currency($v['daily_rate']); ?><small>/day</small>
                                            <?php if (!empty($v['weekly_rate'])): ?>
                                                <br><small style="font-size:0.7rem;">Weekly: <?php echo format_currency($v['weekly_rate']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!is_admin_view_only()): ?>
                                            <?php if (is_logged_in()): ?>
                                                <a href="booking.php?vehicle_id=<?php echo $v['id']; ?>" class="btn btn-primary">Book Now</a>
                                            <?php else: ?>
                                                <a href="pages/login.php" class="btn btn-primary">Login to Book</a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-results">
                                <h3>😕 No vehicles found</h3>
                                <p>Try adjusting your filters or search criteria</p>
                                <a href="?page=vehicles" class="btn btn-primary" style="margin-top: 1rem;">View All Vehicles</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </main>
            </div>

        <?php elseif ($page === 'features'): ?>
            <!-- ========== DYNAMIC FEATURES PAGE ========== -->
            <h1 style="color: white;">System Features</h1>
            <p style="color: rgba(255,255,255,0.8); margin-bottom:2rem;">Discover what makes <?php echo htmlspecialchars($site_name); ?> your best car rental choice</p>

            <div class="features-grid">
                <?php 
                $features = get_features();
                if (empty($features)): ?>
                    <div class="no-results" style="grid-column:1/-1;">
                        <h3>No features configured yet</h3>
                        <p>Please check back later.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($features as $feature): ?>
                        <div class="feature-card">
                            <div class="feature-icon"><?php echo htmlspecialchars($feature['icon']); ?></div>
                            <h3 class="feature-title"><?php echo htmlspecialchars($feature['title']); ?></h3>
                            <p class="feature-desc"><?php echo nl2br(htmlspecialchars($feature['description'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($page === 'contact'): ?>
            <!-- ========== CONTACT PAGE (DYNAMIC) ========== -->
            <h1 style="color: white;">Contact Us</h1>
            <p style="color: rgba(255,255,255,0.8);">We're here to help. Reach out anytime.</p>

            <?php if ($contact_success): ?>
                <div class="alert alert-success"><?php echo $contact_success; ?></div>
            <?php endif; ?>
            <?php if ($contact_error): ?>
                <div class="alert alert-danger"><?php echo $contact_error; ?></div>
            <?php endif; ?>

            <div class="contact-grid">
                <div class="contact-info">
                    <h2>Get in Touch</h2>
                    <div class="info-item">
                        <div class="info-icon"><i class="fas fa-phone-alt"></i></div>
                        <div class="info-text"><strong>Phone:</strong><br><?php echo htmlspecialchars($contact_phone); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon"><i class="fas fa-envelope"></i></div>
                        <div class="info-text"><strong>Email:</strong><br><?php echo htmlspecialchars($contact_email); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="info-text"><strong>Head Office:</strong><br><?php echo htmlspecialchars($contact_address); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon"><i class="fas fa-clock"></i></div>
                        <div class="info-text"><strong>Working Hours:</strong><br>Mon–Sat, 8:00 AM – 8:00 PM</div>
                    </div>
                </div>

                <div class="contact-form">
                    <h2>Send a Message</h2>
                    <form method="post" action="">
                        <input type="hidden" name="contact_submit" value="1">
                        <div class="form-group">
                            <label>Your Name</label>
                            <input type="text" name="name" class="form-control" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Message</label>
                            <textarea name="message" class="form-control" rows="4" required><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">Send Message</button>
                    </form>
                </div>
            </div>

            <div class="map-container">
                <!-- Google Map updated to Safari Park, Nairobi (Safari Park Hotel area) -->
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3988.8123456789!2d36.8923456!3d-1.2198765!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x182f169e6a2b6b6b%3A0x1b0c5c9b9c9c9c9c!2sSafari%20Park%20Hotel!5e0!3m2!1sen!2ske!4v1712000000000" width="100%" height="300" style="border:0; border-radius: 28px;" allowfullscreen="" loading="lazy"></iframe>
            </div>
        <?php endif; ?>
    </div>

    <!-- ========== GLASS FOOTER ========== -->
    <footer class="footer">
        <div class="container">
            <div class="footer-links">
                <a href="?page=home">Home</a>
                <a href="?page=vehicles">Vehicles</a>
                <a href="?page=features">Features</a>
                <a href="?page=contact">Contact</a>
            </div>
            <p style="text-align: center; color: rgba(255,255,255,0.7);">
                &copy; 2026 <span class="multicolor-text"><?php echo htmlspecialchars($site_name); ?></span>. All rights reserved.
            </p>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
    <script>
        // ========== RAINDROPS ANIMATION ==========
        function createRaindrop() {
            const raindrop = document.createElement('div');
            raindrop.classList.add('raindrop');
            const size = Math.random() * 6 + 3; // 3px to 9px
            raindrop.style.width = `${size}px`;
            raindrop.style.height = `${size}px`;
            raindrop.style.left = `${Math.random() * 100}%`;
            raindrop.style.animationDuration = `${Math.random() * 2 + 1.5}s`; // 1.5 to 3.5 seconds
            raindrop.style.animationDelay = `${Math.random() * 5}s`;
            document.body.appendChild(raindrop);
            setTimeout(() => raindrop.remove(), 5000);
        }
        setInterval(createRaindrop, 200);

        (function() {
            if (window.innerWidth <= 968) {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar) sidebar.classList.remove('show');
            }
        })();
    </script>
</body>
</html>
<?php $conn->close(); ?>

