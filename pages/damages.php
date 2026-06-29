<?php
/**
 * ============================================================
 * Damages Management Page
 * ============================================================
 */

// Use __DIR__ to reliably locate the shared configuration file.
require_once __DIR__ . '/../config/config.php';

// Redirect if not logged in as admin
if (!is_admin()) {
    set_flash('danger', 'You must be logged in as an admin to access that page.');
    redirect('/pages/login.php');
}

// Ensure damages table exists (optional – you can run this once manually)
$conn->query("CREATE TABLE IF NOT EXISTS damages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    booking_id INT NULL,
    reported_by INT NULL,
    description TEXT NOT NULL,
    damage_date DATE NOT NULL,
    repair_cost DECIMAL(10,2) DEFAULT 0,
    status ENUM('pending','repaired','written_off') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL
)");

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ================================================================
// AJAX Handlers
// ================================================================
if (isset($_POST['action'])) {
    // Clear output buffers
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $action = $_POST['action'];
    $damage_id = isset($_POST['damage_id']) ? (int)$_POST['damage_id'] : 0;

    // GET damage data for editing
    if ($action === 'get' && $damage_id > 0) {
        $stmt = $conn->prepare("SELECT * FROM damages WHERE id = ?");
        $stmt->bind_param('i', $damage_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $damage = $result->fetch_assoc();
        echo json_encode($damage ?: null);
        exit;
    }

    // DELETE damage
    if ($action === 'delete' && $damage_id > 0) {
        $stmt = $conn->prepare("DELETE FROM damages WHERE id = ?");
        $stmt->bind_param('i', $damage_id);
        $success = $stmt->execute();
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Damage record deleted.' : 'Delete failed.'
        ]);
        exit;
    }

    // ADD or UPDATE damage
    if ($action === 'save') {
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        $booking_id = !empty($_POST['booking_id']) ? (int)$_POST['booking_id'] : null;
        $reported_by = $_SESSION['user_id']; // current admin as reporter
        $description = sanitize($_POST['description'] ?? '');
        $damage_date = $_POST['damage_date'] ?? date('Y-m-d');
        $repair_cost = (float)($_POST['repair_cost'] ?? 0);
        $status = $_POST['status'] ?? 'pending';

        // Validate
        if ($vehicle_id <= 0 || empty($description)) {
            echo json_encode(['success' => false, 'message' => 'Vehicle and description are required.']);
            exit;
        }

        if ($damage_id > 0) {
            // UPDATE
            $stmt = $conn->prepare("UPDATE damages SET vehicle_id=?, booking_id=?, description=?, damage_date=?, repair_cost=?, status=? WHERE id=?");
            $stmt->bind_param('iissdsi', $vehicle_id, $booking_id, $description, $damage_date, $repair_cost, $status, $damage_id);
        } else {
            // INSERT
            $stmt = $conn->prepare("INSERT INTO damages (vehicle_id, booking_id, reported_by, description, damage_date, repair_cost, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iiissds', $vehicle_id, $booking_id, $reported_by, $description, $damage_date, $repair_cost, $status);
        }

        $success = $stmt->execute();
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Damage record saved.' : 'Save failed: ' . $stmt->error
        ]);
        exit;
    }

    // Unknown action
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ================================================================
// Fetch data for display
// ================================================================

// All damages with vehicle and booking info
$damages_query = "
    SELECT d.*, 
           v.make, v.model, v.registration_number,
           b.booking_reference,
           u.first_name AS reporter_first, u.last_name AS reporter_last
    FROM damages d
    LEFT JOIN vehicles v ON d.vehicle_id = v.id
    LEFT JOIN bookings b ON d.booking_id = b.id
    LEFT JOIN users u ON d.reported_by = u.id
    ORDER BY d.created_at DESC
";
$damages_result = $conn->query($damages_query);
$damages = $damages_result ? $damages_result->fetch_all(MYSQLI_ASSOC) : [];

// All vehicles for dropdown
$vehicles = $conn->query("SELECT id, make, model, registration_number FROM vehicles ORDER BY make, model")->fetch_all(MYSQLI_ASSOC);

// All bookings (for optional linking)
$bookings = $conn->query("SELECT id, booking_reference FROM bookings ORDER BY created_at DESC LIMIT 500")->fetch_all(MYSQLI_ASSOC);

// Flash messages
$flash = get_flash();

