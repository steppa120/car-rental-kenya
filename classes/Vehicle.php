<?php
/**
 * ============================================================
 * Vehicle Class - Vehicle Management
 * ============================================================
 */

class Vehicle {
    private $conn;
    private $table = 'vehicles';

    public function __construct($database) {
        $this->conn = $database;
    }

    /**
     * Add new vehicle
     */
    public function addVehicle($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (registration_number, make, model, year, color, vehicle_type, fuel_type, 
                   transmission, seating_capacity, mileage, daily_rate, weekly_rate, monthly_rate, 
                   image_url, location, county, latitude, longitude, status, condition, features, description)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($query);
        
        if (!$stmt) {
            return false;
        }

        $status = 'available';
        $condition = $data['condition'] ?? 'good';

        $stmt->bind_param(
            "sssisssiiidddssddssss",
            $data['registration_number'],
            $data['make'],
            $data['model'],
            $data['year'],
            $data['color'],
            $data['vehicle_type'],
            $data['fuel_type'],
            $data['transmission'],
            $data['seating_capacity'],
            $data['mileage'],
            $data['daily_rate'],
            $data['weekly_rate'],
            $data['monthly_rate'],
            $data['image_url'],
            $data['location'],
            $data['county'],
            $data['latitude'],
            $data['longitude'],
            $status,
            $condition,
            $data['features'],
            $data['description']
        );

        return $stmt->execute();
    }

    /**
     * Get all vehicles
     */
    public function getAllVehicles($filters = []) {
        $query = "SELECT * FROM " . $this->table . " WHERE 1=1";

        if (isset($filters['status'])) {
            $query .= " AND status = '" . $filters['status'] . "'";
        }

        if (isset($filters['county'])) {
            $query .= " AND county = '" . $filters['county'] . "'";
        }

        if (isset($filters['vehicle_type'])) {
            $query .= " AND vehicle_type = '" . $filters['vehicle_type'] . "'";
        }

        if (isset($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query .= " AND (make LIKE '$search' OR model LIKE '$search' OR registration_number LIKE '$search')";
        }

        $query .= " ORDER BY created_at DESC";

        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get vehicle by ID
     */
    public function getVehicleById($id) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get available vehicles
     */
    public function getAvailableVehicles($filters = []) {
        $query = "SELECT * FROM " . $this->table . " WHERE status = 'available'";

        if (isset($filters['county'])) {
            $query .= " AND county = '" . $filters['county'] . "'";
        }

        if (isset($filters['vehicle_type'])) {
            $query .= " AND vehicle_type = '" . $filters['vehicle_type'] . "'";
        }

        if (isset($filters['max_price'])) {
            $query .= " AND daily_rate <= " . $filters['max_price'];
        }

        if (isset($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query .= " AND (make LIKE '$search' OR model LIKE '$search')";
        }

        $query .= " ORDER BY daily_rate ASC";

        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Update vehicle
     */
    public function updateVehicle($id, $data) {
        $query = "UPDATE " . $this->table . " SET 
                  make = ?, model = ?, year = ?, color = ?, vehicle_type = ?, 
                  fuel_type = ?, transmission = ?, seating_capacity = ?, mileage = ?,
                  daily_rate = ?, weekly_rate = ?, monthly_rate = ?, 
                  location = ?, county = ?, latitude = ?, longitude = ?,
                  condition = ?, features = ?, description = ?
                  WHERE id = ?";

        $stmt = $this->conn->prepare($query);
        
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "ssissssiidddssddsssi",
            $data['make'],
            $data['model'],
            $data['year'],
            $data['color'],
            $data['vehicle_type'],
            $data['fuel_type'],
            $data['transmission'],
            $data['seating_capacity'],
            $data['mileage'],
            $data['daily_rate'],
            $data['weekly_rate'],
            $data['monthly_rate'],
            $data['location'],
            $data['county'],
            $data['latitude'],
            $data['longitude'],
            $data['condition'],
            $data['features'],
            $data['description'],
            $id
        );

        return $stmt->execute();
    }

    /**
     * Update vehicle status
     */
    public function updateStatus($id, $status) {
        $query = "UPDATE " . $this->table . " SET status = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $status, $id);
        return $stmt->execute();
    }

    /**
     * Update vehicle mileage
     */
    public function updateMileage($id, $mileage) {
        $query = "UPDATE " . $this->table . " SET mileage = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $mileage, $id);
        return $stmt->execute();
    }

    /**
     * Delete vehicle
     */
    public function deleteVehicle($id) {
        $query = "DELETE FROM " . $this->table . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    /**
     * Get vehicle statistics
     */
    public function getVehicleStats() {
        $stats = [];

        // Total vehicles
        $result = $this->conn->query("SELECT COUNT(*) as count FROM " . $this->table);
        $stats['total'] = $result->fetch_assoc()['count'];

        // By status
        $result = $this->conn->query("SELECT status, COUNT(*) as count FROM " . $this->table . " GROUP BY status");
        $stats['by_status'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['by_status'][$row['status']] = $row['count'];
        }

        // By type
        $result = $this->conn->query("SELECT vehicle_type, COUNT(*) as count FROM " . $this->table . " GROUP BY vehicle_type");
        $stats['by_type'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['by_type'][$row['vehicle_type']] = $row['count'];
        }

        // By county
        $result = $this->conn->query("SELECT county, COUNT(*) as count FROM " . $this->table . " GROUP BY county");
        $stats['by_county'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['by_county'][$row['county']] = $row['count'];
        }

        // Average daily rate
        $result = $this->conn->query("SELECT AVG(daily_rate) as avg_rate FROM " . $this->table);
        $stats['avg_daily_rate'] = $result->fetch_assoc()['avg_rate'];

        return $stats;
    }

    /**
     * Get vehicles by county
     */
    public function getVehiclesByCounty($county) {
        $query = "SELECT * FROM " . $this->table . " WHERE county = ? AND status = 'available' ORDER BY daily_rate ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $county);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Check vehicle availability for dates
     */
    public function isAvailableForDates($vehicle_id, $pickup_date, $return_date) {
        $query = "SELECT COUNT(*) as count FROM bookings 
                  WHERE vehicle_id = ? 
                  AND booking_status IN ('confirmed', 'active')
                  AND (
                    (pickup_date <= ? AND return_date >= ?) OR
                    (pickup_date <= ? AND return_date >= ?) OR
                    (pickup_date >= ? AND return_date <= ?)
                  )";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("issssss", $vehicle_id, $return_date, $pickup_date, $return_date, $pickup_date, $pickup_date, $return_date);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['count'] == 0;
    }

    /**
     * Get vehicle rental count
     */
    public function getRentalCount($vehicle_id) {
        $query = "SELECT COUNT(*) as count FROM bookings WHERE vehicle_id = ? AND booking_status = 'completed'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $vehicle_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()['count'];
    }

    /**
     * Mark vehicle as damaged
     */
    public function markAsDamaged($id, $severity = 'moderate') {
        $query = "UPDATE " . $this->table . " SET status = 'damaged', condition = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $severity, $id);
        return $stmt->execute();
    }

    /**
     * Get damaged vehicles
     */
    public function getDamagedVehicles() {
        $query = "SELECT * FROM " . $this->table . " WHERE status = 'damaged' ORDER BY updated_at DESC";
        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
?>
