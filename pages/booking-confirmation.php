<?php
/**
 * ============================================================
 * Booking Confirmation Page – Premium Glassmorphism
 * ============================================================
 */
error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
session_start();

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

function is_logged_in() {
    return isset($_SESSION['user_id']);
}
function format_currency($amount) {
    return 'Ksh ' . number_format($amount, 2);
}
function format_date_short($date) {
    return date('d M Y', strtotime($date));
}

// Login check
if (!is_logged_in()) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please login to view booking confirmation'];
    header("Location: " . APP_URL . "/pages/login.php");
    exit();
}

$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$booking = null;

if ($booking_id > 0) {
    $query = "SELECT b.*, v.make, v.model, v.registration_number 
              FROM bookings b
              LEFT JOIN vehicles v ON b.vehicle_id = v.id
              WHERE b.id = ? AND b.user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed | FleetKE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            background: radial-gradient(ellipse at 30% 40%, #0f172a, #1e1b4b, #311042);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
            color: #f8fafc;
        }

        /* ----- BUBBLES GLASSMORPHISM CONTAINER ----- */
        .bubbles-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .bubble {
            position: absolute;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(4px);
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            pointer-events: none;
            animation: floatBubble linear infinite;
        }

        @keyframes floatBubble {
            0% {
                transform: translateY(100vh) scale(0.8);
                opacity: 0;
            }
            20% {
                opacity: 0.5;
            }
            80% {
                opacity: 0.5;
            }
            100% {
                transform: translateY(-20vh) scale(1.2);
                opacity: 0;
            }
        }

        /* ----- GLASS CARD ----- */
        .confirm-box {
            position: relative;
            z-index: 10;
            max-width: 550px;
            width: 100%;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 36px;
            padding: 3rem 2.5rem;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.1);
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: cardEntrance 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes cardEntrance {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Animated Success Checkmark */
        .success-checkmark {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            background: rgba(34, 197, 94, 0.15);
            border: 2px solid rgba(34, 197, 94, 0.4);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 20px rgba(34, 197, 94, 0.2);
            animation: pulseCheck 2s infinite ease-in-out;
        }

        .success-checkmark i {
            font-size: 2.5rem;
            color: #4ade80;
            animation: popCheck 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        @keyframes pulseCheck {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 20px rgba(34, 197, 94, 0.2);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 30px rgba(34, 197, 94, 0.4);
            }
        }

        @keyframes popCheck {
            from {
                transform: scale(0) rotate(-45deg);
                opacity: 0;
            }
            to {
                transform: scale(1) rotate(0);
                opacity: 1;
            }
        }

        .confirm-box h1 {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 12px;
            background: linear-gradient(135deg, #ffffff, #c084fc, #a855f7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .confirm-box p.subtitle {
            color: #cbd5e1;
            font-size: 1.05rem;
            margin-bottom: 30px;
            line-height: 1.5;
        }

        /* Detail List */
        .details-list {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 1.5rem;
            margin-bottom: 35px;
            text-align: left;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #94a3b8;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .detail-value {
            color: #f1f5f9;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .detail-value.highlight {
            color: #fbbf24;
        }

        /* Buttons */
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 40px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .btn-primary {
            background: linear-gradient(135deg, #a855f7 0%, #7c3aed 100%);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.35);
            filter: brightness(1.05);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #e2e8f0;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            transform: translateY(-2px);
        }

        @media (max-width: 500px) {
            .confirm-box {
                padding: 2rem 1.5rem;
            }
            .confirm-box h1 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>

<!-- Bubbles Background -->
<div class="bubbles-container" id="bubblesContainer"></div>

<div class="confirm-box">
    <div class="success-checkmark">
        <i class="fas fa-check"></i>
    </div>
    <h1>Booking Confirmed!</h1>
    <p class="subtitle">Thank you for choosing FleetKE. Your booking has been successfully processed and verified.</p>

    <div class="details-list">
        <?php if ($booking): ?>
            <div class="detail-row">
                <span class="detail-label">Reference</span>
                <span class="detail-value highlight"><?php echo htmlspecialchars($booking['booking_reference']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Vehicle</span>
                <span class="detail-value"><?php echo htmlspecialchars($booking['make'] . ' ' . $booking['model']); ?> (<?php echo htmlspecialchars($booking['registration_number']); ?>)</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Pickup Date</span>
                <span class="detail-value"><?php echo format_date_short($booking['pickup_date']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Return Date</span>
                <span class="detail-value"><?php echo format_date_short($booking['return_date']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Total Amount</span>
                <span class="detail-value highlight"><?php echo format_currency($booking['total_amount']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Payment Status</span>
                <span class="detail-value"><?php echo ucfirst(htmlspecialchars($booking['payment_status'])); ?></span>
            </div>
        <?php else: ?>
            <div class="detail-row">
                <span class="detail-label">Booking ID</span>
                <span class="detail-value highlight">#<?php echo htmlspecialchars($booking_id); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Status</span>
                <span class="detail-value">Confirmed</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="btn-group">
        <a href="my-bookings.php" class="btn btn-primary">
            <i class="fas fa-calendar-alt"></i> View My Bookings
        </a>
        <a href="browse.php" class="btn btn-secondary">
            <i class="fas fa-search"></i> Browse More Vehicles
        </a>
    </div>
</div>

<script>
    (function() {
        const bubblesContainer = document.getElementById('bubblesContainer');
        if (!bubblesContainer) return;

        const BUBBLE_COUNT = 15;
        for (let i = 0; i < BUBBLE_COUNT; i++) {
            const bubble = document.createElement('div');
            bubble.classList.add('bubble');

            const size = Math.floor(Math.random() * 120 + 30);
            bubble.style.width = size + 'px';
            bubble.style.height = size + 'px';
            bubble.style.left = Math.random() * 100 + '%';

            const duration = Math.random() * 12 + 8;
            bubble.style.animationDuration = duration + 's';

            const delay = Math.random() * 6;
            bubble.style.animationDelay = delay + 's';

            bubblesContainer.appendChild(bubble);
        }
    })();
</script>
</body>
</html>
