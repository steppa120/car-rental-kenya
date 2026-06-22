<?php
// pages/my-bookings.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Bookings - FleetKE</title>
    <style>
        body { font-family: Arial; background: #f0f2f5; padding: 20px; text-align: center; }
        .container { background: white; max-width: 600px; margin: 0 auto; padding: 30px; border-radius: 10px; }
        h1 { color: #1a73e8; }
    </style>
</head>
<body>
    <div class="container">
        <h1>My Bookings</h1>
        <p>This page will list your bookings. (Under construction)</p>
        <a href="browse.php" class="btn" style="background:#1a73e8; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;">Browse Vehicles</a>
    </div>
</body>
</html>