<?php
/**
 * ============================================================
 * User Registration Page – Self-contained (no external files)
 * Creates a new customer account and redirects to login
 * ADDED: Stars + Splash glassmorphism effects
 * ============================================================
 */
error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
ob_start();

// ----- Database configuration (must match login.php) -----
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');
define('APP_URL', getenv('APP_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: 'http://localhost/car-rental-kenya'));

// ----- Database connection -----
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

// ----- Helper functions -----
function sanitize($data) {
    global $conn;
    return $conn->real_escape_string(trim($data));
}
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT);
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
function generate_token() {
    return bin2hex(random_bytes(32));
}
function verify_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ----- Session start -----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generate_token();
}

// If already logged in, redirect to homepage
if (is_logged_in()) {
    redirect('/pages/browse.php');
}

// ----- Ensure users table exists with all required columns -----
$table_check = $conn->query("SHOW TABLES LIKE 'users'");
if (!$table_check || $table_check->num_rows == 0) {
    // Create full table
    $create_sql = "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `first_name` VARCHAR(100) NOT NULL,
        `last_name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `phone` VARCHAR(20) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `user_role` ENUM('admin', 'customer') NOT NULL DEFAULT 'customer',
        `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        `id_number` VARCHAR(20) DEFAULT NULL,
        `license_number` VARCHAR(50) NOT NULL UNIQUE,
        `license_expiry` DATE NOT NULL,
        `address` TEXT DEFAULT NULL,
        `city` VARCHAR(100) DEFAULT NULL,
        `county` VARCHAR(50) NOT NULL,
        `postal_code` VARCHAR(20) DEFAULT NULL,
        `last_login` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_email_status` (`email`, `status`),
        KEY `idx_license` (`license_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($create_sql)) {
        die("Failed to create users table: " . $conn->error);
    }
} else {
    // Add missing columns safely (with defaults to avoid errors)
    $columns_to_add = [
        'phone' => "ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) NOT NULL DEFAULT '' AFTER `email`",
        'id_number' => "ALTER TABLE `users` ADD COLUMN `id_number` VARCHAR(20) DEFAULT NULL AFTER `password_hash`",
        'license_number' => "ALTER TABLE `users` ADD COLUMN `license_number` VARCHAR(50) DEFAULT NULL AFTER `id_number`",
        'license_expiry' => "ALTER TABLE `users` ADD COLUMN `license_expiry` DATE DEFAULT NULL AFTER `license_number`",
        'address' => "ALTER TABLE `users` ADD COLUMN `address` TEXT DEFAULT NULL AFTER `license_expiry`",
        'city' => "ALTER TABLE `users` ADD COLUMN `city` VARCHAR(100) DEFAULT NULL AFTER `address`",
        'county' => "ALTER TABLE `users` ADD COLUMN `county` VARCHAR(50) DEFAULT NULL AFTER `city`",
        'postal_code' => "ALTER TABLE `users` ADD COLUMN `postal_code` VARCHAR(20) DEFAULT NULL AFTER `county`"
    ];
    foreach ($columns_to_add as $col => $alter_sql) {
        $check = $conn->query("SHOW COLUMNS FROM `users` LIKE '$col'");
        if (!$check || $check->num_rows == 0) {
            if (!$conn->query($alter_sql)) {
                error_log("Failed to add column $col: " . $conn->error);
            }
        }
    }
    
    // After adding columns, enforce NOT NULL on license_number and license_expiry if they exist and have NULL values
    $check_license = $conn->query("SHOW COLUMNS FROM `users` LIKE 'license_number'");
    if ($check_license && $check_license->num_rows > 0) {
        $col_info = $check_license->fetch_assoc();
        if ($col_info['Null'] === 'YES') {
            $conn->query("UPDATE `users` SET `license_number` = CONCAT('temp_', id) WHERE `license_number` IS NULL");
            $conn->query("ALTER TABLE `users` MODIFY `license_number` VARCHAR(50) NOT NULL, ADD UNIQUE INDEX `idx_license` (`license_number`)");
        }
    }
    
    $check_expiry = $conn->query("SHOW COLUMNS FROM `users` LIKE 'license_expiry'");
    if ($check_expiry && $check_expiry->num_rows > 0) {
        $col_info = $check_expiry->fetch_assoc();
        if ($col_info['Null'] === 'YES') {
            $conn->query("UPDATE `users` SET `license_expiry` = '2000-01-01' WHERE `license_expiry` IS NULL");
            $conn->query("ALTER TABLE `users` MODIFY `license_expiry` DATE NOT NULL");
        }
    }
    
    $check_county = $conn->query("SHOW COLUMNS FROM `users` LIKE 'county'");
    if ($check_county && $check_county->num_rows > 0) {
        $col_info = $check_county->fetch_assoc();
        if ($col_info['Null'] === 'YES') {
            $conn->query("UPDATE `users` SET `county` = 'nairobi' WHERE `county` IS NULL");
            $conn->query("ALTER TABLE `users` MODIFY `county` VARCHAR(50) NOT NULL");
        }
    }
}

