<?php
/**
 * ============================================================
 * Damage Class - Damage Tracking and Penalties
 * ============================================================
 */

class Damage {
    private $conn;
    private $returns_table = 'vehicle_returns';
    private $damage_table = 'damage_charges';

    public function __construct($database) {
        $this->conn = $database;
    }

    /**
     * Record vehicle return and damage
     */
    public function recordReturn($data) {
        $query = "INSERT INTO " . $this->returns_table . " 
                  (booking_id, vehicle_id, user_id, actual_return_date, mileage_at_return, 
                   fuel_level_at_return, condition_report, is_damaged, damage_description, 
                   damage_severity, return_status)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($query);
        
        if (!$stmt) {
            return false;
        }

        $return_status = 'pending_inspection';
        $is_damaged = isset($data['is_damaged']) ? 1 : 0;

        $stmt->bind_param(
            "iiisiisissss",
            $data['booking_id'],
            $data['vehicle_id'],
            $data['user_id'],
            $data['actual_return_date'],
            $data['mileage_at_return'],
            $data['fuel_level_at_return'],
            $data['condition_report'],
            $is_damaged,
            $data['damage_description'],
            $data['damage_severity'],
            $return_status
        );

        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        return false;
    }

    /**
     * Create damage charge
     */
    public function createDamageCharge($data) {
        $query = "INSERT INTO " . $this->damage_table . " 
                  (return_id, booking_id, user_id, damage_type, description, charge_amount, status, notes)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($query);
        
        if (!$stmt) {
            return false;
        }

        $status = 'pending';

        $stmt->bind_param(
            "iisssdsss",
            $data['return_id'],
            $data['booking_id'],
            $data['user_id'],
            $data['damage_type'],
            $data['description'],
            $data['charge_amount'],
            $status,
            $data['notes']
        );

        return $stmt->execute();
    }

