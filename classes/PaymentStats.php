<?php
/**
 * Dashboard payment statistics helper.
 */

class PaymentStats {
    private $conn;
    private $table = 'payments';

    public function __construct($database) {
        $this->conn = $database;
    }

    private function tableExists($table) {
        $table = $this->conn->real_escape_string($table);
        $result = $this->conn->query("SHOW TABLES LIKE '{$table}'");
        return $result && $result->num_rows > 0;
    }

    public function getPaymentStats() {
        $stats = [
            'total' => 0,
            'completed' => 0,
            'pending' => 0,
            'failed' => 0,
            'revenue_total' => 0,
            'revenue_this_month' => 0
        ];

        if (!$this->tableExists($this->table)) {
            return $stats;
        }

        $statusResult = $this->conn->query("SELECT payment_status, COUNT(*) AS count FROM {$this->table} GROUP BY payment_status");
        if ($statusResult) {
            while ($row = $statusResult->fetch_assoc()) {
                $status = $row['payment_status'] ?: 'pending';
                $stats[$status] = (int) $row['count'];
                $stats['total'] += (int) $row['count'];
            }
        }

        $totalResult = $this->conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM {$this->table} WHERE payment_status = 'completed'");
        if ($totalResult) {
            $stats['revenue_total'] = (float) $totalResult->fetch_assoc()['total'];
        }

        $monthResult = $this->conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM {$this->table} WHERE payment_status = 'completed' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
        if ($monthResult) {
            $stats['revenue_this_month'] = (float) $monthResult->fetch_assoc()['total'];
        }

        return $stats;
    }
}
?>
