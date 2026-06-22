<?php
/**
 * ============================================================
 * M-Pesa Payment Simulation
 * ============================================================
 */
session_start();

require_once __DIR__ . '/../config/config.php';

// Check if payment session exists
if (!isset($_SESSION['payment'])) {
    header('Location: payment.php');
    exit();
}

$ref = $_GET['ref'] ?? $_SESSION['payment']['transaction_ref'];
$amount = $_SESSION['payment']['amount'];
$booking_id = $_SESSION['payment']['booking_id'];

// If form submitted, simulate successful payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update payment status to completed
    $update_payment = "UPDATE payments SET payment_status = 'completed' WHERE transaction_reference = ?";
    $stmt = $conn->prepare($update_payment);
    $stmt->bind_param("s", $ref);
    $stmt->execute();

    // Update booking status to paid
    $update_booking = "UPDATE bookings SET payment_status = 'paid' WHERE id = ?";
    $stmt = $conn->prepare($update_booking);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();

    // Clear payment session
    unset($_SESSION['payment']);

    // Redirect to confirmation page
    header('Location: booking-confirmation.php?booking_id=' . $booking_id);
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>M-Pesa Payment - FleetKE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .mpesa-box {
            background: white;
            max-width: 400px;
            width: 100%;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-align: center;
        }
        .mpesa-box i {
            font-size: 4rem;
            color: #1a73e8;
            margin-bottom: 20px;
        }
        .mpesa-box h2 {
            color: #202124;
            margin-bottom: 10px;
        }
        .mpesa-box p {
            color: #5f6368;
            margin-bottom: 5px;
        }
        .transaction-ref {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 1.1rem;
            margin: 20px 0;
            color: #1a73e8;
        }
        .btn {
            background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s ease;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .info {
            background: #e8f0fe;
            padding: 15px;
            border-radius: 8px;
            color: #1a73e8;
            text-align: left;
            margin: 20px 0;
        }
        .info i {
            font-size: 1rem;
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="mpesa-box">
        <i class="fas fa-mobile-alt"></i>
        <h2>M-Pesa Payment</h2>
        <p>Amount: <strong><?php echo 'Ksh ' . number_format($amount, 2); ?></strong></p>
        <div class="transaction-ref">
            <?php echo htmlspecialchars($ref); ?>
        </div>

        <div class="info">
            <i class="fas fa-info-circle"></i> A payment request has been sent to your phone.<br>
            <i class="fas fa-mobile-alt"></i> Enter your M-Pesa PIN to complete the transaction.
        </div>

        <form method="post">
            <!-- In a real integration, you would not ask for PIN here; this is just for demo -->
            <input type="text" placeholder="Enter M-Pesa PIN" style="width: 100%; padding: 12px; margin-bottom: 20px; border: 2px solid #e0e0e0; border-radius: 8px;" required>
            <button type="submit" class="btn">Confirm Payment</button>
        </form>

        <p style="margin-top: 20px; font-size: 0.9rem;">
            <a href="payment.php?booking_id=<?php echo $booking_id; ?>" style="color: #5f6368;">Cancel and go back</a>
        </p>
    </div>
</body>
</html>
