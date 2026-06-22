<?php
// admin_view_only.php
if (session_status() === PHP_SESSION_NONE) session_start();

function is_admin_view_only() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'
           && isset($_SESSION['admin_view_only']) && $_SESSION['admin_view_only'] === true;
}

// Redirect if admin tries to access a booking page
if (is_admin_view_only() && basename($_SERVER['SCRIPT_NAME']) === 'booking.php') {
    header('Location: admin_login.php');
    exit();
}
?>