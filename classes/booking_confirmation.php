<?php
// pages/booking-confirmation.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$booking_id = $_GET['booking_id'] ?? 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Booking Confirmed - FleetKE</title>
    <style>
        body { font-family: Arial; background: #f0f2f5; text-align: center; padding: 50px; }
        .box { background: white; max-width: 500px; margin: 0 auto; padding: 30px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        h1 { color: #34a853; }
        .btn { background: #1a73e8; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>✅ Booking Confirmed!</h1>
        <p>Your booking #<?php echo htmlspecialchars($booking_id); ?> has been confirmed.</p>
        <a href="my-bookings.php" class="btn">View My Bookings</a>
    </div>
</body>
</html>