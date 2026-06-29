<?php
/**
 * ============================================================
 * Maintenance Management Page
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';

// Redirect if not logged in as admin
if (!is_admin()) {
    set_flash('danger', 'You must be logged in as an admin to access that page.');
    redirect('/pages/login.php');
}

// Ensure maintenance table exists
$conn->query("CREATE TABLE IF NOT EXISTS maintenance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    scheduled_date DATE NOT NULL,
    completed_date DATE NULL,
    maintenance_type VARCHAR(100) NOT NULL,
    cost DECIMAL(10,2) DEFAULT 0,
    notes TEXT,
    status ENUM('scheduled','in_progress','completed','cancelled') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
)");

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ================================================================
// AJAX Handlers
// ================================================================
if (isset($_POST['action'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $action = $_POST['action'];
    $maintenance_id = isset($_POST['maintenance_id']) ? (int)$_POST['maintenance_id'] : 0;

    // GET maintenance data
    if ($action === 'get' && $maintenance_id > 0) {
        $stmt = $conn->prepare("SELECT * FROM maintenance WHERE id = ?");
        $stmt->bind_param('i', $maintenance_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $maintenance = $result->fetch_assoc();
        echo json_encode($maintenance ?: null);
        exit;
    }

    // DELETE maintenance
    if ($action === 'delete' && $maintenance_id > 0) {
        $stmt = $conn->prepare("DELETE FROM maintenance WHERE id = ?");
        $stmt->bind_param('i', $maintenance_id);
        $success = $stmt->execute();
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Maintenance record deleted.' : 'Delete failed.'
        ]);
        exit;
    }

    // SAVE (add or update) maintenance
    if ($action === 'save') {
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        $scheduled_date = $_POST['scheduled_date'] ?? '';
        $completed_date = !empty($_POST['completed_date']) ? $_POST['completed_date'] : null;
        $maintenance_type = sanitize($_POST['maintenance_type'] ?? '');
        $cost = (float)($_POST['cost'] ?? 0);
        $notes = sanitize($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'scheduled';

        if ($vehicle_id <= 0 || empty($scheduled_date) || empty($maintenance_type)) {
            echo json_encode(['success' => false, 'message' => 'Vehicle, scheduled date and type are required.']);
            exit;
        }

        if ($maintenance_id > 0) {
            // UPDATE
            $stmt = $conn->prepare("UPDATE maintenance SET vehicle_id=?, scheduled_date=?, completed_date=?, maintenance_type=?, cost=?, notes=?, status=? WHERE id=?");
            $stmt->bind_param('isssdssi', $vehicle_id, $scheduled_date, $completed_date, $maintenance_type, $cost, $notes, $status, $maintenance_id);
        } else {
            // INSERT
            $stmt = $conn->prepare("INSERT INTO maintenance (vehicle_id, scheduled_date, completed_date, maintenance_type, cost, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssdss', $vehicle_id, $scheduled_date, $completed_date, $maintenance_type, $cost, $notes, $status);
        }

        $success = $stmt->execute();
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Maintenance record saved.' : 'Save failed: ' . $stmt->error
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ================================================================
// Fetch data for display
// ================================================================

// All maintenance records with vehicle details
$maintenance_query = "
    SELECT m.*, v.make, v.model, v.registration_number
    FROM maintenance m
    LEFT JOIN vehicles v ON m.vehicle_id = v.id
    ORDER BY 
        CASE m.status 
            WHEN 'scheduled' THEN 1
            WHEN 'in_progress' THEN 2
            WHEN 'completed' THEN 3
            WHEN 'cancelled' THEN 4
        END,
        m.scheduled_date ASC
";
$maintenance_result = $conn->query($maintenance_query);
$maintenance_records = $maintenance_result ? $maintenance_result->fetch_all(MYSQLI_ASSOC) : [];

// All vehicles for dropdown
$vehicles = $conn->query("SELECT id, make, model, registration_number FROM vehicles ORDER BY make, model")->fetch_all(MYSQLI_ASSOC);

// Flash message
$flash = get_flash();

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Management - FleetKE Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Same styles as damages.php – you can reuse or link a common CSS file */
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
        .badge-scheduled { background: #fef3c7; color: #d97706; }
        .badge-in_progress { background: #cff3ff; color: #0284c7; }
        .badge-completed { background: #d1fae5; color: #059669; }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }

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
                <div class="nav-item" onclick="window.location.href='damages.php'">
                    <i class="fas fa-tools"></i>
                    <span>Damages</span>
                </div>
                <div class="nav-item active" onclick="window.location.href='maintenance.php'">
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
                    <h1>Maintenance Management</h1>
                    <p>Schedule and track vehicle maintenance</p>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="openModal()">
                        <i class="fas fa-plus"></i> Schedule Maintenance
                    </button>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type']; ?>">
                    <i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?>"></i>
                    <div><?php echo htmlspecialchars($flash['message']); ?></div>
                </div>
            <?php endif; ?>

            <!-- Maintenance Table -->
            <div class="table-section">
                <div class="section-header">
                    <h2>All Maintenance Records</h2>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Vehicle</th>
                                <th>Type</th>
                                <th>Scheduled</th>
                                <th>Completed</th>
                                <th>Cost</th>
                                <th>Status</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($maintenance_records)): ?>
                                <tr><td colspan="9" style="text-align:center;">No maintenance records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($maintenance_records as $m): ?>
                                <tr data-id="<?php echo $m['id']; ?>">
                                    <td><?php echo $m['id']; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($m['make'] . ' ' . $m['model']); ?><br>
                                        <small><?php echo htmlspecialchars($m['registration_number']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($m['maintenance_type']); ?></td>
                                    <td><?php echo htmlspecialchars($m['scheduled_date']); ?></td>
                                    <td><?php echo $m['completed_date'] ? htmlspecialchars($m['completed_date']) : '—'; ?></td>
                                    <td><?php echo number_format($m['cost'], 2); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo str_replace('_', '-', $m['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $m['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($m['notes'], 0, 30)) . (strlen($m['notes'])>30?'...':''); ?></td>
                                    <td>
                                        <button class="btn btn-outline btn-sm" onclick="editMaintenance(<?php echo $m['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="deleteMaintenance(<?php echo $m['id']; ?>)">
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

    <!-- Add/Edit Maintenance Modal -->
    <div id="maintenanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Schedule Maintenance</h3>
                <span class="modal-close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="maintenanceForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="maintenance_id" id="maintenance_id" value="0">

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
                        <label for="maintenance_type">Maintenance Type *</label>
                        <input type="text" id="maintenance_type" name="maintenance_type" placeholder="e.g., Oil Change, Brake Pad" required>
                    </div>

                    <div class="form-group">
                        <label for="scheduled_date">Scheduled Date *</label>
                        <input type="date" id="scheduled_date" name="scheduled_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="completed_date">Completed Date</label>
                        <input type="date" id="completed_date" name="completed_date">
                    </div>

                    <div class="form-group">
                        <label for="cost">Cost (KES)</label>
                        <input type="number" id="cost" name="cost" step="100" min="0" value="0">
                    </div>

                    <div class="form-group">
                        <label for="status">Status *</label>
                        <select id="status" name="status" required>
                            <option value="scheduled">Scheduled</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveMaintenance()">Save</button>
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
                <p>Are you sure you want to delete this maintenance record? This action cannot be undone.</p>
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
            document.getElementById('maintenanceForm').reset();
            document.getElementById('maintenance_id').value = '0';
            document.getElementById('modalTitle').innerText = 'Schedule Maintenance';
            document.getElementById('maintenanceModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('maintenanceModal').style.display = 'none';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Edit maintenance
        function editMaintenance(id) {
            fetch('maintenance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'get',
                    maintenance_id: id,
                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data) {
                    document.getElementById('modalTitle').innerText = 'Edit Maintenance';
                    document.getElementById('maintenance_id').value = data.id;
                    document.getElementById('vehicle_id').value = data.vehicle_id;
                    document.getElementById('maintenance_type').value = data.maintenance_type;
                    document.getElementById('scheduled_date').value = data.scheduled_date;
                    document.getElementById('completed_date').value = data.completed_date || '';
                    document.getElementById('cost').value = data.cost;
                    document.getElementById('status').value = data.status;
                    document.getElementById('notes').value = data.notes;
                    document.getElementById('maintenanceModal').style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Edit error:', error);
                showToast('Error', 'Could not load maintenance data', 'error');
            });
        }

        // Save maintenance (add or update)
        function saveMaintenance() {
            const form = document.getElementById('maintenanceForm');
            const maintenanceId = document.getElementById('maintenance_id').value;
            const saveBtn = document.querySelector('#maintenanceModal .btn-primary');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const originalText = saveBtn.innerText;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData(form);
            formData.append('action', 'save');

            fetch('maintenance.php', {
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

        // Delete maintenance
        function deleteMaintenance(id) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function confirmDelete() {
            const id = document.getElementById('delete_id').value;
            fetch('maintenance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'delete',
                    maintenance_id: id,
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
