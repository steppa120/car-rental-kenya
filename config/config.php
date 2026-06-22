<?php
/**
 * ============================================================
 * FleetKE Car Rental — Configuration
 * ============================================================
 * APP_URL is detected dynamically — no hardcoded domains.
 * Set the APP_URL env var on Render to override if needed.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── DB credentials (read from env or use defaults for local) ────────────────
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');

// ── Dynamic APP_URL ──────────────────────────────────────────────────────────
// Priority: APP_URL env var > RENDER_EXTERNAL_URL > auto-detect from request
if (!defined('APP_URL')) {
    if (getenv('APP_URL')) {
        define('APP_URL', rtrim(getenv('APP_URL'), '/'));
    } elseif (getenv('RENDER_EXTERNAL_URL')) {
        define('APP_URL', rtrim(getenv('RENDER_EXTERNAL_URL'), '/'));
    } else {
        // Auto-detect: works on localhost, Render, or any other host
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
              || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        define('APP_URL', $proto . '://' . $host);
    }
}

// ── App constants ────────────────────────────────────────────────────────────
define('APP_NAME',        'FleetKE - Car Rental Kenya');
define('CURRENCY',        'KES');
define('CURRENCY_SYMBOL', 'Ksh');

define('SESSION_TIMEOUT', 3600);
define('SESSION_NAME',    'fleetke_session');

define('UPLOAD_DIR',          __DIR__ . '/../uploads/');
define('UPLOAD_URL',          APP_URL . '/uploads/');
define('MAX_FILE_SIZE',       5242880); // 5 MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_IMAGE_EXT',   ['jpg', 'jpeg', 'png', 'gif', 'webp']);

define('PASSWORD_MIN_LENGTH', 8);

// Pricing constants
define('LATE_FEE_PER_HOUR',      500);   // KES per hour
define('DAMAGE_PENALTY_MINOR',   2000);
define('DAMAGE_PENALTY_MODERATE',5000);
define('DAMAGE_PENALTY_SEVERE',  15000);

define('ADMIN_EMAIL',   'admin@fleetke.com');
define('ITEMS_PER_PAGE', 10);

// Kenya counties
$KENYA_COUNTIES = [
    'nairobi'  => ['name' => 'Nairobi',  'lat' => -1.2921, 'lng' => 36.8219],
    'mombasa'  => ['name' => 'Mombasa',  'lat' => -4.0435, 'lng' => 39.6682],
    'kisumu'   => ['name' => 'Kisumu',   'lat' => -0.1022, 'lng' => 34.7617],
    'nakuru'   => ['name' => 'Nakuru',   'lat' => -0.2833, 'lng' => 36.0667],
    'eldoret'  => ['name' => 'Eldoret',  'lat' =>  0.5143, 'lng' => 35.2799],
    'kericho'  => ['name' => 'Kericho',  'lat' => -0.3667, 'lng' => 35.2833],
    'naivasha' => ['name' => 'Naivasha', 'lat' => -0.7167, 'lng' => 36.4333],
    'nyeri'    => ['name' => 'Nyeri',    'lat' => -0.4167, 'lng' => 36.9500],
];

// ── Connect ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/db.php';
$mysqli = db_connect();
$mysqli->set_charset('utf8mb4');

// ── Helper functions ─────────────────────────────────────────────────────────
function sanitize(string $data): string {
    global $mysqli;
    return $mysqli->real_escape_string(trim($data));
}

function validate_email(?string $email): bool {
    return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function hash_password(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verify_password(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function format_currency(float $amount): string {
    return CURRENCY_SYMBOL . ' ' . number_format($amount, 2);
}

function format_date(string $date): string {
    return date('d M Y', strtotime($date));
}

function format_datetime(string $datetime): string {
    return date('d M Y H:i', strtotime($datetime));
}

function redirect(string $path): void {
    // Support absolute URLs
    if (strpos($path, 'http') === 0) {
        header('Location: ' . $path);
    } else {
        $path = '/' . ltrim($path, '/');
        header('Location: ' . rtrim(APP_URL, '/') . $path);
    }
    exit();
}

function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function is_admin(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function require_login(): void {
    if (!is_logged_in()) {
        set_flash('error', 'Please login to continue');
        redirect('/pages/login.php');
    }
}

function require_admin(): void {
    if (!is_admin()) {
        set_flash('error', 'Admin access required');
        redirect('/pages/login.php');
    }
}

function generate_token(): string {
    return bin2hex(random_bytes(32));
}

function verify_csrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generate_token();
}
