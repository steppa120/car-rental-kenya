<?php
/**
 * ============================================================
 * My Bookings – Lists all bookings for the logged-in user
 * with real-time status updates + Falls Glassmorphism
 * ============================================================
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);

// Configuration
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

// Helper functions
function is_logged_in() {
    return isset($_SESSION['user_id']);
}
function format_currency($amount) {
    return 'Ksh ' . number_format($amount, 2);
}
function format_date_short($date) {
    return date('d M Y', strtotime($date));
}

// Check login
if (!is_logged_in()) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please login to view your bookings'];
    header("Location: " . APP_URL . "/pages/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch all bookings for this user with vehicle and payment info
$query = "
    SELECT 
        b.*,
        v.make, v.model, v.registration_number, v.status as vehicle_status,
        p.payment_status, p.transaction_reference, p.id as payment_id
    FROM bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.id
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle flash messages
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Bookings | FleetKE</title>
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
            background: radial-gradient(ellipse at 30% 40%, #0a0f2a 0%, #34bd2f 100%);
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* ----- FALLS GLASSMORPHISM (falling glass particles) ----- */
        .falls-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .fall-particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(4px);
            border-radius: 8px 2px 12px 2px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            animation: fall linear infinite;
            pointer-events: none;
        }

        /* Different shapes for variety */
        .fall-particle.square {
            border-radius: 6px;
            transform: rotate(45deg);
        }
        .fall-particle.circle {
            border-radius: 50%;
        }
        .fall-particle.triangle {
            clip-path: polygon(50% 0%, 0% 100%, 100% 100%);
            border-radius: 0;
        }

        @keyframes fall {
            0% {
                transform: translateY(-20vh) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 0.5;
            }
            90% {
                opacity: 0.5;
            }
            100% {
                transform: translateY(120vh) rotate(360deg);
                opacity: 0;
            }
        }

        /* ----- GLASS CARD (container) ----- */
        .container {
            position: relative;
            z-index: 10;
            max-width: 1300px;
            margin: 0 auto;
            background: rgba(20, 30, 55, 0.45);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-radius: 36px;
            padding: 2rem;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        h1 {
            color: #ffd966;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.9rem;
            text-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        h1 i {
            color: #ffd966;
        }

        .flash {
            padding: 12px 18px;
            border-radius: 28px;
            margin-bottom: 20px;
            backdrop-filter: blur(4px);
        }
        .flash.success {
            background: rgba(52, 168, 83, 0.3);
            color: #d4edda;
            border: 1px solid #28a745;
        }
        .flash.error {
            background: rgba(220, 53, 69, 0.3);
            color: #f8d7da;
            border: 1px solid #dc3545;
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: 24px;
            background: rgba(0, 0, 0, 0.2);
            padding: 1px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            backdrop-filter: blur(2px);
        }

        th {
            background: rgba(0, 0, 0, 0.4);
            padding: 14px 12px;
            text-align: left;
            font-weight: 600;
            color: #ffd966;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        td {
            padding: 14px 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
        }

        tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pending { background: rgba(255, 193, 7, 0.25); color: #ffecb3; border: 1px solid #ffc107; }
        .status-paid, .status-completed { background: rgba(40, 167, 69, 0.25); color: #d4edda; border: 1px solid #28a745; }
        .status-cancelled { background: rgba(220, 53, 69, 0.25); color: #f8d7da; border: 1px solid #dc3545; }
        .status-returned, .status-available { background: rgba(0, 123, 255, 0.25); color: #cce5ff; border: 1px solid #007bff; }
        .status-rented { background: rgba(220, 53, 69, 0.25); color: #f8d7da; border: 1px solid #dc3545; }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 40px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ffb347, #ff8c00);
            color: #0a0f2a;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }

        .btn-success {
            background: #34a853;
            color: white;
        }
        .btn-success:hover {
            background: #2d8659;
            transform: translateY(-2px);
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
        }
        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #ffd966;
        }

        .empty-state {
            text-align: center;
            padding: 50px;
            color: rgba(255,255,255,0.8);
        }
        .empty-state i {
            font-size: 4rem;
            color: rgba(255, 217, 102, 0.6);
            margin-bottom: 15px;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .refresh-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            color: #ffd966;
            padding: 10px 18px;
            border-radius: 40px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 1000;
            border: 1px solid rgba(255, 217, 102, 0.3);
        }

        .refresh-indicator i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .notification {
            position: fixed;
            top: 80px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 40px;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(8px);
            color: #ffd966;
            z-index: 2000;
            animation: slideIn 0.3s ease;
            border-left: 4px solid #ffd966;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            th, td {
                padding: 8px 6px;
                font-size: 0.8rem;
            }
            .btn {
                padding: 5px 10px;
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>

<!-- Falls Glassmorphism Background -->
<div class="falls-container" id="fallsContainer"></div>

<div class="container">
    <h1><i class="fas fa-calendar-check"></i> My Bookings</h1>

    <?php if ($flash): ?>
        <div class="flash <?php echo $flash['type']; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div id="bookingsContainer">
        <?php if (empty($bookings)): ?>
            <div class="empty-state">
                <i class="fas fa-car"></i>
                <h3>No bookings found</h3>
                <p>You haven't made any bookings yet.</p>
                <a href="browse.php" class="btn btn-primary" style="margin-top: 15px;"><i class="fas fa-search"></i> Browse Cars</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="bookingsTable">
                    <thead>
                        <tr>
                            <th>Booking Ref</th>
                            <th>Vehicle</th>
                            <th>Pick-up</th>
                            <th>Return</th>
                            <th>Total</th>
                            <th>Payment Status</th>
                            <th>Vehicle Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): 
                            $status = $booking['payment_status'] ?? 'pending';
                            $badge_class = 'status-' . $status;
                            $vehicle_status = $booking['vehicle_status'] ?? 'available';
                            $vehicle_badge_class = 'status-' . $vehicle_status;
                        ?>
                        <tr data-booking-id="<?php echo $booking['id']; ?>" data-payment-status="<?php echo $status; ?>" data-vehicle-status="<?php echo $vehicle_status; ?>">
                            <td><strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></td>
                            <td><?php echo htmlspecialchars($booking['make'] . ' ' . $booking['model']); ?><br>
                                <small style="color: rgba(255,255,255,0.6);"><?php echo htmlspecialchars($booking['registration_number']); ?></small>
                             </td>
                            <td><?php echo format_date_short($booking['pickup_date']); ?></td>
                            <td><?php echo format_date_short($booking['return_date']); ?></td>
                            <td><?php echo format_currency($booking['total_amount']); ?></td>
                            <td><span class="status-badge <?php echo $badge_class; ?>"><?php echo ucfirst($status); ?></span></td>
                            <td><span class="status-badge <?php echo $vehicle_badge_class; ?>"><?php echo ucfirst($vehicle_status); ?></span></td>
                            <td class="actions">
                                <?php if ($status === 'completed' || $status === 'paid'): ?>
                                    <?php if (!empty($booking['transaction_reference'])): ?>
                                        <a href="payment-receipt.php?booking_id=<?php echo $booking['id']; ?>&ref=<?php echo urlencode($booking['transaction_reference']); ?>" class="btn btn-success"><i class="fas fa-receipt"></i> Receipt</a>
                                    <?php endif; ?>
                                <?php elseif ($status === 'pending'): ?>
                                    <a href="mpesa-payment.php?booking_id=<?php echo $booking['id']; ?>" class="btn btn-primary"><i class="fas fa-money-bill"></i> Pay Now</a>
                                <?php endif; ?>
                                <a href="booking-details.php?booking_id=<?php echo $booking['id']; ?>" class="btn btn-outline"><i class="fas fa-eye"></i> View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div style="margin-top: 30px; text-align: center;">
        <a href="browse.php" class="btn btn-primary"><i class="fas fa-search"></i> Browse More Cars</a>
    </div>
</div>

<div class="refresh-indicator" id="refreshIndicator" style="display: none;">
    <i class="fas fa-sync-alt"></i>
    <span>Checking for updates...</span>
</div>

<script>
    (function() {
        // Create falling glass particles (Falls Glassmorphism)
        const fallsContainer = document.getElementById('fallsContainer');
        if (fallsContainer) {
            const PARTICLE_COUNT = 45;
            const shapes = ['square', 'circle', 'triangle'];
            for (let i = 0; i < PARTICLE_COUNT; i++) {
                const particle = document.createElement('div');
                particle.classList.add('fall-particle');
                // random shape
                const shape = shapes[Math.floor(Math.random() * shapes.length)];
                particle.classList.add(shape);
                // random size 8px to 35px
                const size = Math.floor(Math.random() * 30 + 8);
                particle.style.width = size + 'px';
                particle.style.height = size + 'px';
                // random left position
                particle.style.left = Math.random() * 100 + '%';
                // random animation duration (5s to 18s)
                const duration = Math.random() * 13 + 5;
                particle.style.animationDuration = duration + 's';
                // random delay
                const delay = Math.random() * 8;
                particle.style.animationDelay = delay + 's';
                fallsContainer.appendChild(particle);
            }
        }
    })();

    let lastUpdateTime = <?php echo time(); ?>;
    
    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `notification`;
        notification.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'}"></i> ${message}`;
        document.body.appendChild(notification);
        setTimeout(() => notification.remove(), 3000);
    }
    
    function checkStatusUpdates() {
        const refreshIndicator = document.getElementById('refreshIndicator');
        refreshIndicator.style.display = 'flex';
        
        fetch('check-booking-status.php?user_id=<?php echo $user_id; ?>&last_check=' + lastUpdateTime)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.updates && data.updates.length > 0) {
                    let hasUpdates = false;
                    
                    data.updates.forEach(update => {
                        const row = document.querySelector(`tr[data-booking-id="${update.booking_id}"]`);
                        if (row) {
                            const paymentStatusCell = row.querySelector('td:nth-child(6) .status-badge');
                            const vehicleStatusCell = row.querySelector('td:nth-child(7) .status-badge');
                            
                            if (paymentStatusCell && update.payment_status !== row.dataset.paymentStatus) {
                                paymentStatusCell.textContent = update.payment_status.charAt(0).toUpperCase() + update.payment_status.slice(1);
                                paymentStatusCell.className = `status-badge status-${update.payment_status}`;
                                row.dataset.paymentStatus = update.payment_status;
                                hasUpdates = true;
                                
                                // Update actions based on payment status
                                const actionsCell = row.querySelector('td:last-child');
                                if (update.payment_status === 'completed' || update.payment_status === 'paid') {
                                    if (!actionsCell.querySelector('.btn-success')) {
                                        actionsCell.innerHTML = `<a href="payment-receipt.php?booking_id=${update.booking_id}&ref=${update.transaction_reference || ''}" class="btn btn-success"><i class="fas fa-receipt"></i> Receipt</a> <a href="booking-details.php?booking_id=${update.booking_id}" class="btn btn-outline"><i class="fas fa-eye"></i> View</a>`;
                                    }
                                }
                            }
                            
                            if (vehicleStatusCell && update.vehicle_status !== row.dataset.vehicleStatus) {
                                const statusText = update.vehicle_status.charAt(0).toUpperCase() + update.vehicle_status.slice(1);
                                vehicleStatusCell.textContent = statusText;
                                vehicleStatusCell.className = `status-badge status-${update.vehicle_status}`;
                                row.dataset.vehicleStatus = update.vehicle_status;
                                hasUpdates = true;
                            }
                        }
                    });
                    
                    if (hasUpdates) {
                        showNotification('Booking status updated!', 'success');
                    }
                }
                lastUpdateTime = data.timestamp || Date.now() / 1000;
            })
            .catch(error => {
                console.error('Status check error:', error);
            })
            .finally(() => {
                setTimeout(() => {
                    refreshIndicator.style.display = 'none';
                }, 1000);
            });
    }
    
    // Check for updates every 10 seconds
    setInterval(checkStatusUpdates, 10000);
    setTimeout(checkStatusUpdates, 3000);
</script>
</body>
</html>
<?php $conn->close(); ?>