$error = '';
$success = '';

// ----- Handle registration POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_token($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $first_name = sanitize($_POST['first_name'] ?? '');
        $last_name = sanitize($_POST['last_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $id_number = sanitize($_POST['id_number'] ?? '');
        $license_number = sanitize($_POST['license_number'] ?? '');
        $license_expiry = sanitize($_POST['license_expiry'] ?? '');
        $county = sanitize($_POST['county'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city = sanitize($_POST['city'] ?? '');
        $postal_code = sanitize($_POST['postal_code'] ?? '');

        if (empty($first_name) || empty($last_name) || empty($email) || empty($phone) || empty($password) || empty($license_number) || empty($license_expiry) || empty($county)) {
            $error = 'Please fill in all required fields.';
        } elseif (!validate_email($email)) {
            $error = 'Invalid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif (strtotime($license_expiry) <= time()) {
            $error = 'License expiry date must be in the future.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            if (!$stmt) {
                $error = 'Database error: ' . $conn->error;
            } else {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $error = 'Email already registered.';
                } else {
                    $stmt2 = $conn->prepare("SELECT id FROM users WHERE license_number = ?");
                    if (!$stmt2) {
                        $error = 'Database error: ' . $conn->error;
                    } else {
                        $stmt2->bind_param("s", $license_number);
                        $stmt2->execute();
                        $result2 = $stmt2->get_result();
                        if ($result2->num_rows > 0) {
                            $error = 'License number already registered.';
                        } else {
                            $password_hash = hash_password($password);
                            $insert = $conn->prepare("INSERT INTO users 
                                (first_name, last_name, email, phone, password_hash, user_role, status, 
                                 id_number, license_number, license_expiry, address, city, county, postal_code) 
                                VALUES (?, ?, ?, ?, ?, 'customer', 'active', ?, ?, ?, ?, ?, ?, ?)");
                            if (!$insert) {
                                $error = 'Database error (prepare): ' . $conn->error;
                            } else {
                                $insert->bind_param("ssssssssssss", 
                                    $first_name, $last_name, $email, $phone, $password_hash,
                                    $id_number, $license_number, $license_expiry, $address, $city, $county, $postal_code
                                );
                                if ($insert->execute()) {
                                    $success = 'Registration successful! Please log in.';
                                    echo '<meta http-equiv="refresh" content="2;url=' . APP_URL . '/pages/login.php">';
                                } else {
                                    $error = 'Registration failed: ' . $insert->error;
                                }
                                $insert->close();
                            }
                        }
                        $stmt2->close();
                    }
                }
                $stmt->close();
            }
        }
    }
}

$flash = get_flash();
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Register - FleetKE Car Rental</title>
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
            /* Forest/Beach background with dark overlay for readability */
            background: linear-gradient(rgba(0, 20, 30, 0.5), rgba(0, 10, 20, 0.6)), 
                        url('https://images.pexels.com/photos/2387873/pexels-photo-2387873.jpeg?auto=compress&cs=tinysrgb&w=1600') no-repeat center center fixed;
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            overflow-x: hidden;
        }

        /* ----- STARS GLASSMORPHISM (twinkling) ----- */
        .stars-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
            overflow: hidden;
        }
        .star {
            position: absolute;
            background: rgba(255, 255, 200, 0.8);
            border-radius: 50%;
            box-shadow: 0 0 6px 2px rgba(255, 245, 150, 0.8);
            animation: twinkle linear infinite;
        }
        @keyframes twinkle {
            0%, 100% { opacity: 0.1; transform: scale(1); }
            50% { opacity: 0.9; transform: scale(1.3); }
        }

        /* ----- SPLASH GLASSMORPHISM (water droplets / light splashes) ----- */
        .splash-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
            overflow: hidden;
        }
        .splash {
            position: absolute;
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(3px);
            border-radius: 60% 40% 50% 50% / 45% 50% 50% 55%;
            animation: splashFloat linear infinite;
        }
        @keyframes splashFloat {
            0% {
                transform: translateY(0) scale(0.8) rotate(0deg);
                opacity: 0.3;
            }
            50% {
                opacity: 0.7;
            }
            100% {
                transform: translateY(-120vh) scale(1.4) rotate(15deg);
                opacity: 0;
            }
        }

        /* ----- GLASSMORPHISM CARD (raised above effects) ----- */
        .register-container {
            position: relative;
            z-index: 10;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
        }

        .register-box {
            background: rgba(15, 25, 50, 0.55);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-radius: 32px;
            padding: 2rem;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.25);
        }

        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .register-header h1 {
            color: #ffffff;
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        }

        .register-header h1 i {
            margin-right: 8px;
            color: #ffd966;
        }

        .register-header p {
            color: rgba(255, 255, 255, 0.85);
            font-size: 1rem;
        }

        /* Alerts (glass style) */
        .alert {
            padding: 12px 18px;
            border-radius: 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            backdrop-filter: blur(4px);
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.25);
            color: #ffb3b3;
            border: 1px solid rgba(220, 53, 69, 0.5);
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.25);
            color: #b3ffcf;
            border: 1px solid rgba(40, 167, 69, 0.5);
        }

        /* Form styling */
        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: rgba(255, 255, 255, 0.95);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group label i {
            margin-right: 8px;
            color: #ffd966;
        }

        .form-control,
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 16px;
            font-size: 1rem;
            color: #ffffff;
            transition: all 0.25s ease;
            outline: none;
        }

        .form-group input::placeholder,
        .form-group select {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-group select option {
            background: #0a1128;
            color: white;
        }

        .form-control:focus,
        .form-group input:focus,
        .form-group select:focus {
            border-color: #ffd966;
            background: rgba(255, 255, 255, 0.2);
            box-shadow: 0 0 0 3px rgba(255, 217, 102, 0.2);
        }

        .help-text {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 5px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 40px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            text-decoration: none;
            background: linear-gradient(135deg, #ffb347, #ff8c00);
            color: #0a0f2a;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .btn i {
            color: #0a0f2a;
        }

        .btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        .register-footer {
            text-align: center;
            margin-top: 1.8rem;
            padding-top: 1.2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        .register-footer p {
            color: rgba(255, 255, 255, 0.85);
        }

        .register-footer a {
            color: #ffd966;
            text-decoration: none;
            font-weight: 600;
            transition: 0.2s;
        }

        .register-footer a:hover {
            text-decoration: underline;
            color: #ffe08c;
        }

        @media (max-width: 650px) {
            body {
                padding: 1rem;
            }
            .register-box {
                padding: 1.5rem;
            }
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
    </style>
</head>
<body>

<!-- Stars glassmorphism container -->
<div class="stars-container" id="starsContainer"></div>

<!-- Splash glassmorphism container -->
<div class="splash-container" id="splashContainer"></div>

<div class="register-container">
    <div class="register-box">
        <div class="register-header">
            <h1><i class="fas fa-car"></i>FleetKE</h1>
            <p>Join the ride — secure, fast, and modern</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <div><?php echo htmlspecialchars($flash['message']); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name"><i class="fas fa-user"></i> First Name *</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                <div class="form-group">
                    <label for="last_name"><i class="fas fa-user"></i> Last Name *</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
            </div>

            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="phone"><i class="fas fa-phone-alt"></i> Phone Number *</label>
                <input type="tel" id="phone" name="phone" placeholder="+254..." required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="id_number"><i class="fas fa-id-card"></i> ID Number</label>
                    <input type="text" id="id_number" name="id_number">
                </div>
                <div class="form-group">
                    <label for="county"><i class="fas fa-map-marker-alt"></i> County *</label>
                    <select id="county" name="county" required>
                        <option value="">Select County</option>
                        <option value="nairobi">Nairobi</option>
                        <option value="mombasa">Mombasa</option>
                        <option value="kisumu">Kisumu</option>
                        <option value="nakuru">Nakuru</option>
                        <option value="eldoret">Eldoret</option>
                        <option value="kericho">Kericho</option>
                        <option value="naivasha">Naivasha</option>
                        <option value="nyeri">Nyeri</option>
                        <option value="muranga">Murang'a</option>
                        <option value="thika">Thika</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="address"><i class="fas fa-location-dot"></i> Address</label>
                <input type="text" id="address" name="address">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="city"><i class="fas fa-city"></i> City</label>
                    <input type="text" id="city" name="city">
                </div>
                <div class="form-group">
                    <label for="postal_code"><i class="fas fa-mail-bulk"></i> Postal Code</label>
                    <input type="text" id="postal_code" name="postal_code">
                </div>
            </div>

            <div class="form-group">
                <label for="license_number"><i class="fas fa-id-card"></i> Driver's License Number *</label>
                <input type="text" id="license_number" name="license_number" required>
            </div>

            <div class="form-group">
                <label for="license_expiry"><i class="fas fa-calendar-alt"></i> License Expiry Date *</label>
                <input type="date" id="license_expiry" name="license_expiry" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password *</label>
                    <input type="password" id="password" name="password" required>
                    <div class="help-text">At least 8 characters</div>
                </div>
                <div class="form-group">
                    <label for="confirm_password"><i class="fas fa-lock"></i> Confirm Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
            </div>

            <button type="submit" class="btn">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>

        <div class="register-footer">
            <p>Already have an account? <a href="<?php echo APP_URL; ?>/pages/login.php">Login here</a></p>
        </div>
    </div>
</div>

<script>
    (function() {
        // ----- Generate twinkling stars -----
        const starsContainer = document.getElementById('starsContainer');
        if (starsContainer) {
            const STAR_COUNT = 180;
            for (let i = 0; i < STAR_COUNT; i++) {
                const star = document.createElement('div');
                star.classList.add('star');
                const size = Math.random() * 3 + 1; // 1px to 4px
                star.style.width = size + 'px';
                star.style.height = size + 'px';
                star.style.left = Math.random() * 100 + '%';
                star.style.top = Math.random() * 100 + '%';
                const duration = Math.random() * 5 + 2; // 2 to 7 seconds
                const delay = Math.random() * 5;
                star.style.animation = `twinkle ${duration}s infinite ease-in-out`;
                star.style.animationDelay = `${delay}s`;
                starsContainer.appendChild(star);
            }
        }

        // ----- Generate splash effects (water droplets / soft circles) -----
        const splashContainer = document.getElementById('splashContainer');
        if (splashContainer) {
            const SPLASH_COUNT = 35;
            for (let i = 0; i < SPLASH_COUNT; i++) {
                const splash = document.createElement('div');
                splash.classList.add('splash');
                const size = Math.random() * 70 + 15;
                splash.style.width = size + 'px';
                splash.style.height = size + 'px';
                splash.style.left = Math.random() * 100 + '%';
                splash.style.top = Math.random() * 100 + '%';
                // Random starting vertical offset so they don't all start at same Y
                splash.style.top = (Math.random() * 100) + '%';
                const duration = Math.random() * 10 + 6;
                splash.style.animation = `splashFloat ${duration}s linear infinite`;
                const delay = Math.random() * 8;
                splash.style.animationDelay = `${delay}s`;
                // Random rotation variance
                const randomRotate = Math.random() * 360;
                splash.style.transform = `rotate(${randomRotate}deg)`;
                splashContainer.appendChild(splash);
            }
        }
    })();
</script>
</body>
</html>

