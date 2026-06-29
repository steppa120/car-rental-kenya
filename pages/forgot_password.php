<?php
/**
 * Forgot Password – Request password reset link
 * Works with the same database and session as login.php
 * Location: pages/forgot_password.php
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

// ----- Session start -----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ----- Helper functions -----
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

// ----- Ensure users table exists (required for foreign key) -----
$users_check = $conn->query("SHOW TABLES LIKE 'users'");
if (!$users_check || $users_check->num_rows == 0) {
    // Create minimal users table if missing (prevents foreign key errors)
    $create_users = "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($create_users);
}

// ----- Create password_resets table safely (without foreign key first) -----
$table_check = $conn->query("SHOW TABLES LIKE 'password_resets'");
if (!$table_check || $table_check->num_rows == 0) {
    $create_sql = "CREATE TABLE IF NOT EXISTS `password_resets` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL,
        `token` VARCHAR(255) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_token` (`token`),
        KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!$conn->query($create_sql)) {
        die("Failed to create password_resets table: " . $conn->error);
    }
    
    // Try to add foreign key (if users table exists and has id column)
    $fk_add = "ALTER TABLE `password_resets` ADD CONSTRAINT `fk_password_resets_user` 
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE";
    $conn->query($fk_add); // Ignore errors (e.g., if foreign key already exists or users table incomplete)
}

$email = '';
$error = '';

// ----- Process form submission -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Get user ID if exists and active
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND status = 'active'");
        if (!$stmt) {
            $error = 'Database error: unable to prepare statement.';
            error_log("Prepare failed: " . $conn->error);
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user) {
                $user_id = $user['id'];

                // Generate token and expiry (1 hour)
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Start transaction
                $conn->begin_transaction();
                try {
                    // Delete old tokens for this user
                    $delete = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                    if (!$delete) {
                        throw new Exception("Delete prepare failed: " . $conn->error);
                    }
                    $delete->bind_param("i", $user_id);
                    if (!$delete->execute()) {
                        throw new Exception("Delete execute failed: " . $delete->error);
                    }
                    $delete->close();

                    // Insert new token
                    $insert = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
                    if (!$insert) {
                        throw new Exception("Insert prepare failed: " . $conn->error);
                    }
                    $insert->bind_param("iss", $user_id, $token, $expires);
                    if (!$insert->execute()) {
                        throw new Exception("Insert execute failed: " . $insert->error);
                    }
                    $insert->close();

                    $conn->commit();

                    // Build reset link
                    $reset_link = APP_URL . '/pages/reset-password.php?token=' . urlencode($token);

                    // Send email
                    $to = $email;
                    $subject = 'Password Reset - FleetKE Car Rental';
                    $message_body = "Hello,\n\n";
                    $message_body .= "You requested a password reset. Click the link below to reset your password:\n";
                    $message_body .= $reset_link . "\n\n";
                    $message_body .= "This link will expire in 1 hour.\n";
                    $message_body .= "If you did not request this, please ignore this email.\n\n";
                    $message_body .= "Regards,\nFleetKE Team";

                    $headers = "From: no-reply@" . parse_url(APP_URL, PHP_URL_HOST) . "\r\n" .
                               "Reply-To: no-reply@" . parse_url(APP_URL, PHP_URL_HOST) . "\r\n" .
                               "X-Mailer: PHP/" . phpversion();

                    if (mail($to, $subject, $message_body, $headers)) {
                        set_flash('success', 'Password reset link has been sent to your email.');
                    } else {
                        error_log("Mail failed for $email");
                        set_flash('success', 'If your email exists, you will receive a reset link shortly.');
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Database error in forgot_password: " . $e->getMessage());
                    $error = 'Something went wrong. Please try again later.';
                }
            } else {
                // No user found – generic message for security
                set_flash('success', 'If your email exists, you will receive a reset link shortly.');
            }
            $stmt->close();
        }

        // Redirect back to the same page to show flash message
        redirect(APP_URL . '/pages/forgot_password.php');
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
    <title>Forgot Password - FleetKE</title>
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
            background: linear-gradient(135deg, #1909f5 0%, #0c0c0c 50%, #e40f67 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* ----- Sprinkling Drops (water droplets) ----- */
        .drops-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .drop {
            position: absolute;
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(2px);
            border-radius: 50%;
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.4);
            animation: fallDrop linear infinite;
            pointer-events: none;
        }

        @keyframes fallDrop {
            0% {
                transform: translateY(-10vh) translateX(0) rotate(0deg);
                opacity: 0.5;
            }
            100% {
                transform: translateY(110vh) translateX(30px) rotate(20deg);
                opacity: 0;
            }
        }

        /* Main wrapper - above drops */
        .wrapper {
            max-width: 450px;
            width: 100%;
            position: relative;
            z-index: 10;
            animation: fadeUp 0.6s ease;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(25px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ========== GLASSMORPHISM CARD ========== */
        .box {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 48px;
            padding: 42px 34px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            transition: all 0.35s ease;
        }

        .box:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.5);
            box-shadow: 0 30px 55px rgba(0, 0, 0, 0.3);
        }

        /* Logo & heading – highly visible */
        .logo {
            text-align: center;
            margin-bottom: 28px;
        }

        .logo h1 {
            font-size: 2.6rem;
            font-weight: 800;
            background: linear-gradient(125deg, #ffffff, #ffe6b0, #ffcd6b);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .logo p {
            color: rgba(255, 255, 255, 0.95);
            font-size: 1rem;
            font-weight: 500;
            background: rgba(0, 0, 0, 0.25);
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
            .box {
                padding: 32px 24px;
            }
            .logo h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>

<!-- Sprinkling Drops (water droplets) background -->
<div class="drops-container" id="dropsContainer"></div>

<script>
    // Dynamically create falling water droplets with random sizes, positions, speeds
    function createDrops() {
        const container = document.getElementById('dropsContainer');
        const dropCount = 65;  // number of droplets
        
        for (let i = 0; i < dropCount; i++) {
            const drop = document.createElement('div');
            drop.classList.add('drop');
            
            // random size between 8px and 55px
            const size = Math.floor(Math.random() * 48) + 8;
            drop.style.width = `${size}px`;
            drop.style.height = `${size}px`;
            
            // random horizontal position (0% to 100%)
            const leftPos = Math.random() * 100;
            drop.style.left = `${leftPos}%`;
            
            // random animation duration (4s to 14s)
            const duration = Math.random() * 10 + 4;
            drop.style.animationDuration = `${duration}s`;
            
            // random delay (0s to 8s)
            const delay = Math.random() * 8;
            drop.style.animationDelay = `${delay}s`;
            
            // random opacity variation
            const opacity = 0.2 + Math.random() * 0.4;
            drop.style.background = `rgba(255, 255, 255, ${opacity})`;
            drop.style.backdropFilter = `blur(${Math.random() * 3 + 1}px)`;
            
            container.appendChild(drop);
        }
    }
    
    createDrops();
</script>

<div class="wrapper">
    <div class="box">
        <div class="logo">
            <h1><b>FleetKE</b></h1>
            <p>Reset your password</p>
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
                <input type="email" class="form-control" id="email" name="email"
                       value="<?php echo htmlspecialchars($email); ?>" placeholder="your@email.com" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send Reset Link
            </button>
        </form>

        <div class="back-link">
            <a href="<?php echo APP_URL; ?>/pages/login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>
</div>
</body>
</html>

