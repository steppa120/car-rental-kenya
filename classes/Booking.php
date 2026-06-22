<?php
/**
 * Dashboard booking helpers.
 */

class Booking {
    private $conn;
    private $table = 'bookings';

    public function __construct($database) {
        $this->conn = $database;
    }

    private function tableExists($table) {
        $table = $this->conn->real_escape_string($table);
        $result = $this->conn->query("SHOW TABLES LIKE '{$table}'");
        return $result && $result->num_rows > 0;
    }

    public function getBookingStats() {
        $stats = [
            'total' => 0,
            'pending' => 0,
            'confirmed' => 0,
            'active' => 0,
            'completed' => 0,
            'cancelled' => 0
        ];

        if (!$this->tableExists($this->table)) {
            return $stats;
        }

        $result = $this->conn->query("SELECT booking_status, COUNT(*) AS count FROM {$this->table} GROUP BY booking_status");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $status = $row['booking_status'] ?: 'pending';
                $stats[$status] = (int) $row['count'];
                $stats['total'] += (int) $row['count'];
            }
        }

        return $stats;
    }

    public function getAllBookings($filters = []) {
        if (!$this->tableExists($this->table)) {
            return [];
        }

        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 100;
        $query = "SELECT b.*, u.first_name, u.last_name, v.make, v.model
                  FROM {$this->table} b
                  LEFT JOIN users u ON b.user_id = u.id
                  LEFT JOIN vehicles v ON b.vehicle_id = v.id
                  ORDER BY b.created_at DESC
                  LIMIT ?";

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
?>