    /**
     * Get return record by ID
     */
    public function getReturnById($id) {
        $query = "SELECT r.*, v.make, v.model, v.registration_number, u.first_name, u.last_name
                  FROM " . $this->returns_table . " r
                  JOIN vehicles v ON r.vehicle_id = v.id
                  JOIN users u ON r.user_id = u.id
                  WHERE r.id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get all returns
     */
    public function getAllReturns($filters = []) {
        $query = "SELECT r.*, v.make, v.model, v.registration_number, u.first_name, u.last_name
                  FROM " . $this->returns_table . " r
                  JOIN vehicles v ON r.vehicle_id = v.id
                  JOIN users u ON r.user_id = u.id
                  WHERE 1=1";

        if (isset($filters['status'])) {
            $query .= " AND r.return_status = '" . $filters['status'] . "'";
        }

        if (isset($filters['is_damaged'])) {
            $query .= " AND r.is_damaged = " . ($filters['is_damaged'] ? 1 : 0);
        }

        if (isset($filters['damage_severity'])) {
            $query .= " AND r.damage_severity = '" . $filters['damage_severity'] . "'";
        }

        $query .= " ORDER BY r.actual_return_date DESC";

        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get damage charges
     */
    public function getDamageCharges($filters = []) {
        $query = "SELECT dc.*, u.first_name, u.last_name, b.booking_reference
                  FROM " . $this->damage_table . " dc
                  JOIN users u ON dc.user_id = u.id
                  JOIN bookings b ON dc.booking_id = b.id
                  WHERE 1=1";

        if (isset($filters['status'])) {
            $query .= " AND dc.status = '" . $filters['status'] . "'";
        }

        if (isset($filters['user_id'])) {
            $query .= " AND dc.user_id = " . $filters['user_id'];
        }

        if (isset($filters['damage_type'])) {
            $query .= " AND dc.damage_type = '" . $filters['damage_type'] . "'";
        }

        $query .= " ORDER BY dc.created_at DESC";

        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Approve damage charge
     */
    public function approveDamageCharge($id, $admin_id) {
        $query = "UPDATE " . $this->damage_table . " SET status = 'approved', approved_by = ?, approval_date = NOW() WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $admin_id, $id);
        return $stmt->execute();
    }

    /**
     * Mark damage charge as paid
     */
    public function markDamageChargePaid($id) {
        $query = "UPDATE " . $this->damage_table . " SET status = 'paid', payment_date = NOW() WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    /**
     * Get damage statistics
     */
    public function getDamageStats() {
        $stats = [];

        // Total damaged vehicles
        $result = $this->conn->query("SELECT COUNT(*) as count FROM " . $this->returns_table . " WHERE is_damaged = 1");
        $stats['total_damaged'] = $result->fetch_assoc()['count'];

        // By severity
        $result = $this->conn->query("SELECT damage_severity, COUNT(*) as count FROM " . $this->returns_table . " WHERE is_damaged = 1 GROUP BY damage_severity");
        $stats['by_severity'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['by_severity'][$row['damage_severity']] = $row['count'];
        }

        // Total damage charges
        $result = $this->conn->query("SELECT SUM(charge_amount) as total FROM " . $this->damage_table);
        $stats['total_charges'] = $result->fetch_assoc()['total'] ?? 0;

        // Pending charges
        $result = $this->conn->query("SELECT SUM(charge_amount) as total FROM " . $this->damage_table . " WHERE status = 'pending'");
        $stats['pending_charges'] = $result->fetch_assoc()['total'] ?? 0;

        // By damage type
        $result = $this->conn->query("SELECT damage_type, COUNT(*) as count, SUM(charge_amount) as total FROM " . $this->damage_table . " GROUP BY damage_type");
        $stats['by_type'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['by_type'][$row['damage_type']] = $row;
        }

        return $stats;
    }

    /**
     * Get user damage history
     */
    public function getUserDamageHistory($user_id) {
        $query = "SELECT dc.*, b.booking_reference, v.make, v.model
                  FROM " . $this->damage_table . " dc
                  JOIN bookings b ON dc.booking_id = b.id
                  JOIN vehicles v ON b.vehicle_id = v.id
                  WHERE dc.user_id = ?
                  ORDER BY dc.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get vehicle damage history
     */
    public function getVehicleDamageHistory($vehicle_id) {
        $query = "SELECT r.*, u.first_name, u.last_name, dc.charge_amount
                  FROM " . $this->returns_table . " r
                  LEFT JOIN " . $this->damage_table . " dc ON r.id = dc.return_id
                  JOIN users u ON r.user_id = u.id
                  WHERE r.vehicle_id = ? AND r.is_damaged = 1
                  ORDER BY r.actual_return_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $vehicle_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Calculate damage penalty based on severity
     */
    public function calculateDamagePenalty($severity) {
        switch ($severity) {
            case 'minor':
                return DAMAGE_PENALTY_MINOR;
            case 'moderate':
                return DAMAGE_PENALTY_MODERATE;
            case 'severe':
                return DAMAGE_PENALTY_SEVERE;
            default:
                return 0;
        }
    }

    /**
     * Approve return
     */
    public function approveReturn($id, $admin_id) {
        $query = "UPDATE " . $this->returns_table . " SET return_status = 'approved', inspected_by = ?, inspection_date = NOW() WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $admin_id, $id);
        return $stmt->execute();
    }

    /**
     * Get pending damage inspections
     */
    public function getPendingInspections() {
        $query = "SELECT r.*, v.make, v.model, v.registration_number, u.first_name, u.last_name
                  FROM " . $this->returns_table . " r
                  JOIN vehicles v ON r.vehicle_id = v.id
                  JOIN users u ON r.user_id = u.id
                  WHERE r.return_status = 'pending_inspection' AND r.is_damaged = 1
                  ORDER BY r.actual_return_date ASC";
        
        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
?>
