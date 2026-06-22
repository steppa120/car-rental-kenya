-- ============================================================
-- Car Rental Management System - Database Schema
-- Kenya-Based Rental System
-- ============================================================

-- Create Database
CREATE DATABASE IF NOT EXISTS car_rental_kenya;
USE car_rental_kenya;

-- ============================================================
-- USERS TABLE
-- ============================================================
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    phone VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    id_number VARCHAR(20) UNIQUE,
    license_number VARCHAR(50) UNIQUE,
    license_expiry DATE,
    address TEXT,
    city VARCHAR(100),
    county VARCHAR(100),
    postal_code VARCHAR(20),
    profile_image VARCHAR(255),
    user_role ENUM('customer', 'admin', 'staff') DEFAULT 'customer',
    status ENUM('active', 'inactive', 'suspended', 'blacklisted') DEFAULT 'active',
    verification_token VARCHAR(255),
    email_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_county (county)
);

-- ============================================================
-- VEHICLES TABLE
-- ============================================================
CREATE TABLE vehicles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    registration_number VARCHAR(20) UNIQUE NOT NULL,
    make VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    year INT NOT NULL,
    color VARCHAR(50),
    vehicle_type ENUM('economy', 'compact', 'sedan', 'suv', 'luxury', 'van', 'truck') NOT NULL,
    fuel_type ENUM('petrol', 'diesel', 'hybrid', 'electric') DEFAULT 'petrol',
    transmission ENUM('manual', 'automatic') DEFAULT 'automatic',
    seating_capacity INT DEFAULT 5,
    mileage INT DEFAULT 0,
    daily_rate DECIMAL(10, 2) NOT NULL,
    discount_percent DECIMAL(5, 2) DEFAULT 0.00,
    weekly_rate DECIMAL(10, 2),
    monthly_rate DECIMAL(10, 2),
    image_url VARCHAR(255),
    images TEXT,
    location VARCHAR(100),
    county VARCHAR(100),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    status ENUM('available', 'rented', 'maintenance', 'damaged', 'retired') DEFAULT 'available',
    is_featured TINYINT(1) DEFAULT 0,
    `condition` ENUM('excellent', 'good', 'fair', 'poor') DEFAULT 'good',
    features TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_county (county),
    INDEX idx_registration (registration_number)
);

-- ============================================================
-- BOOKINGS TABLE
-- ============================================================
CREATE TABLE bookings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_reference VARCHAR(50) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    pickup_date DATE NOT NULL,
    return_date DATE NOT NULL,
    pickup_location VARCHAR(255),
    return_location VARCHAR(255),
    pickup_county VARCHAR(100),
    return_county VARCHAR(100),
    daily_rate DECIMAL(10, 2) NOT NULL,
    number_of_days INT NOT NULL,
    base_amount DECIMAL(10, 2) NOT NULL,
    discount_amount DECIMAL(10, 2) DEFAULT 0,
    total_amount DECIMAL(10, 2) NOT NULL,
    payment_status ENUM('pending', 'partial', 'paid', 'refunded') DEFAULT 'pending',
    booking_status ENUM('pending', 'confirmed', 'active', 'completed', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    INDEX idx_user_id (user_id),
    INDEX idx_vehicle_id (vehicle_id),
    INDEX idx_status (booking_status),
    INDEX idx_dates (pickup_date, return_date)
);

-- ============================================================
-- PAYMENTS TABLE
-- ============================================================
CREATE TABLE payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('cash', 'card', 'mpesa', 'bank_transfer', 'online') NOT NULL,
    transaction_id VARCHAR(100),
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_booking_id (booking_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
);

-- ============================================================
-- SETTINGS TABLE
-- ============================================================
CREATE TABLE settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- VEHICLE RETURNS TABLE
-- ============================================================
CREATE TABLE vehicle_returns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    user_id INT NOT NULL,
    actual_return_date DATETIME NOT NULL,
    mileage_at_return INT,
    fuel_level_at_return INT DEFAULT 100,
    condition_report TEXT,
    is_damaged BOOLEAN DEFAULT FALSE,
    damage_description TEXT,
    damage_severity ENUM('none', 'minor', 'moderate', 'severe') DEFAULT 'none',
    damage_images VARCHAR(255),
    return_status ENUM('pending_inspection', 'inspected', 'approved', 'disputed') DEFAULT 'pending_inspection',
    inspected_by INT,
    inspection_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (inspected_by) REFERENCES users(id),
    INDEX idx_booking_id (booking_id),
    INDEX idx_vehicle_id (vehicle_id)
);

