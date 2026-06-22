<?php
/**
 * ============================================================
 * Admin Dashboard - Main Dashboard
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Vehicle.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/PaymentStats.php';

// Check if user is admin
require_admin();

// Initialize classes
$vehicle = new Vehicle($conn);
$user = new User($conn);
$booking = new Booking($conn);
$payment = new PaymentStats($conn);

// Get statistics
$vehicle_stats = $vehicle->getVehicleStats();
$user_stats = $user->getUserStats();
$booking_stats = $booking->getBookingStats();
$payment_stats = $payment->getPaymentStats();

// Get recent bookings
$recent_bookings = $booking->getAllBookings(['limit' => 5]);

// Get all vehicles for management
$all_vehicles = $vehicle->getAllVehicles();

// Get available counties
global $KENYA_COUNTIES;

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_vehicle') {
    header('Content-Type: application/json');
    $vehicle_id = $_GET['vehicle_id'] ?? 0;
    $vehicle_data = $vehicle->getVehicleById($vehicle_id);
    echo json_encode($vehicle_data);
    exit;
}

if (isset($_POST['ajax']) && $_POST['ajax'] == 'delete_vehicle') {
    header('Content-Type: application/json');
    if (!verify_token($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }
    $vehicle_id = $_POST['vehicle_id'] ?? 0;
    $result = $vehicle->deleteVehicle($vehicle_id);
    echo json_encode(['success' => $result, 'message' => $result ? 'Vehicle deleted successfully' : 'Failed to delete vehicle']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - FleetKE Car Rental Kenya</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
        }

        /* Admin Layout */
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .admin-sidebar {
            width: 280px;
            background: linear-gradient(135deg, #1a2b3c 0%, #0f1a24 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .sidebar-header h2 {
            color: #34a853;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .sidebar-header p {
            color: #bdc1c6;
            font-size: 0.9rem;
        }

        .admin-profile {
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .profile-image {
            width: 50px;
            height: 50px;
            background: #34a853;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .profile-info h4 {
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .profile-info p {
            font-size: 0.85rem;
            color: #bdc1c6;
        }

        /* Sidebar Navigation */
        .sidebar-nav {
            padding: 1.5rem 0;
        }

        .nav-item {
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: #bdc1c6;
            transition: all 0.3s ease;
            cursor: pointer;
            border-left: 3px solid transparent;
        }

        .nav-item:hover {
            background: rgba(52, 168, 83, 0.1);
            color: white;
        }

        .nav-item.active {
            background: rgba(52, 168, 83, 0.2);
            color: white;
            border-left-color: #34a853;
        }

        .nav-item i {
            width: 20px;
            font-size: 1.2rem;
        }

        .nav-item span {
            flex: 1;
        }

        .nav-item .badge {
            background: #dc3545;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
        }

        /* Main Content */
        .admin-main {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            padding: 1rem 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            color: #202124;
        }

        .page-title p {
            color: #5f6368;
            font-size: 0.9rem;
        }

        .top-bar-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .notification-badge {
            position: relative;
        }

        .notification-badge i {
            font-size: 1.2rem;
            color: #5f6368;
            cursor: pointer;
        }

        .badge-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
            border-radius: 50%;
        }

        .date-display {
            background: #f0f2f5;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            color: #5f6368;
        }

        .date-display i {
            margin-right: 0.5rem;
            color: #1a73e8;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
        }

        .stat-info h3 {
            font-size: 1.8rem;
            margin-bottom: 0.25rem;
            color: #202124;
        }

        .stat-info p {
            color: #5f6368;
            font-size: 0.9rem;
        }

        .stat-change {
            margin-top: 0.5rem;
            font-size: 0.85rem;
        }

        .stat-change.positive {
            color: #34a853;
        }

        .stat-change.negative {
            color: #dc3545;
        }

        /* Quick Actions */
        .quick-actions {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .quick-actions h2 {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            color: #202124;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .action-btn {
            padding: 1rem;
            border: 1px solid #dadce0;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }

        .action-btn:hover {
            background: #f8f9fa;
            border-color: #1a73e8;
            transform: translateY(-2px);
        }

        .action-btn i {
            font-size: 1.5rem;
            color: #1a73e8;
            margin-bottom: 0.5rem;
            display: block;
        }

        .action-btn span {
            font-weight: 500;
            color: #202124;
        }

        /* Content Sections */
        .content-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-header h2 {
            font-size: 1.2rem;
            color: #202124;
        }

        .section-header a {
            color: #1a73e8;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .section-header a:hover {
            text-decoration: underline;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 1rem 0.5rem;
            color: #5f6368;
            font-weight: 600;
            font-size: 0.85rem;
            border-bottom: 2px solid #dadce0;
        }

        td {
            padding: 1rem 0.5rem;
            border-bottom: 1px solid #dadce0;
            color: #202124;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-badge.available, .status-badge.completed, .status-badge.paid, .status-badge.active {
            background: #e6f4ea;
            color: #34a853;
        }

        .status-badge.pending, .status-badge.maintenance {
            background: #fef7e0;
            color: #f9ab00;
        }

        .status-badge.rented {
            background: #e3f2fd;
            color: #1a73e8;
        }

        .status-badge.cancelled, .status-badge.suspended, .status-badge.damaged {
            background: #fce8e8;
            color: #dc3545;
        }

        .action-icons {
            display: flex;
            gap: 0.5rem;
        }

        .action-icons i {
            cursor: pointer;
            color: #5f6368;
            transition: color 0.3s ease;
            font-size: 1rem;
        }

        .action-icons i:hover {
            color: #1a73e8;
        }

        .action-icons i.fa-trash:hover {
            color: #dc3545;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            overflow-y: auto;
        }

        .modal-content {
            background: white;
            max-width: 600px;
            margin: 50px auto;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #dadce0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            color: #202124;
        }

        .close-modal {
            font-size: 1.5rem;
            cursor: pointer;
            color: #5f6368;
        }

        .close-modal:hover {
            color: #202124;
        }

        .modal-body {
            padding: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid #dadce0;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #5f6368;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #1a73e8;
            box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.2);
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #1a73e8;
            color: white;
        }

        .btn-primary:hover {
            background: #1557b0;
        }

        .btn-outline {
            background: white;
            border: 1px solid #dadce0;
            color: #5f6368;
        }

        .btn-outline:hover {
            background: #f8f9fa;
            border-color: #1a73e8;
            color: #1a73e8;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #b02a37;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        /* Search and Filters */
        .filters-bar {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #5f6368;
        }

        .search-box input {
            width: 100%;
            padding: 10px 10px 10px 35px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
        }

        .filter-select {
            padding: 10px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            min-width: 150px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
        }

        .pagination-info {
            color: #5f6368;
            font-size: 0.9rem;
        }

        .pagination-controls {
            display: flex;
            gap: 0.5rem;
        }

        /* Vehicle Cards */
        .vehicle-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .vehicle-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }

        .vehicle-image {
            height: 150px;
            background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
        }

        .vehicle-info {
            padding: 1rem;
        }

        .vehicle-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .vehicle-details {
            font-size: 0.85rem;
            color: #5f6368;
            margin-bottom: 0.5rem;
        }

        .vehicle-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1a73e8;
            margin-bottom: 0.5rem;
        }

        .vehicle-status {
            margin-bottom: 0.5rem;
        }

        /* Contact Section */
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .contact-card {
            background: ;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .contact-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }

        .contact-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            margin: 0 auto 1.5rem;
        }

        .contact-card h3 {
            color: #202124;
            margin-bottom: 1rem;
        }

        .contact-card p {
            color: #5f6368;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .contact-card .phone {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1a73e8;
            margin: 1rem 0;
        }

        .contact-social {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1rem;
        }

        .contact-social a {
            width: 40px;
            height: 40px;
            background: #f8f9fa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1a73e8;
            transition: all 0.3s ease;
        }

        .contact-social a:hover {
            background: #1a73e8;
            color: white;
        }

        /* Office Locations */
        .locations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .location-item {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
        }

        .location-item h4 {
            color: #202124;
            margin-bottom: 0.5rem;
        }

        .location-item p {
            color: #5f6368;
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .admin-sidebar {
                width: 80px;
            }

            .sidebar-header h2,
            .sidebar-header p,
            .profile-info,
            .nav-item span {
                display: none;
            }

            .admin-profile {
                justify-content: center;
            }

            .nav-item {
                justify-content: center;
                padding: 1rem;
            }

            .nav-item i {
                margin-right: 0;
            }

            .admin-main {
                margin-left: 80px;
            }
        }

        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .top-bar {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .filters-bar {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .contact-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Loading Spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #1a73e8;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            min-width: 300px;
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 1rem;
            z-index: 3000;
            animation: slideInRight 0.3s ease;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .toast.success {
            border-left: 4px solid #34a853;
        }

        .toast.error {
            border-left: 4px solid #dc3545;
        }

        .toast.warning {
            border-left: 4px solid #f9ab00;
        }

        .toast i {
            font-size: 1.5rem;
        }

        .toast.success i {
            color: #34a853;
        }

        .toast.error i {
            color: #dc3545;
        }

        .toast.warning i {
            color: #f9ab00;
        }

        .toast-content {
            flex: 1;
        }

        .toast-content h4 {
            color: #202124;
            margin-bottom: 0.25rem;
        }

        .toast-content p {
            color: #5f6368;
            font-size: 0.9rem;
        }

        .toast-close {
            cursor: pointer;
            color: #5f6368;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <h2>FleetKE</h2>
                <p>Admin Panel</p>
            </div>

            <div class="admin-profile">
                <div class="profile-image">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="profile-info">
                    <h4><?php echo htmlspecialchars($_SESSION['user_name']); ?></h4>
                    <p>Administrator</p>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item active" onclick="showSection('dashboard')">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </div>
                <div class="nav-item" onclick="showSection('vehicles')">
                    <i class="fas fa-car"></i>
                    <span>Vehicles</span>
                </div>
                <div class="nav-item" onclick="showSection('add-vehicle')">
                    <i class="fas fa-plus-circle"></i>
                    <span>Add Vehicle</span>
                </div>
                <div class="nav-item" onclick="showSection('bookings')">
                    <i class="fas fa-calendar-check"></i>
                    <span>Bookings</span>
                    <?php if (isset($booking_stats['pending']) && $booking_stats['pending'] > 0): ?>
                        <span class="badge"><?php echo $booking_stats['pending']; ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-item" onclick="showSection('users')">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </div>
                <div class="nav-item" onclick="showSection('payments')">
                    <i class="fas fa-credit-card"></i>
                    <span>Payments</span>
                </div>
                <div class="nav-item active" onclick="showSection('contacts')">
                    <i class="fas fa-address-book"></i>
                    <span>Contacts</span>
                </div>
                <div class="nav-item" onclick="showSection('reports')">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </div>
                <div class="nav-item" onclick="showSection('settings')">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </div>
                <div class="nav-item" onclick="window.location.href='<?php echo APP_URL; ?>/pages/logout.php'">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="page-title">
                    <h1 id="pageTitle">Dashboard</h1>
                    <p id="pageDescription">Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?>! Here's what's happening today.</p>
                </div>
                <div class="top-bar-actions">
                    <div class="notification-badge">
                        <i class="far fa-bell"></i>
                        <span class="badge-count">3</span>
                    </div>
                    <div class="date-display">
                        <i class="far fa-calendar-alt"></i>
                        <?php echo date('d M Y'); ?>
                    </div>
                </div>
            </div>

            <!-- Dashboard Section -->
            <div id="dashboard-section">
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-car"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $vehicle_stats['total'] ?? 0; ?></h3>
                            <p>Total Vehicles</p>
                            <div class="stat-change positive">
                                <i class="fas fa-arrow-up"></i> <?php echo $vehicle_stats['by_status']['available'] ?? 0; ?> available
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $user_stats['total_users'] ?? 0; ?></h3>
                            <p>Total Users</p>
                            <div class="stat-change positive">
                                <i class="fas fa-user-check"></i> <?php echo $user_stats['active_users'] ?? 0; ?> active
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $booking_stats['active'] ?? 0; ?></h3>
                            <p>Active Bookings</p>
                            <div class="stat-change">
                                <i class="fas fa-clock"></i> <?php echo $booking_stats['pending'] ?? 0; ?> pending
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo format_currency($payment_stats['revenue_this_month'] ?? 0); ?></h3>
                            <p>Revenue (This Month)</p>
                            <div class="stat-change positive">
                                <i class="fas fa-chart-line"></i> +12.5%
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="actions-grid">
                        <div class="action-btn" onclick="showSection('add-vehicle')">
                            <i class="fas fa-plus-circle"></i>
                            <span>Add Vehicle</span>
                        </div>
                        <div class="action-btn" onclick="showSection('vehicles')">
                            <i class="fas fa-edit"></i>
                            <span>Manage Vehicles</span>
                        </div>
                        <div class="action-btn" onclick="showSection('bookings')">
                            <i class="fas fa-eye"></i>
                            <span>View Bookings</span>
                        </div>
                        <div class="action-btn" onclick="showSection('users')">
                            <i class="fas fa-user-plus"></i>
                            <span>Manage Users</span>
                        </div>
                        <div class="action-btn" onclick="showSection('reports')">
                            <i class="fas fa-file-pdf"></i>
                            <span>Generate Report</span>
                        </div>
                        <div class="action-btn" onclick="showSection('contacts')">
                            <i class="fas fa-phone-alt"></i>
                            <span>Contact Support</span>
                        </div>
                    </div>
                </div>

                <!-- Recent Bookings -->
                <div class="content-section">
                    <div class="section-header">
                        <h2>Recent Bookings</h2>
                        <a href="#" onclick="showSection('bookings')">View All →</a>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Booking Ref</th>
                                    <th>Customer</th>
                                    <th>Vehicle</th>
                                    <th>Pickup Date</th>
                                    <th>Return Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_bookings)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 2rem; color: #5f6368;">
                                            <i class="fas fa-info-circle"></i> No recent bookings found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_bookings as $booking_item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($booking_item['booking_reference']); ?></td>
                                        <td><?php echo htmlspecialchars($booking_item['first_name'] . ' ' . $booking_item['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($booking_item['make'] . ' ' . $booking_item['model']); ?></td>
                                        <td><?php echo format_date($booking_item['pickup_date']); ?></td>
                                        <td><?php echo format_date($booking_item['return_date']); ?></td>
                                        <td><?php echo format_currency($booking_item['total_amount']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $booking_item['booking_status']; ?>">
                                                <?php echo ucfirst($booking_item['booking_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-icons">
                                                <i class="fas fa-eye" title="View Details" onclick="viewBooking(<?php echo $booking_item['id']; ?>)"></i>
                                                <i class="fas fa-edit" title="Edit" onclick="editBooking(<?php echo $booking_item['id']; ?>)"></i>
                                                <i class="fas fa-print" title="Print Invoice" onclick="printInvoice(<?php echo $booking_item['id']; ?>)"></i>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Vehicle Status Overview -->
                <div class="content-section">
                    <div class="section-header">
                        <h2>Vehicle Status Overview</h2>
                        <a href="#" onclick="showSection('vehicles')">Manage All Vehicles →</a>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Registration</th>
                                    <th>Vehicle</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Daily Rate</th>
                                    <th>Status</th>
                                    <th>Condition</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $recent_vehicles = array_slice($all_vehicles, 0, 5);
                                if (empty($recent_vehicles)): 
                                ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 2rem; color: #5f6368;">
                                            <i class="fas fa-info-circle"></i> No vehicles found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_vehicles as $v): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($v['registration_number']); ?></td>
                                        <td><?php echo htmlspecialchars($v['make'] . ' ' . $v['model'] . ' (' . $v['year'] . ')'); ?></td>
                                        <td><?php echo ucfirst($v['vehicle_type']); ?></td>
                                        <td><?php echo ucfirst($v['county']); ?></td>
                                        <td><?php echo format_currency($v['daily_rate']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $v['status']; ?>">
                                                <?php echo ucfirst($v['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo ucfirst($v['condition']); ?></td>
                                        <td>
                                            <div class="action-icons">
                                                <i class="fas fa-edit" title="Edit" onclick="editVehicle(<?php echo $v['id']; ?>)"></i>
                                                <i class="fas fa-history" title="History" onclick="viewVehicleHistory(<?php echo $v['id']; ?>)"></i>
                                                <i class="fas fa-ban" title="Mark Unavailable" onclick="toggleVehicleStatus(<?php echo $v['id']; ?>, '<?php echo $v['status']; ?>')"></i>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Vehicles Management Section -->
            <div id="vehicles-section" style="display: none;">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Manage Vehicles</h2>
                        <button class="btn btn-primary" onclick="showSection('add-vehicle')">
                            <i class="fas fa-plus"></i> Add New Vehicle
                        </button>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-bar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="vehicleSearch" placeholder="Search by registration, make, model..." onkeyup="filterVehicles()">
                        </div>
                        <select class="filter-select" id="statusFilter" onchange="filterVehicles()">
                            <option value="">All Status</option>
                            <option value="available">Available</option>
                            <option value="rented">Rented</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="damaged">Damaged</option>
                        </select>
                        <select class="filter-select" id="countyFilter" onchange="filterVehicles()">
                            <option value="">All Counties</option>
                            <?php foreach ($KENYA_COUNTIES as $key => $county): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($county['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline" onclick="resetFilters()">Reset Filters</button>
                    </div>

                    <!-- Vehicles Table -->
                    <div class="table-responsive">
                        <table id="vehiclesTable">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th>Registration</th>
                                    <th>Vehicle</th>
                                    <th>Type</th>
                                    <th>Year</th>
                                    <th>Location</th>
                                    <th>Daily Rate</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_vehicles)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; padding: 3rem; color: #5f6368;">
                                            <i class="fas fa-car" style="font-size: 3rem; margin-bottom: 1rem; display: block;"></i>
                                            No vehicles found. Click "Add New Vehicle" to get started.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_vehicles as $v): ?>
                                    <tr data-status="<?php echo $v['status']; ?>" data-county="<?php echo $v['county']; ?>" data-search="<?php echo strtolower($v['registration_number'] . ' ' . $v['make'] . ' ' . $v['model']); ?>">
                                        <td>
                                            <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%); border-radius: 4px; display: flex; align-items: center; justify-content: center; color: white;">
                                                <i class="fas fa-car"></i>
                                            </div>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($v['registration_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($v['make'] . ' ' . $v['model']); ?></td>
                                        <td><?php echo ucfirst($v['vehicle_type']); ?></td>
                                        <td><?php echo $v['year']; ?></td>
                                        <td><?php echo ucfirst($v['county']); ?></td>
                                        <td><?php echo format_currency($v['daily_rate']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $v['status']; ?>">
                                                <?php echo ucfirst($v['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-icons">
                                                <i class="fas fa-eye" title="View Details" onclick="viewVehicle(<?php echo $v['id']; ?>)"></i>
                                                <i class="fas fa-edit" title="Edit" onclick="editVehicle(<?php echo $v['id']; ?>)"></i>
                                                <i class="fas fa-copy" title="Duplicate" onclick="duplicateVehicle(<?php echo $v['id']; ?>)"></i>
                                                <i class="fas fa-trash" title="Delete" style="color: #dc3545;" onclick="deleteVehicle(<?php echo $v['id']; ?>)"></i>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="pagination">
                        <div class="pagination-info">
                            Showing <span id="showingStart">1</span> to <span id="showingEnd"><?php echo count($all_vehicles); ?></span> of <span id="totalVehicles"><?php echo count($all_vehicles); ?></span> vehicles
                        </div>
                        <div class="pagination-controls">
                            <button class="btn btn-outline btn-sm" onclick="previousPage()" id="prevBtn" disabled>Previous</button>
                            <button class="btn btn-primary btn-sm" id="page1">1</button>
                            <button class="btn btn-outline btn-sm" id="page2" style="display: none;">2</button>
                            <button class="btn btn-outline btn-sm" id="page3" style="display: none;">3</button>
                            <button class="btn btn-outline btn-sm" onclick="nextPage()" id="nextBtn">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add Vehicle Section -->
            <div id="add-vehicle-section" style="display: none;">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Add New Vehicle</h2>
                    </div>

                    <form id="add-vehicle-form" method="POST" action="<?php echo APP_URL; ?>/admin/add_vehicle.php" enctype="multipart/form-data" onsubmit="return validateVehicleForm()">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="registration_number">Registration Number *</label>
                                <input type="text" id="registration_number" name="registration_number" placeholder="e.g., KCA 123A" required pattern="[A-Z0-9\s]+" title="Only uppercase letters, numbers and spaces allowed">
                            </div>

                            <div class="form-group">
                                <label for="make">Make *</label>
                                <input type="text" id="make" name="make" placeholder="e.g., Toyota" required>
                            </div>

                            <div class="form-group">
                                <label for="model">Model *</label>
                                <input type="text" id="model" name="model" placeholder="e.g., Corolla" required>
                            </div>

                            <div class="form-group">
                                <label for="year">Year *</label>
                                <input type="number" id="year" name="year" min="2000" max="2026" value="<?php echo date('Y'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="color">Color *</label>
                                <input type="text" id="color" name="color" placeholder="e.g., White" required>
                            </div>

                            <div class="form-group">
                                <label for="vehicle_type">Vehicle Type *</label>
                                <select id="vehicle_type" name="vehicle_type" required>
                                    <option value="">Select Type</option>
                                    <option value="economy">Economy</option>
                                    <option value="compact">Compact</option>
                                    <option value="sedan">Sedan</option>
                                    <option value="suv">SUV</option>
                                    <option value="luxury">Luxury</option>
                                    <option value="van">Van</option>
                                    <option value="truck">Truck</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="fuel_type">Fuel Type *</label>
                                <select id="fuel_type" name="fuel_type" required>
                                    <option value="">Select Fuel Type</option>
                                    <option value="petrol">Petrol</option>
                                    <option value="diesel">Diesel</option>
                                    <option value="hybrid">Hybrid</option>
                                    <option value="electric">Electric</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="transmission">Transmission *</label>
                                <select id="transmission" name="transmission" required>
                                    <option value="">Select Transmission</option>
                                    <option value="manual">Manual</option>
                                    <option value="automatic">Automatic</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="seating_capacity">Seating Capacity *</label>
                                <input type="number" id="seating_capacity" name="seating_capacity" min="2" max="50" required>
                            </div>

                            <div class="form-group">
                                <label for="mileage">Current Mileage (km) *</label>
                                <input type="number" id="mileage" name="mileage" min="0" step="100" required>
                            </div>

                            <div class="form-group">
                                <label for="daily_rate">Daily Rate (KES) *</label>
                                <input type="number" id="daily_rate" name="daily_rate" min="500" step="100" required>
                            </div>

                            <div class="form-group">
                                <label for="weekly_rate">Weekly Rate (KES)</label>
                                <input type="number" id="weekly_rate" name="weekly_rate" min="0" step="100">
                            </div>

                            <div class="form-group">
                                <label for="monthly_rate">Monthly Rate (KES)</label>
                                <input type="number" id="monthly_rate" name="monthly_rate" min="0" step="100">
                            </div>

                            <div class="form-group">
                                <label for="county">County *</label>
                                <select id="county" name="county" required>
                                    <option value="">Select County</option>
                                    <?php foreach ($KENYA_COUNTIES as $key => $county): ?>
                                        <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($county['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="location">Specific Location</label>
                                <input type="text" id="location" name="location" placeholder="e.g., Westlands, Nairobi">
                            </div>

                            <div class="form-group full-width">
                                <label for="features">Features (comma separated)</label>
                                <textarea id="features" name="features" rows="3" placeholder="e.g., Bluetooth, GPS, Backup Camera, USB Port"></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" rows="4" placeholder="Additional details about the vehicle..."></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="vehicle_image">Vehicle Image</label>
                                <input type="file" id="vehicle_image" name="vehicle_image" accept="image/*">
                                <small style="color: #5f6368;">Max file size: 5MB. Allowed: JPG, PNG, GIF</small>
                            </div>
                        </div>

                        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                            <button type="button" class="btn btn-outline" onclick="showSection('vehicles')">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add Vehicle</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Contacts Section -->
            <div id="contacts-section" style="display: none;">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Contact Information</h2>
                    </div>

                    <div class="contact-grid">
                        <!-- Main Office -->
                        <div class="contact-card">
                            <div class="contact-icon">
                                <i class="fas fa-building"></i>
                            </div>
                            <h3>Head Office</h3>
                            <p><i class="fas fa-map-marker-alt"></i> Westlands, Nairobi</p>
                            <p><i class="fas fa-road"></i> Mpesi Lane, Off Waiyaki Way</p>
                            <p><i class="fas fa-building"></i> 3rd Floor, Suite 305</p>
                            <div class="phone">
                                <i class="fas fa-phone-alt"></i> +254 700 123 456
                            </div>
                            <p><i class="fas fa-envelope"></i> info@fleetke.com</p>
                            <div class="contact-social">
                                <a href="#"><i class="fab fa-facebook-f"></i></a>
                                <a href="#"><i class="fab fa-twitter"></i></a>
                                <a href="#"><i class="fab fa-instagram"></i></a>
                                <a href="#"><i class="fab fa-linkedin-in"></i></a>
                            </div>
                        </div>

                        <!-- Customer Support -->
                        <div class="contact-card">
                            <div class="contact-icon">
                                <i class="fas fa-headset"></i>
                            </div>
                            <h3>Customer Support</h3>
                            <p><i class="fas fa-phone-alt"></i> +254 700 789 012</p>
                            <p><i class="fas fa-phone-alt"></i> +254 700 789 013</p>
                            <p><i class="fas fa-envelope"></i> support@fleetke.com</p>
                            <p><i class="fas fa-clock"></i> 24/7 Support Available</p>
                            <div class="phone" style="margin-top: 1rem;">
                                <i class="fas fa-whatsapp"></i> +254 700 789 014
                            </div>
                        </div>

                        <!-- Emergency & Roadside -->
                        <div class="contact-card">
                            <div class="contact-icon">
                                <i class="fas fa-tools"></i>
                            </div>
                            <h3>Roadside Assistance</h3>
                            <p><i class="fas fa-phone-alt"></i> +254 700 789 015</p>
                            <p><i class="fas fa-phone-alt"></i> +254 700 789 016</p>
                            <p><i class="fas fa-envelope"></i> roadside@fleetke.com</p>
                            <p><i class="fas fa-clock"></i> Available 24/7</p>
                            <div class="phone" style="margin-top: 1rem; color: #dc3545;">
                                <i class="fas fa-exclamation-triangle"></i> Emergency: 911
                            </div>
                        </div>
                    </div>

                    <!-- Office Locations -->
                    <div style="margin-top: 3rem;">
                        <h3 style="margin-bottom: 1.5rem; color: #202124;">Our Locations Across Kenya</h3>
                        <div class="locations-grid">
                            <div class="location-item">
                                <h4><i class="fas fa-map-pin" style="color: #1a73e8;"></i> Nairobi</h4>
                                <p>Westlands, Mpesi Lane</p>
                                <p>Tel: +254 700 123 456</p>
                            </div>
                            <div class="location-item">
                                <h4><i class="fas fa-map-pin" style="color: #34a853;"></i> Mombasa</h4>
                                <p>Nyali, Links Road</p>
                                <p>Tel: +254 700 123 457</p>
                            </div>
                            <div class="location-item">
                                <h4><i class="fas fa-map-pin" style="color: #f9ab00;"></i> Kisumu</h4>
                                <p>CBD, Oginga Odinga Street</p>
                                <p>Tel: +254 700 123 458</p>
                            </div>
                            <div class="location-item">
                                <h4><i class="fas fa-map-pin" style="color: #ea4335;"></i> Nakuru</h4>
                                <p>CBD, Kenyatta Avenue</p>
                                <p>Tel: +254 700 123 459</p>
                            </div>
                            <div class="location-item">
                                <h4><i class="fas fa-map-pin" style="color: #9334e8;"></i> Eldoret</h4>
                                <p>CBD, Uganda Road</p>
                                <p>Tel: +254 700 123 460</p>
                            </div>
                            <div class="location-item">
                                <h4><i class="fas fa-map-pin" style="color: #34a853;"></i> Thika</h4>
                                <p>CBD, Kenyatta Highway</p>
                                <p>Tel: +254 700 123 461</p>
                            </div>
                        </div>
                    </div>

                    <!-- Business Hours -->
                    <div style="margin-top: 3rem; background: #f8f9fa; padding: 2rem; border-radius: 8px;">
                        <h3 style="margin-bottom: 1rem; color: #202124;">Business Hours</h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div>
                                <p><strong>Monday - Friday:</strong> 8:00 AM - 8:00 PM</p>
                                <p><strong>Saturday:</strong> 9:00 AM - 6:00 PM</p>
                            </div>
                            <div>
                                <p><strong>Sunday:</strong> 10:00 AM - 4:00 PM</p>
                                <p><strong>Public Holidays:</strong> 10:00 AM - 4:00 PM</p>
                            </div>
                            <div>
                                <p><strong>Emergency Support:</strong> 24/7</p>
                                <p><strong>Roadside Assistance:</strong> 24/7</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Other sections (bookings, users, payments, reports, settings) would go here -->
            <!-- For brevity, I'll include placeholders -->

            <div id="bookings-section" style="display: none;">
                <div class="content-section">
                    <h2>Bookings Management</h2>
                    <p style="color: #5f6368; margin-bottom: 1rem;">View and manage all bookings</p>
                    <!-- Bookings content would go here -->
                </div>
            </div>

            <div id="users-section" style="display: none;">
                <div class="content-section">
                    <h2>Users Management</h2>
                    <p style="color: #5f6368; margin-bottom: 1rem;">Manage registered users</p>
                    <!-- Users content would go here -->
                </div>
            </div>

            <div id="payments-section" style="display: none;">
                <div class="content-section">
                    <h2>Payments</h2>
                    <p style="color: #5f6368; margin-bottom: 1rem;">Track all payments and transactions</p>
                    <!-- Payments content would go here -->
                </div>
            </div>

            <div id="reports-section" style="display: none;">
                <div class="content-section">
                    <h2>Reports</h2>
                    <p style="color: #5f6368; margin-bottom: 1rem;">Generate and view reports</p>
                    <!-- Reports content would go here -->
                </div>
            </div>

            <div id="settings-section" style="display: none;">
                <div class="content-section">
                    <h2>System Settings</h2>
                    <p style="color: #5f6368; margin-bottom: 1rem;">Configure system settings</p>
                    <!-- Settings content would go here -->
                </div>
            </div>
        </main>
    </div>

    <!-- View Vehicle Modal -->
    <div id="viewVehicleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Vehicle Details</h3>
                <span class="close-modal" onclick="closeModal('viewVehicleModal')">&times;</span>
            </div>
            <div class="modal-body" id="vehicleDetails">
                <div class="spinner"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('viewVehicleModal')">Close</button>
                <button class="btn btn-primary" onclick="editVehicleFromModal()">Edit Vehicle</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
            </div>
            <div class="modal-body">
                <p style="text-align: center; padding: 1rem;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: #dc3545; margin-bottom: 1rem; display: block;"></i>
                    Are you sure you want to delete this vehicle? This action cannot be undone.
                </p>
                <input type="hidden" id="deleteVehicleId">
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
                <button class="btn btn-danger" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <script>
        // Section navigation
        function showSection(section) {
            // Hide all sections
            document.getElementById('dashboard-section').style.display = 'none';
            document.getElementById('vehicles-section').style.display = 'none';
            document.getElementById('add-vehicle-section').style.display = 'none';
            document.getElementById('bookings-section').style.display = 'none';
            document.getElementById('users-section').style.display = 'none';
            document.getElementById('payments-section').style.display = 'none';
            document.getElementById('contacts-section').style.display = 'none';
            document.getElementById('reports-section').style.display = 'none';
            document.getElementById('settings-section').style.display = 'none';
            
            // Show selected section
            document.getElementById(section + '-section').style.display = 'block';
            
            // Update active nav item
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            event.target.closest('.nav-item').classList.add('active');
            
            // Update page title
            const titles = {
                'dashboard': 'Dashboard',
                'vehicles': 'Vehicle Management',
                'add-vehicle': 'Add New Vehicle',
                'bookings': 'Booking Management',
                'users': 'User Management',
                'payments': 'Payment Management',
                'contacts': 'Contact Information',
                'reports': 'Reports',
                'settings': 'System Settings'
            };
            document.getElementById('pageTitle').textContent = titles[section];
            
            // Update page description
            const descriptions = {
                'dashboard': 'Welcome back! Here\'s what\'s happening today.',
                'vehicles': 'Manage your vehicle fleet',
                'add-vehicle': 'Add a new vehicle to your fleet',
                'bookings': 'View and manage all bookings',
                'users': 'Manage registered users',
                'payments': 'Track all payments and transactions',
                'contacts': 'View contact information',
                'reports': 'Generate and view reports',
                'settings': 'Configure system settings'
            };
            document.getElementById('pageDescription').textContent = descriptions[section];
        }

        // Vehicle management functions
        function viewVehicle(id) {
            document.getElementById('viewVehicleModal').style.display = 'block';
            document.getElementById('vehicleDetails').innerHTML = '<div class="spinner"></div>';
            
            // Fetch vehicle details via AJAX
            fetch(`?ajax=get_vehicle&vehicle_id=${id}`)
                .then(response => response.json())
                .then(data => {
                    let html = `
                        <div style="text-align: center; margin-bottom: 1rem;">
                            <div style="width: 100px; height: 100px; background: linear-gradient(135deg, #1a73e8 0%, #34a853 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto; color: white; font-size: 2rem;">
                                <i class="fas fa-car"></i>
                            </div>
                            <h3 style="margin-top: 1rem;">${data.make} ${data.model}</h3>
                            <p style="color: #5f6368;">${data.registration_number}</p>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div><strong>Year:</strong> ${data.year}</div>
                            <div><strong>Color:</strong> ${data.color}</div>
                            <div><strong>Type:</strong> ${data.vehicle_type}</div>
                            <div><strong>Fuel:</strong> ${data.fuel_type}</div>
                            <div><strong>Transmission:</strong> ${data.transmission}</div>
                            <div><strong>Seats:</strong> ${data.seating_capacity}</div>
                            <div><strong>Mileage:</strong> ${data.mileage} km</div>
                            <div><strong>County:</strong> ${data.county}</div>
                            <div><strong>Daily Rate:</strong> ${formatCurrency(data.daily_rate)}</div>
                            <div><strong>Status:</strong> <span class="status-badge ${data.status}">${data.status}</span></div>
                        </div>
                        <hr style="margin: 1rem 0;">
                        <div>
                            <strong>Features:</strong>
                            <p>${data.features || 'No features listed'}</p>
                        </div>
                        <div>
                            <strong>Description:</strong>
                            <p>${data.description || 'No description'}</p>
                        </div>
                    `;
                    document.getElementById('vehicleDetails').innerHTML = html;
                });
        }

        function editVehicle(id) {
            // Redirect to edit page or show edit modal
            window.location.href = `<?php echo APP_URL; ?>/admin/edit_vehicle.php?id=${id}`;
        }

        function deleteVehicle(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('deleteVehicleId').value = id;
        }

        function confirmDelete() {
            const id = document.getElementById('deleteVehicleId').value;
            
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax=delete_vehicle&vehicle_id=${id}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Success', data.message, 'success');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showToast('Error', data.message, 'error');
                }
                closeModal('deleteModal');
            });
        }

        function duplicateVehicle(id) {
            // Implement duplicate functionality
            showToast('Info', 'Duplicate functionality coming soon', 'info');
        }

        function toggleVehicleStatus(id, currentStatus) {
            const newStatus = currentStatus === 'available' ? 'maintenance' : 'available';
            // Implement status toggle via AJAX
            showToast('Info', `Change status to ${newStatus}`, 'info');
        }

        function viewVehicleHistory(id) {
            showToast('Info', 'Vehicle history view coming soon', 'info');
        }

        function editVehicleFromModal() {
            closeModal('viewVehicleModal');
            // Get vehicle ID from somewhere
        }

        // Booking functions
        function viewBooking(id) {
            showToast('Info', 'View booking details - Coming soon', 'info');
        }

        function editBooking(id) {
            showToast('Info', 'Edit booking - Coming soon', 'info');
        }

        function printInvoice(id) {
            showToast('Info', 'Print invoice - Coming soon', 'info');
        }

        // Modal functions
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Toast notification
        function showToast(title, message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <div class="toast-content">
                    <h4>${title}</h4>
                    <p>${message}</p>
                </div>
                <i class="fas fa-times toast-close" onclick="this.parentElement.remove()"></i>
            `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 5000);
        }

        // Format currency
        function formatCurrency(amount) {
            return 'Ksh ' + parseFloat(amount).toFixed(2);
        }

        // Vehicle filtering
        function filterVehicles() {
            const search = document.getElementById('vehicleSearch').value.toLowerCase();
            const status = document.getElementById('statusFilter').value;
            const county = document.getElementById('countyFilter').value;
            const rows = document.querySelectorAll('#vehiclesTable tbody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const rowSearch = row.getAttribute('data-search') || '';
                const rowStatus = row.getAttribute('data-status') || '';
                const rowCounty = row.getAttribute('data-county') || '';
                
                const matchesSearch = search === '' || rowSearch.includes(search);
                const matchesStatus = status === '' || rowStatus === status;
                const matchesCounty = county === '' || rowCounty === county;
                
                if (matchesSearch && matchesStatus && matchesCounty) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            document.getElementById('totalVehicles').textContent = visibleCount;
            document.getElementById('showingEnd').textContent = visibleCount;
        }

        function resetFilters() {
            document.getElementById('vehicleSearch').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('countyFilter').value = '';
            filterVehicles();
        }

        // Form validation
        function validateVehicleForm() {
            const reg = document.getElementById('registration_number').value;
            const make = document.getElementById('make').value;
            const model = document.getElementById('model').value;
            const dailyRate = document.getElementById('daily_rate').value;

            if (!reg || !make || !model || !dailyRate) {
                showToast('Error', 'Please fill in all required fields', 'error');
                return false;
            }

            if (dailyRate < 500) {
                showToast('Error', 'Daily rate must be at least Ksh 500', 'error');
                return false;
            }

            return true;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            // Check for URL parameters to show specific section
            const urlParams = new URLSearchParams(window.location.search);
            const section = urlParams.get('section');
            if (section) {
                showSection(section);
            }
        });
    </script>
</body>
</html>
