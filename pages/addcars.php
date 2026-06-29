<?php
/**
 * addcar.php
 * Standalone page for adding a new vehicle (admin only)
 */

error_reporting(E_ALL);
ini_set('display_errors', getenv('RENDER') ? 0 : 1);
ob_start();

// ------------------------------------------------------------------
// Configuration
// ------------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental_kenya');
define('APP_URL', getenv('APP_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: 'http://localhost/car-rental-kenya'));

// Database connection
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
// Helper: check admin
// ------------------------------------------------------------------
function is_admin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Redirect if not admin
if (!is_admin()) {
    header('Location: admin_login.php');
    exit;
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Kenya counties list
$KENYA_COUNTIES = [
    'nairobi' => 'Nairobi',
    'mombasa' => 'Mombasa',
    'kisumu' => 'Kisumu',
    'nakuru' => 'Nakuru',
    'eldoret' => 'Eldoret',
    'kericho' => 'Kericho',
    'naivasha' => 'Naivasha',
    'nyeri' => 'Nyeri',
    'muranga' => 'Murang\'a',
    'thika' => 'thika',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Vehicle - FleetKE Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #9d20bd;
            padding: 40px 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background:orange;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        h1 {
            font-size: 1.8rem;
            color: #202124;
            margin-bottom: 10px;
        }
        .back-link {
            margin-bottom: 20px;
            display: inline-block;
            color: #1a73e8;
            text-decoration: none;
        }
        .back-link:hover { text-decoration: underline; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #5f6368;
            font-weight: 500;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #b4d815;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #1a73e8;
            outline: none;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: #1a73e8;
            color: white;
        }
        .btn-primary:hover { background: #1557b0; }
        .btn-outline {
            background: yellow;
            border: 1px solid #dadce0;
            color: #5f6368;
        }
        .btn-outline:hover { border-color: #1a73e8; color: #1a73e8; }
        .actions { margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end; }
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            min-width: 300px;
            background: silver;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 1rem;
            z-index: 3000;
            animation: slideInRight 0.3s ease;
        }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast.success { border-left: 4px solid #34a853; }
        .toast.error { border-left: 4px solid #ea4335; }
        .toast.info { border-left: 4px solid #1a73e8; }
        .toast i { font-size: 1.5rem; }
        .toast.success i { color: #34a853; }
        .toast.error i { color: #ea4335; }
        .toast.info i { color: #1a73e8; }
        .toast-content { flex: 1; }
        .toast-close { cursor: pointer; color: #5f6368; }
    </style>
</head>
<body>
    <div class="container">
        <a href="admin_login.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <h1><i class="fas fa-plus-circle" style="color:#34a853;"></i> Add New Vehicle</h1>

        <form id="vehicleForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="vehicle_id" id="vehicle_id" value="0">

            <div class="form-grid">
                <!-- All fields same as original -->
                <div class="form-group">
                    <label for="registration_number">Registration *</label>
                    <input type="text" id="registration_number" name="registration_number" required>
                </div>
                <div class="form-group">
                    <label for="make">Make *</label>
                    <input type="text" id="make" name="make" required>
                </div>
                <div class="form-group">
                    <label for="model">Model *</label>
                    <input type="text" id="model" name="model" required>
                </div>
                <div class="form-group">
                    <label for="year">Year *</label>
                    <input type="number" id="year" name="year" min="2000" max="2026" required>
                </div>
                <div class="form-group">
                    <label for="color">Color *</label>
                    <input type="text" id="color" name="color" required>
                </div>
                <div class="form-group">
                    <label for="vehicle_type">Type *</label>
                    <select id="vehicle_type" name="vehicle_type" required>
                        <option value="">Select</option>
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
                    <label for="fuel_type">Fuel *</label>
                    <select id="fuel_type" name="fuel_type" required>
                        <option value="petrol">Petrol</option>
                        <option value="diesel">Diesel</option>
                        <option value="hybrid">Hybrid</option>
                        <option value="electric">Electric</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="transmission">Transmission *</label>
                    <select id="transmission" name="transmission" required>
                        <option value="manual">Manual</option>
                        <option value="automatic">Automatic</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="seating_capacity">Seats *</label>
                    <input type="number" id="seating_capacity" name="seating_capacity" min="2" max="50" required>
                </div>
                <div class="form-group">
                    <label for="mileage">Mileage (km) *</label>
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
                        <option value="">Select</option>
                        <?php foreach ($KENYA_COUNTIES as $key => $county): ?>
                            <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($county); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" placeholder="e.g., Westlands">
                </div>
                <div class="form-group">
                    <label for="condition">Condition *</label>
                    <select id="condition" name="condition" required>
                        <option value="excellent">Excellent</option>
                        <option value="good">Good</option>
                        <option value="fair">Fair</option>
                        <option value="poor">Poor</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label for="features">Features (comma separated)</label>
                    <textarea id="features" name="features" rows="2"></textarea>
                </div>
                <div class="form-group full-width">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3"></textarea>
                </div>
                <div class="form-group full-width">
                    <label for="vehicle_image">Vehicle Image</label>
                    <input type="file" id="vehicle_image" name="vehicle_image" accept="image/*">
                    <small style="color:#5f6368;">Max 5MB (JPG, PNG, GIF)</small>
                </div>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-outline" onclick="window.location.href='admin_login.php'">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveVehicle()">
                    <i class="fas fa-save"></i> Save Vehicle
                </button>
            </div>
        </form>
    </div>

    <script>
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

        function saveVehicle() {
            const form = document.getElementById('vehicleForm');
            const saveBtn = document.querySelector('.btn-primary');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const dailyRate = parseFloat(document.getElementById('daily_rate').value);
            if (dailyRate < 500) {
                showToast('Validation Error', 'Daily rate must be at least Ksh 500', 'error');
                return;
            }

            const originalText = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData(form);
            formData.append('action', 'add');

            // ************************************************************
            // IMPORTANT: Adjust this URL to the exact location of savevehicle.php
            // Example: if both files are in the same folder, use 'savevehicle.php'
            // Example: if they are in a subfolder named 'admin', use '/car-rental-kenya/admin/savevehicle.php'
            // ************************************************************
            const url = 'savevehicle.php'; // Change this if needed

            fetch(url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(`HTTP error ${response.status}`);
                }
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response. Server returned:', text);
                    throw new Error('Server returned invalid JSON (maybe an error page). Check the console for details.');
                }
            })
            .then(data => {
                if (data.success) {
                    showToast('🎉 Success', data.message, 'success');
                    setTimeout(() => {
                        window.location.href = 'admin_login.php';
                    }, 1500);
                } else {
                    showToast('❌ Error', data.message || 'Operation failed', 'error');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('AJAX error:', error);
                showToast('⚠️ Network Error', error.message, 'error');
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalText;
            });
        }
    </script>
</body>
</html>

