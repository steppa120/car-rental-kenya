<?php
/**
 * ============================================================
 * User Class - Authentication and User Management
 * ============================================================
 */

class User {
    private $conn;
    private $table = 'users';

    public function __construct($database) {
        $this->conn = $database;
    }

    /**
     * Register a new user
     */
    public function register($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (first_name, last_name, email, phone, password_hash, id_number, license_number, 
                   license_expiry, address, city, county, postal_code, user_role, status, email_verified)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($query);
        
        if (!$stmt) {
            return false;
        }

        $password_hash = password_hash($data['password'], PASSWORD_BCRYPT);
        $user_role = 'customer';
        $status = 'active';
        $email_verified = false;

        $stmt->bind_param(
            "ssssssssssssssi",
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['phone'],
            $password_hash,
            $data['id_number'],
            $data['license_number'],
            $data['license_expiry'],
            $data['address'],
            $data['city'],
            $data['county'],
            $data['postal_code'],
            $user_role,
            $status,
            $email_verified
        );

        return $stmt->execute();
    }

    /**
     * Login user
     */
    public function login($email, $password) {
        $query = "SELECT * FROM " . $this->table . " WHERE email = ? AND status != 'suspended' AND status != 'blacklisted'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password_hash'])) {
                // Update last login
                $update_query = "UPDATE " . $this->table . " SET last_login = NOW() WHERE id = ?";
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->bind_param("i", $user['id']);
                $update_stmt->execute();

                return $user;
            }
        }
        return false;
    }

    /**
     * Get user by ID
     */
    public function getUserById($id) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get user by email
     */
    public function getUserByEmail($email) {
        $query = "SELECT * FROM " . $this->table . " WHERE email = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Check if email exists
     */
    public function emailExists($email) {
        $query = "SELECT id FROM " . $this->table . " WHERE email = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /**
     * Check if license exists
     */
    public function licenseExists($license_number) {
        $query = "SELECT id FROM " . $this->table . " WHERE license_number = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $license_number);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /**
     * Update user profile
     */
    public function updateProfile($id, $data) {
        $query = "UPDATE " . $this->table . " SET 
                  first_name = ?, last_name = ?, phone = ?, address = ?, 
                  city = ?, county = ?, postal_code = ?, profile_image = ?
                  WHERE id = ?";

        $stmt = $this->conn->prepare($query);
        
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "ssssssssi",
            $data['first_name'],
            $data['last_name'],
            $data['phone'],
            $data['address'],
            $data['city'],
            $data['county'],
            $data['postal_code'],
            $data['profile_image'],
            $id
        );

        return $stmt->execute();
    }

    /**
     * Change password
     */
    public function changePassword($id, $old_password, $new_password) {
        $user = $this->getUserById($id);
        
        if (!$user || !password_verify($old_password, $user['password_hash'])) {
            return false;
        }

        $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
        $query = "UPDATE " . $this->table . " SET password_hash = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $password_hash, $id);
        
        return $stmt->execute();
    }

    /**
     * Get all users (Admin)
     */
    public function getAllUsers($filters = []) {
        $query = "SELECT * FROM " . $this->table . " WHERE user_role = 'customer'";

        if (isset($filters['status'])) {
            $query .= " AND status = '" . $filters['status'] . "'";
        }

        if (isset($filters['county'])) {
            $query .= " AND county = '" . $filters['county'] . "'";
        }

        if (isset($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query .= " AND (first_name LIKE '$search' OR last_name LIKE '$search' OR email LIKE '$search')";
        }

        $query .= " ORDER BY created_at DESC";

        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Update user status (Admin)
     */
    public function updateStatus($id, $status) {
        $query = "UPDATE " . $this->table . " SET status = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $status, $id);
        return $stmt->execute();
    }

    /**
     * Get user statistics
     */
    public function getUserStats() {
        $stats = [];

        // Total users
        $result = $this->conn->query("SELECT COUNT(*) as count FROM " . $this->table . " WHERE user_role = 'customer'");
        $stats['total_users'] = $result->fetch_assoc()['count'];

        // Active users
        $result = $this->conn->query("SELECT COUNT(*) as count FROM " . $this->table . " WHERE user_role = 'customer' AND status = 'active'");
        $stats['active_users'] = $result->fetch_assoc()['count'];

        // Blacklisted users
        $result = $this->conn->query("SELECT COUNT(*) as count FROM " . $this->table . " WHERE user_role = 'customer' AND status = 'blacklisted'");
        $stats['blacklisted_users'] = $result->fetch_assoc()['count'];

        // Users by county
        $result = $this->conn->query("SELECT county, COUNT(*) as count FROM " . $this->table . " WHERE user_role = 'customer' GROUP BY county");
        $stats['by_county'] = $result->fetch_all(MYSQLI_ASSOC);

        return $stats;
    }

    /**
     * Get user rental history
     */
    public function getUserRentalHistory($user_id) {
        $query = "SELECT rh.*, v.make, v.model, v.registration_number 
                  FROM rental_history rh
                  JOIN vehicles v ON rh.vehicle_id = v.id
                  WHERE rh.user_id = ?
                  ORDER BY rh.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Blacklist user
     */
    public function blacklistUser($id, $reason = '') {
        $query = "UPDATE " . $this->table . " SET status = 'blacklisted' WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    /**
     * Get user by license number
     */
    public function getUserByLicense($license_number) {
        $query = "SELECT * FROM " . $this->table . " WHERE license_number = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $license_number);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}
?>