-- ============================================================
-- DAMAGE CHARGES TABLE
-- ============================================================
CREATE TABLE damage_charges (
    id INT PRIMARY KEY AUTO_INCREMENT,
    return_id INT NOT NULL,
    booking_id INT NOT NULL,
    user_id INT NOT NULL,
    damage_type ENUM('minor', 'moderate', 'severe') NOT NULL,
    description TEXT,
    charge_amount DECIMAL(10, 2) NOT NULL,
    status ENUM('pending', 'approved', 'paid', 'disputed') DEFAULT 'pending',
    approved_by INT,
    approval_date DATETIME,
    payment_date DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (return_id) REFERENCES vehicle_returns(id),
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    INDEX idx_booking_id (booking_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
);

-- ============================================================
-- LATE FEES TABLE
-- ============================================================
CREATE TABLE late_fees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL,
    user_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    scheduled_return_date DATE NOT NULL,
    actual_return_date DATE NOT NULL,
    hours_late INT NOT NULL,
    hourly_rate DECIMAL(10, 2) NOT NULL,
    total_fee DECIMAL(10, 2) NOT NULL,
    status ENUM('pending', 'paid', 'waived') DEFAULT 'pending',
    payment_date DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    INDEX idx_booking_id (booking_id),
    INDEX idx_user_id (user_id)
);

-- ============================================================
-- RENTAL HISTORY TABLE
-- ============================================================
CREATE TABLE rental_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    booking_id INT NOT NULL,
    rental_start DATE NOT NULL,
    rental_end DATE NOT NULL,
    total_days INT NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    status ENUM('completed', 'cancelled', 'disputed') DEFAULT 'completed',
    rating INT,
    review TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    INDEX idx_user_id (user_id),
    INDEX idx_vehicle_id (vehicle_id)
);

-- ============================================================
-- MAINTENANCE RECORDS TABLE
-- ============================================================
CREATE TABLE maintenance_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    maintenance_type ENUM('routine', 'repair', 'inspection', 'cleaning') NOT NULL,
    description TEXT,
    cost DECIMAL(10, 2),
    maintenance_date DATE NOT NULL,
    completed_date DATE,
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled') DEFAULT 'scheduled',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    INDEX idx_vehicle_id (vehicle_id),
    INDEX idx_status (status)
);

-- ============================================================
-- ADMIN LOGS TABLE
-- ============================================================
CREATE TABLE admin_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    details TEXT,
    ip_address VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id),
    INDEX idx_admin_id (admin_id),
    INDEX idx_created_at (created_at)
);

-- ============================================================
-- INSERT SAMPLE DATA
-- ============================================================

-- Sample Admin User
INSERT INTO users (first_name, last_name, email, phone, password_hash, user_role, status, email_verified)
VALUES ('Admin', 'FleetKE', 'admin@fleetke.com', '0700000001', '$2y$10$YourHashedPasswordHere', 'admin', 'active', TRUE);

-- Default Site Settings
INSERT INTO settings (setting_key, setting_value)
VALUES
('site_name', 'FleetKE'),
('contact_email', 'info@fleetke.com'),
('contact_phone', '+254 700 000 000'),
('contact_address', 'Westlands, Nairobi, Kenya'),
('currency', 'Ksh'),
('tax_rate', '16'),
('features_list', '[{"icon":"car","title":"Wide Vehicle Selection","description":"Choose from economy cars to luxury SUVs, all well-maintained and ready to drive."},{"icon":"map","title":"Multiple Locations","description":"Pickup and drop-off across major counties in Kenya."},{"icon":"card","title":"Easy Payment","description":"Pay securely via M-Pesa, card, bank transfer, or cash."},{"icon":"support","title":"24/7 Support","description":"Our customer service team is available around the clock."}]');

-- Sample Vehicles
INSERT INTO vehicles (registration_number, make, model, year, color, vehicle_type, fuel_type, transmission, seating_capacity, daily_rate, weekly_rate, monthly_rate, location, county, latitude, longitude, status, is_featured, `condition`, description)
VALUES 
('KCA 123A', 'Toyota', 'Corolla', 2023, 'Silver', 'sedan', 'petrol', 'automatic', 5, 2500, 16000, 60000, 'Nairobi CBD', 'nairobi', -1.2921, 36.8219, 'available', 1, 'excellent', 'Reliable sedan for city driving'),
('KCA 124B', 'Nissan', 'X-Trail', 2023, 'Black', 'suv', 'diesel', 'automatic', 7, 4000, 25000, 95000, 'Nairobi West', 'nairobi', -1.3521, 36.7942, 'available', 1, 'excellent', 'Spacious SUV for family trips'),
('KCB 456C', 'Hyundai', 'i10', 2022, 'White', 'compact', 'petrol', 'manual', 5, 1800, 11000, 40000, 'Mombasa', 'mombasa', -4.0435, 39.6682, 'available', 0, 'good', 'Budget-friendly compact car'),
('KCC 789D', 'Mercedes', 'C-Class', 2023, 'Blue', 'luxury', 'petrol', 'automatic', 5, 8000, 50000, 180000, 'Nairobi CBD', 'nairobi', -1.2921, 36.8219, 'available', 1, 'excellent', 'Premium luxury sedan');

-- Create Indexes for Better Performance
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_phone ON users(phone);
CREATE INDEX idx_vehicles_registration ON vehicles(registration_number);
CREATE INDEX idx_bookings_user_vehicle ON bookings(user_id, vehicle_id);
CREATE INDEX idx_payments_booking ON payments(booking_id);
CREATE INDEX idx_returns_booking ON vehicle_returns(booking_id);
CREATE INDEX idx_damage_booking ON damage_charges(booking_id);

-- ============================================================
-- END OF SCHEMA
-- ============================================================
