<?php
/**
 * Reset Password – Set new password using token
 */
error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
ob_start();

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');
define('APP_URL', getenv('APP_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: 'http://localhost/car-rental-kenya'));

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

session_start();

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

// Validate token
$token = $_GET['token'] ?? '';
if (empty($token)) {
    set_flash('danger', 'Invalid or missing reset token.');
    redirect(APP_URL . '/login.php');
}

// Fetch token from database (not used, not expired)
$stmt = $conn->prepare("
    SELECT user_id, expires_at
    FROM password_resets
    WHERE token = ? AND used = 0 AND expires_at > NOW()
");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$reset = $result->fetch_assoc();

if (!$reset) {
    set_flash('danger', 'This reset link is invalid or has expired.');
    redirect(APP_URL . '/login.php');
}

$user_id = $reset['user_id'];
$error = '';

// Process password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        try {
            $update = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $update->bind_param("si", $password_hash, $user_id);
            $update->execute();

            $mark = $conn->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
            $mark->bind_param("s", $token);
            $mark->execute();

            $conn->commit();

            set_flash('success', 'Your password has been reset successfully. You can now login.');
            redirect(APP_URL . '/login.php');
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Something went wrong. Please try again.';
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
    <title>Reset Password - FleetKE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Same styles as forgot-password page */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #12e712 0%, #4a0ed6 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .wrapper { max-width: 400px; width: 100%; }
        .box {
            background: lightblue;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .logo { text-align: center; margin-bottom: 30px; }
        .logo h1 { color: #1a73e8; font-size: 2rem; margin-bottom: 5px; }
        .logo p { color: #5f6368; font-size: 0.9rem; }
        .alert {
            padding: 12px 15px; border-radius: 8px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .alert-danger { background: #fee; color: #c33; border: 1px solid #fcc; }
        .alert-success { background: #e6f4ea; color: #34a853; border: 1px solid #b8e0c5; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block; margin-bottom: 8px; color: #5f6368;
            font-weight: 500; font-size: 0.9rem;
        }
        .form-group label i { margin-right: 5px; color: #1a73e8; }
        .form-control {
            width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 1rem; transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #1a73e8; outline: none;
            box-shadow: 0 0 0 3px rgba(26,115,232,0.1);
        }
        .btn {
            padding: 14px 30px; border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 600; cursor: pointer;
            transition: all 0.3s ease; display: inline-flex;
            align-items: center; justify-content: center; gap: 10px;
            text-decoration: none; width: 100%;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .back-link { margin-top: 20px; text-align: center; }
        .back-link a { color: #5f6368; text-decoration: none; }
        .back-link a:hover { color: #1a73e8; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="box">
            <div class="logo">
                <h1>FleetKE</h1>
                <p>Choose a new password</p>
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
                    <label for="password"><i class="fas fa-lock"></i> New Password</label>
                    <input type="password" class="form-control" id="password" name="password"
                           placeholder="At least 6 characters" required minlength="6">
                </div>
                <div class="form-group">
                    <label for="confirm_password"><i class="fas fa-check-circle"></i> Confirm Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Reset Password
                </button>
            </form>

            <div class="back-link">
                <a href="<?php echo APP_URL; ?>/login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>