// Clear output buffer before HTML
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Damages Management - FleetKE Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .admin-wrapper { display: flex; min-height: 100vh; }
        .admin-sidebar {
            width: 280px;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            color: #333;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
            border-right: 1px solid rgba(0,0,0,0.1);
        }
        .sidebar-header { padding: 2rem; border-bottom: 1px solid #e0e0e0; text-align: center; }
        .sidebar-header h2 { color: #667eea; font-size: 1.8rem; margin-bottom: 0.5rem; }
        .sidebar-header p { color: #999; font-size: 0.9rem; }
        .admin-profile {
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid #e0e0e0;
        }
        .profile-image {
            width: 50px; height: 50px;
            background: #667eea;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        .profile-info h4 { font-size: 1rem; margin-bottom: 0.25rem; }
        .profile-info p { font-size: 0.85rem; color: #999; }
        .sidebar-nav { padding: 1.5rem 0; }
        .nav-item {
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: #666;
            transition: all 0.3s ease;
            cursor: pointer;
            border-left: 3px solid transparent;
        }
        .nav-item:hover {
            background: #f5f5f5;
            color: #667eea;
        }
        .nav-item.active {
            background: #f0f3ff;
            color: #667eea;
            border-left-color: #667eea;
        }
        .nav-item i { width: 20px; }
        .admin-main {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
        }
        .top-bar {
            background: white;
            padding: 1rem 2rem;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .page-title h1 { font-size: 1.8rem; color: #333; }
        .page-title p { color: #999; font-size: 0.9rem; }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(102,126,234,0.4); }
        .btn-success { background: #34a853; color: white; }
        .btn-success:hover { background: #2d8659; transform: translateY(-2px); }
        .btn-danger { background: #ea4335; color: white; }
        .btn-danger:hover { background: #d33c27; }
        .btn-outline {
            background: transparent;
            border: 1px solid #667eea;
            color: #667eea;
        }
        .btn-outline:hover { background: #667eea; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .alert {
            padding: 12px 15px; border-radius: 8px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px; animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .alert-danger { background: #fee; color: #c33; border: 1px solid #fcc; }
        .alert-success { background: #e6f4ea; color: #34a853; border: 1px solid #b8e0c5; }

        .table-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .section-header h2 { font-size: 1.2rem; color: #333; }
        .table-responsive { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        tr:hover { background: #f5f5f5; }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-repaired { background: #d1fae5; color: #059669; }
        .badge-written_off { background: #fee2e2; color: #dc2626; }

        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            overflow-y: auto;
        }
        .modal-content {
            background: white;
            max-width: 600px;
            margin: 50px auto;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease;
        }
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { color: #333; }
        .modal-close {
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        .modal-close:hover { color: #333; }
        .modal-body { padding: 1.5rem; max-height: 60vh; overflow-y: auto; }
        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid #eee;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            min-width: 300px;
            background: white;
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
        .toast.info { border-left: 4px solid #667eea; }
        .toast i { font-size: 1.5rem; }
        .toast.success i { color: #34a853; }
        .toast.error i { color: #ea4335; }
        .toast.info i { color: #667eea; }
        .toast-content { flex: 1; }
        .toast-close { cursor: pointer; color: #666; }

        @media (max-width: 1024px) {
            .admin-sidebar { width: 80px; }
            .sidebar-header h2, .sidebar-header p, .profile-info, .nav-item span { display: none; }
            .admin-profile { justify-content: center; }
            .nav-item { justify-content: center; }
            .nav-item i { margin-right: 0; }
            .admin-main { margin-left: 80px; }
        }
        @media (max-width: 768px) {
            .admin-main { padding: 1rem; }
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
                <div class="nav-item" onclick="window.location.href='admin.php'">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </div>
                <div class="nav-item active" onclick="window.location.href='damages.php'">
                    <i class="fas fa-tools"></i>
                    <span>Damages</span>
                </div>
                <div class="nav-item" onclick="window.location.href='maintenance.php'">
                    <i class="fas fa-wrench"></i>
                    <span>Maintenance</span>
                </div>
                <div class="nav-item" onclick="window.location.href='vehicles.php'">
                    <i class="fas fa-car"></i>
                    <span>Vehicles</span>
                </div>
                <div class="nav-item" onclick="window.location.href='bookings.php'">
                    <i class="fas fa-calendar-check"></i>
                    <span>Bookings</span>
                </div>
                <div class="nav-item" onclick="window.location.href='logout.php'">
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
                    <h1>Damages Management</h1>
                    <p>Record and track vehicle damages</p>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="openModal()">
                        <i class="fas fa-plus"></i> Report Damage
                    </button>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type']; ?>">
                    <i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?>"></i>
                    <div><?php echo htmlspecialchars($flash['message']); ?></div>
                </div>
            <?php endif; ?>

            <!-- Damages Table -->
            <div class="table-section">
                <div class="section-header">
                    <h2>All Damage Reports</h2>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Vehicle</th>
                                <th>Booking Ref</th>
                                <th>Description</th>
                                <th>Date</th>
                                <th>Repair Cost</th>
                                <th>Status</th>
                                <th>Reported By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($damages)): ?>
                                <tr><td colspan="9" style="text-align:center;">No damage records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($damages as $d): ?>
                                <tr data-id="<?php echo $d['id']; ?>">
                                    <td><?php echo $d['id']; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($d['make'] . ' ' . $d['model']); ?><br>
                                        <small><?php echo htmlspecialchars($d['registration_number']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($d['booking_reference'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars(substr($d['description'], 0, 50)) . (strlen($d['description'])>50?'...':''); ?></td>
                                    <td><?php echo htmlspecialchars($d['damage_date']); ?></td>
                                    <td><?php echo number_format($d['repair_cost'], 2); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $d['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $d['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($d['reporter_first'] . ' ' . $d['reporter_last']); ?></td>
                                    <td>
                                        <button class="btn btn-outline btn-sm" onclick="editDamage(<?php echo $d['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="deleteDamage(<?php echo $d['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit Damage Modal -->
    <div id="damageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Report Damage</h3>
                <span class="modal-close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="damageForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="damage_id" id="damage_id" value="0">

                    <div class="form-group">
                        <label for="vehicle_id">Vehicle *</label>
                        <select id="vehicle_id" name="vehicle_id" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?php echo $v['id']; ?>">
                                    <?php echo htmlspecialchars($v['make'] . ' ' . $v['model'] . ' - ' . $v['registration_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="booking_id">Related Booking (optional)</label>
                        <select id="booking_id" name="booking_id">
                            <option value="">— None —</option>
                            <?php foreach ($bookings as $b): ?>
                                <option value="<?php echo $b['id']; ?>">
                                    <?php echo htmlspecialchars($b['booking_reference']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="damage_date">Damage Date *</label>
                        <input type="date" id="damage_date" name="damage_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Description *</label>
                        <textarea id="description" name="description" rows="3" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="repair_cost">Repair Cost (KES)</label>
                        <input type="number" id="repair_cost" name="repair_cost" step="100" min="0" value="0">
                    </div>

                    <div class="form-group">
                        <label for="status">Status *</label>
                        <select id="status" name="status" required>
                            <option value="pending">Pending</option>
                            <option value="repaired">Repaired</option>
                            <option value="written_off">Written Off</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveDamage()">Save</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width:400px;">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <span class="modal-close" onclick="closeDeleteModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this damage record? This action cannot be undone.</p>
                <input type="hidden" id="delete_id">
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
                <button class="btn btn-danger" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <script>
        // Toast function
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

        // Modal controls
        function openModal() {
            document.getElementById('damageForm').reset();
            document.getElementById('damage_id').value = '0';
            document.getElementById('modalTitle').innerText = 'Report Damage';
            document.getElementById('damageModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('damageModal').style.display = 'none';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Edit damage
        function editDamage(id) {
            fetch('damages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'get',
                    damage_id: id,
                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data) {
                    document.getElementById('modalTitle').innerText = 'Edit Damage';
                    document.getElementById('damage_id').value = data.id;
                    document.getElementById('vehicle_id').value = data.vehicle_id;
                    document.getElementById('booking_id').value = data.booking_id || '';
                    document.getElementById('damage_date').value = data.damage_date;
                    document.getElementById('description').value = data.description;
                    document.getElementById('repair_cost').value = data.repair_cost;
                    document.getElementById('status').value = data.status;
                    document.getElementById('damageModal').style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Edit error:', error);
                showToast('Error', 'Could not load damage data', 'error');
            });
        }

        // Save damage (add or update)
        function saveDamage() {
            const form = document.getElementById('damageForm');
            const damageId = document.getElementById('damage_id').value;
            const saveBtn = document.querySelector('#damageModal .btn-primary');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const originalText = saveBtn.innerText;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData(form);
            formData.append('action', 'save');

            fetch('damages.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Success', data.message, 'success');
                    closeModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Error', data.message, 'error');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Save error:', error);
                showToast('Error', 'Save failed: ' + error.message, 'error');
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalText;
            });
        }

        // Delete damage
        function deleteDamage(id) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function confirmDelete() {
            const id = document.getElementById('delete_id').value;
            fetch('damages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'delete',
                    damage_id: id,
                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Success', data.message, 'success');
                    closeDeleteModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Error', data.message, 'error');
                    closeDeleteModal();
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                showToast('Error', 'Delete failed: ' + error.message, 'error');
                closeDeleteModal();
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
