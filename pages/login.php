<?php
/**
 * ============================================================
 * Login Page – Self‑contained (no external config.php needed)
 * Redirects: customers → browse.php, admin → admin_login.php
 * ============================================================
 */
error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
ob_start();

// ----- Database configuration (hardcoded for local development) -----
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
function is_admin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}
function sanitize($data) {
    global $conn;
    return $conn->real_escape_string(trim($data));
}
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}
function format_currency($amount) {
    return 'Ksh ' . number_format($amount, 2);
}

// ----- Session start -----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ----- If already logged in, redirect to appropriate dashboard -----
if (is_logged_in()) {
    if (is_admin()) {
        redirect('/pages/admin_login.php');
    } else {
        redirect('/pages/browse.php');
    }
}

$error = '';
$email = '';

// ----- Ensure users table exists (create if missing) -----
$table_check = $conn->query("SHOW TABLES LIKE 'users'");
if (!$table_check || $table_check->num_rows == 0) {
    $create_sql = "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `first_name` VARCHAR(100) NOT NULL,
        `last_name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL,
        `user_role` ENUM('admin', 'customer') NOT NULL DEFAULT 'customer',
        `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        `last_login` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_email_status` (`email`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!$conn->query($create_sql)) {
        die("Failed to create users table: " . $conn->error);
    }
    
    // Insert demo admin (password = 'admin123')
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    $conn->query("INSERT IGNORE INTO `users` 
        (`first_name`, `last_name`, `email`, `password_hash`, `user_role`, `status`) 
        VALUES ('Admin', 'User', 'admin@example.com', '$admin_password', 'admin', 'active')");
    
    // Insert demo customer (password = 'customer123')
    $customer_password = password_hash('customer123', PASSWORD_DEFAULT);
    $conn->query("INSERT IGNORE INTO `users` 
        (`first_name`, `last_name`, `email`, `password_hash`, `user_role`, `status`) 
        VALUES ('Demo', 'Customer', 'customer@example.com', '$customer_password', 'customer', 'active')");
}

// ----- Handle login POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, first_name, last_name, password_hash, user_role FROM users WHERE email = ? AND status = 'active'");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user && verify_password($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['user_role'] = $user['user_role'];

                // Update last_login if column exists
                $check_column = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'");
                if ($check_column && $check_column->num_rows > 0) {
                    $update = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    if ($update) {
                        $update->bind_param("i", $user['id']);
                        $update->execute();
                        $update->close();
                    }
                }

                // Redirect based on role
                if ($_SESSION['user_role'] === 'admin') {
                    redirect('/pages/admin_login.php');
                } else {
                    redirect('/pages/browse.php');
                }
            } else {
                $error = 'Invalid email or password.';
            }
            $stmt->close();
        } else {
            $error = 'Database error: unable to prepare statement.';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FleetKE Car Rental</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            /* High-quality beach wallpaper with overlay for better glass effect */
            background: linear-gradient(145deg, rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.2)), 
                        url('https://images.pexels.com/photos/2387873/pexels-photo-2387873.jpeg?auto=compress&cs=tinysrgb&w=1600');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* Main content wrapper */
        .login-wrapper {
            max-width: 480px;
            width: 100%;
            animation: fadeUp 0.6s ease-out;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ========== ENHANCED GLASSMORPHISM CARD ========== */
        .login-box {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 56px;
            padding: 44px 38px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 30px 50px rgba(0, 0, 0, 0.3), inset 0 1px 1px rgba(255, 255, 255, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .login-box:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 40px 60px rgba(0, 0, 0, 0.4);
            border-color: rgba(255, 255, 255, 0.5);
        }

        /* Logo & Heading — crisp glass-compatible gradient */
        .logo {
            text-align: center;
            margin-bottom: 36px;
        }

        .logo h1 {
            font-size: 2.7rem;
            font-weight: 800;
            background: linear-gradient(135deg, #FFFFFF, #FFE9B6, #FFD966);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.15);
            letter-spacing: -0.5px;
            margin-bottom: 10px;
        }

        .logo p {
            color: rgba(255, 255, 245, 0.95);
            font-size: 1rem;
            font-weight: 600;
            background: rgba(0, 0, 0, 0.35);
            display: inline-block;
            padding: 6px 20px;
            border-radius: 60px;
            backdrop-filter: blur(5px);
        }

        /* Glass alerts */
        .alert {
            padding: 12px 18px;
            border-radius: 60px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-danger {
            background: rgba(200, 60, 70, 0.3);
            color: #fff0f0;
            border-left: 5px solid #ff9e9e;
        }

        .alert-success {
            background: rgba(60, 180, 90, 0.3);
            color: #e8ffef;
            border-left: 5px solid #a5ffb3;
        }

        /* Form group */
        .form-group {
            margin-bottom: 28px;
        }

        .form-group label {
            display: block;
            margin-bottom: 12px;
            color: rgba(255, 255, 245, 0.95);
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.4px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .form-group label i {
            margin-right: 8px;
            color: #FFE2A4;
        }

        /* Glass inputs – pure frosted style */
        .form-control {
            width: 100%;
            padding: 16px 24px;
            background: rgba(219, 210, 210, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 80px;
            font-size: 1rem;
            font-weight: 500;
            color: #1e2a2a;
            transition: all 0.25s ease;
            backdrop-filter: blur(4px);
        }

        .form-control:focus {
            outline: none;
            border-color: #FFD966;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 0 0 5px rgba(255, 217, 102, 0.3);
        }

        .form-control::placeholder {
            color: #e704dc;
            font-weight: 400;
        }

        /* Glass button – deep ocean blur */
        .btn {
            padding: 16px 24px;
            border: none;
            border-radius: 80px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            background: rgba(255, 0, 191, 0.75);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 245, 200, 0.6);
            color: white;
            letter-spacing: 0.6px;
            text-shadow: 0 1px 1px rgba(0,0,0,0.1);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            background: rgba(72, 181, 160, 0.9);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.25);
            border-color: rgba(255, 255, 220, 0.9);
        }

        /* Links with light glass touch */
        .links {
            margin-top: 28px;
            text-align: center;
        }

        .links a {
            color: #FFF6E0;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: 0.2s;
            border-bottom: 1px solid transparent;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .links a:hover {
            color: #FFEFC5;
            border-bottom-color: #FFE2A4;
        }

        .register-link {
            margin-top: 24px;
            border-top: 1px solid rgba(255, 245, 200, 0.4);
            padding-top: 24px;
            text-align: center;
        }

        .register-link a {
            color: #FFF5E0;
            font-weight: 600;
            font-size: 0.9rem;
            background: rgba(0, 0, 0, 0.3);
            padding: 10px 26px;
            border-radius: 60px;
            transition: 0.2s;
            text-decoration: none;
            backdrop-filter: blur(4px);
        }

        .register-link a:hover {
            background: rgba(255, 245, 200, 0.35);
            color: white;
        }

        .admin-link {
            margin-top: 20px;
            text-align: center;
        }

        .admin-link a {
            color: rgba(255, 250, 225, 0.95);
            font-size: 0.85rem;
            text-decoration: none;
            font-weight: 500;
            transition: 0.2s;
        }

        .admin-link a i {
            margin-right: 6px;
        }

        .admin-link a:hover {
            color: #FFEFC5;
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 550px) {
            .login-box {
                padding: 32px 24px;
            }
            .logo h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-box">
        <div class="logo">
            <h1><i class="fas fa-car-side"></i> FLEETKE MOTORS</h1>
            <p><b>Your trusted car rental partner in Kenya</b></p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <div><?php echo htmlspecialchars($flash['message']); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="hello@fleetke.com" required autofocus>
            </div>
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>

        <div class="links">
            <a href="forgot_password.php"><i class="fas fa-key"></i> Forgot Password?</a>
        </div>
        <div class="register-link">
            <a href="register.php"><i class="fas fa-user-plus"></i> Don't have an account? Register</a>
        </div>
        <div class="admin-link">
            <a href="<?php echo APP_URL; ?>/pages/admin.php"><i class="fas fa-shield-alt"></i> ADMIN LOGIN</a>
        </div>
    </div>
</div>
</body>
</html>

