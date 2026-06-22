<?php
/**
 * ============================================================
 * Admin Login Page – Separate entry for administrators
 * Location: /pages/admin.php
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
ob_start();

// ------------------------------------------------------------------
// Configuration – same as other files
// ------------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');
define('APP_URL', getenv('APP_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: 'http://localhost/car-rental-kenya'));

// Database Connection (kept for potential future use, but not used for login)
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

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ------------------------------------------------------------------
// Helper Functions
// ------------------------------------------------------------------
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

// If already logged in as admin, go to dashboard
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'admin') {
    redirect(APP_URL . '/pages/admin_login.php');
}

$error = '';
$email = '';

// ------------------------------------------------------------------
// HARDCODED ADMIN CREDENTIALS – Change these as needed
// ------------------------------------------------------------------
$valid_admin_email = 'admin@gmail.com';
$valid_admin_password = '120179'; // plain text password

// ------------------------------------------------------------------
// Process Login Form
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        // Compare with hardcoded credentials
        if ($email === $valid_admin_email && $password === $valid_admin_password) {
            $_SESSION['user_id'] = 1;                // dummy user ID
            $_SESSION['user_name'] = 'Administrator'; // display name
            $_SESSION['user_role'] = 'admin';

            redirect(APP_URL . '/pages/admin_login.php'); // ✅ go to dashboard
        } else {
            $error = 'Invalid admin credentials.';
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
    <title>Admin Login - FleetKE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #111110 0%, #e032d7 50%, #19f705 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* ----- Floating Stars Background ----- */
        .stars-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .star {
            position: absolute;
            background: rgba(255, 245, 180, 0.8);
            clip-path: polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%);
            animation: floatStar linear infinite;
            pointer-events: none;
            filter: drop-shadow(0 0 4px rgba(255,215,0,0.5));
        }

        @keyframes floatStar {
            0% {
                transform: translateY(110vh) translateX(-20px) rotate(0deg);
                opacity: 0.7;
            }
            100% {
                transform: translateY(-10vh) translateX(40px) rotate(360deg);
                opacity: 0;
            }
        }

        /* main wrapper - above stars */
        .login-wrapper {
            max-width: 440px;
            width: 100%;
            position: relative;
            z-index: 10;
            animation: fadeUp 0.6s ease;
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

        /* ========== GLASSMORPHISM CARD ========== */
        .login-box {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 48px;
            padding: 42px 34px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            transition: all 0.35s ease;
        }

        .login-box:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.5);
            box-shadow: 0 30px 55px rgba(0, 0, 0, 0.3);
        }

        /* Logo & heading – highly visible */
        .logo {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo h1 {
            font-size: 2.3rem;
            font-weight: 800;
            background: linear-gradient(125deg, #ffffff, #ffe6b0, #ffcd6b);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            letter-spacing: -0.3px;
            margin-bottom: 10px;
        }

        .logo p {
            color: rgba(255, 255, 255, 0.95);
            font-size: 1rem;
            font-weight: 600;
            background: rgba(0, 0, 0, 0.3);
            display: inline-block;
            padding: 5px 18px;
            border-radius: 40px;
            backdrop-filter: blur(4px);
        }

        /* Glass alerts */
        .alert {
            padding: 12px 18px;
            border-radius: 40px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-8px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.25);
            color: #ffe6e6;
            border-left: 4px solid #ff8a8a;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.25);
            color: #e8ffe8;
            border-left: 4px solid #8affa3;
        }

        /* Form group */
        .form-group {
            margin-bottom: 26px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: rgba(255, 255, 255, 0.95);
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.3px;
        }

        .form-group label i {
            margin-right: 8px;
            color: #ffdd88;
        }

        /* Glass inputs */
        .form-control {
            width: 100%;
            padding: 14px 22px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 60px;
            font-size: 1rem;
            font-weight: 500;
            color: #fff;
            transition: all 0.25s ease;
            backdrop-filter: blur(4px);
        }

        .form-control:focus {
            outline: none;
            border-color: #ffd966;
            background: rgba(255, 255, 255, 0.25);
            box-shadow: 0 0 0 5px rgba(255, 217, 102, 0.2);
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.65);
            font-weight: 400;
        }

        /* Glass button */
        .btn {
            padding: 14px 22px;
            border: none;
            border-radius: 60px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            background: linear-gradient(115deg, rgba(100, 110, 250, 0.8), rgba(52, 180, 235, 0.8));
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            color: white;
            letter-spacing: 0.5px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            background: linear-gradient(115deg, rgba(130, 145, 255, 0.9), rgba(72, 200, 255, 0.9));
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            border-color: rgba(255, 255, 255, 0.7);
        }

        /* Back link */
        .back-link {
            margin-top: 28px;
            text-align: center;
        }

        .back-link a {
            color: rgba(255, 252, 210, 0.9);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: 0.2s;
            background: rgba(0, 0, 0, 0.2);
            padding: 8px 20px;
            border-radius: 40px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-link a:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        /* Responsive */
        @media (max-width: 500px) {
            .login-box {
                padding: 32px 24px;
            }
            .logo h1 {
                font-size: 1.9rem;
            }
        }
    </style>
</head>
<body>

<!-- Floating stars background (generated with JS) -->
<div class="stars-container" id="starsContainer"></div>

<script>
    // Dynamically create floating stars with random sizes, positions, durations
    function createStars() {
        const container = document.getElementById('starsContainer');
        const starCount = 50;  // number of stars
        
        for (let i = 0; i < starCount; i++) {
            const star = document.createElement('div');
            star.classList.add('star');
            
            // random size between 15px and 65px
            const size = Math.floor(Math.random() * 50) + 15;
            star.style.width = `${size}px`;
            star.style.height = `${size}px`;
            
            // random horizontal position (0% to 100%)
            const leftPos = Math.random() * 100;
            star.style.left = `${leftPos}%`;
            
            // random animation duration (6s to 20s)
            const duration = Math.random() * 14 + 6;
            star.style.animationDuration = `${duration}s`;
            
            // random delay (0s to 8s)
            const delay = Math.random() * 8;
            star.style.animationDelay = `${delay}s`;
            
            // random opacity variation
            const opacity = 0.4 + Math.random() * 0.5;
            star.style.background = `rgba(255, 245, 160, ${opacity})`;
            
            container.appendChild(star);
        }
    }
    
    createStars();
</script>

<div class="login-wrapper">
    <div class="login-box">
        <div class="logo">
            <h1><i>FleetKE Admin</i></h1>
            <p><b>Administrator Login</b></p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
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
                <label for="email"><i class="fas fa-envelope"></i> Admin Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="admin@example.com" required autofocus>
            </div>
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i> Login as Admin
            </button>
        </form>

        <div class="back-link">
            <a href="<?php echo APP_URL; ?>/pages/login.php"><i class="fas fa-arrow-left"></i> Back to Customer Login</a>
        </div>
    </div>
</div>
</body>
</html>

