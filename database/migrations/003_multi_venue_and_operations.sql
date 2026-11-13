-- Migration 003: Multi-Venue / Multi-Hub Fleet Tracking, Normalized Addresses,
-- Change Data Capture (Audit History), Soft Deletions, Protection Tiers, and
-- Automated Operational Penalty Rules (Fuel & Mileage).

-- 1. Normalized Addresses table (3NF)
CREATE TABLE IF NOT EXISTS addresses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  street_address VARCHAR(150) NOT NULL,
  barangay_district VARCHAR(100) DEFAULT NULL,
  city VARCHAR(100) NOT NULL,
  province_state VARCHAR(100) NOT NULL,
  postal_code VARCHAR(20) NOT NULL,
  country VARCHAR(60) NOT NULL DEFAULT 'Philippines',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_addresses_city_province (city, province_state)
);

-- 2. Entity Change Logs table (Full State History / CDC)
CREATE TABLE IF NOT EXISTS entity_change_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  table_name VARCHAR(60) NOT NULL,
  record_id INT NOT NULL,
  action ENUM('INSERT','UPDATE','SOFT_DELETE','RESTORE','HARD_DELETE') NOT NULL,
  old_values JSON DEFAULT NULL,
  new_values JSON DEFAULT NULL,
  changed_by INT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_change_entity (table_name, record_id),
  INDEX idx_change_user_date (changed_by, created_at)
);

-- 3. Enhance locations table with normalized address and soft delete
ALTER TABLE locations
  ADD COLUMN address_id INT DEFAULT NULL AFTER address,
  ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT 'Quezon City' AFTER address_id,
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER status,
  ADD COLUMN deleted_by INT DEFAULT NULL AFTER deleted_at,
  ADD CONSTRAINT fk_locations_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_locations_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;

-- 4. Enhance vehicles table with multi-venue, operational specs, and soft delete
ALTER TABLE vehicles
  ADD COLUMN home_location_id INT NOT NULL DEFAULT 1 AFTER category_id,
  ADD COLUMN current_location_id INT NOT NULL DEFAULT 1 AFTER home_location_id,
  ADD COLUMN fuel_capacity_liters DECIMAL(5,2) NOT NULL DEFAULT 45.00 AFTER color,
  ADD COLUMN daily_mileage_limit INT UNSIGNED NOT NULL DEFAULT 200 AFTER fuel_capacity_liters,
  ADD COLUMN excess_mileage_rate DECIMAL(8,2) NOT NULL DEFAULT 12.00 AFTER daily_mileage_limit,
  ADD COLUMN next_service_mileage INT UNSIGNED NOT NULL DEFAULT 5000 AFTER mileage,
  ADD COLUMN service_interval_km INT UNSIGNED NOT NULL DEFAULT 5000 AFTER next_service_mileage,
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at,
  ADD COLUMN deleted_by INT DEFAULT NULL AFTER deleted_at,
  ADD CONSTRAINT fk_vehicles_home_location FOREIGN KEY (home_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_vehicles_current_location FOREIGN KEY (current_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_vehicles_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD INDEX idx_vehicles_current_location (current_location_id),
  ADD INDEX idx_vehicles_deleted (deleted_at);

-- 5. Enhance users table with branch assignment, normalized address, and soft delete
ALTER TABLE users
  ADD COLUMN location_id INT DEFAULT NULL AFTER account_status,
  ADD COLUMN address_id INT DEFAULT NULL AFTER address,
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at,
  ADD COLUMN deleted_by INT DEFAULT NULL AFTER deleted_at,
  ADD CONSTRAINT fk_users_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_users_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_users_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD INDEX idx_users_location (location_id),
  ADD INDEX idx_users_deleted (deleted_at);

-- 6. Enhance reservations table with protection plans and security deposit tracking
ALTER TABLE reservations
  ADD COLUMN protection_tier ENUM('basic','medium','premium') NOT NULL DEFAULT 'basic' AFTER payment_method,
  ADD COLUMN protection_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER protection_tier,
  ADD COLUMN security_deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 5000.00 AFTER protection_fee,
  ADD COLUMN security_deposit_status ENUM('pending','held','refunded','partially_deducted','forfeited') NOT NULL DEFAULT 'pending' AFTER security_deposit_amount,
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at,
  ADD COLUMN deleted_by INT DEFAULT NULL AFTER deleted_at,
  ADD CONSTRAINT fk_reservations_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD INDEX idx_reservations_deleted (deleted_at);

-- 6b. Extend rental charges with operational categories
ALTER TABLE rental_charges
  MODIFY charge_type ENUM('rental','late_fee','damage','discount','cancellation','fuel','mileage','insurance','deposit','other') NOT NULL;

-- 7. Add super_admin role
INSERT INTO roles (name, description) VALUES
  ('super_admin', 'Super Administrator with global multi-venue management')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- 8. Seed normalized addresses and realistic hub venues
INSERT INTO addresses (id, street_address, barangay_district, city, province_state, postal_code, country) VALUES
  (1, 'VELO Central Motorpool Depot, University Way', 'Diliman', 'Quezon City', 'Metro Manila', '1101', 'Philippines'),
  (2, 'Ayala Station Commercial Hub, 450 Ayala Avenue', 'San Lorenzo', 'Makati City', 'Metro Manila', '1223', 'Philippines'),
  (3, 'Terminal 3 Arrivals Flight Deck Bay 8', 'Villamor Airbase', 'Pasay City', 'Metro Manila', '1300', 'Philippines')
ON DUPLICATE KEY UPDATE street_address = VALUES(street_address);

UPDATE locations SET
  name = 'Main Campus Hub (Motorpool Depot)',
  address = 'VELO Central Motorpool Depot, University Way, Diliman, Quezon City',
  address_id = 1,
  city = 'Quezon City',
  phone = '+63 2 8981 8500',
  opening_time = '06:00:00',
  closing_time = '22:00:00',
  instructions = 'Check in at Motorpool Administration Gate 2. Driver license verification mandatory.'
WHERE id = 1;

INSERT INTO locations (id, name, address, address_id, city, phone, opening_time, closing_time, instructions, status) VALUES
  (2, 'Downtown Business Hub (Makati)', 'Ayala Station Commercial Hub, 450 Ayala Avenue, Makati City', 2, 'Makati City', '+63 2 8812 4000', '07:00:00', '21:00:00', 'Lower Ground Level Valet Lane, Ayala Commercial Center. Bring reservation voucher.', 'active'),
  (3, 'Airport Express Hub (Pasay/NAIA)', 'Terminal 3 Arrivals Flight Deck Bay 8, Pasay City', 3, 'Pasay City', '+63 2 8877 1109', '00:00:00', '23:59:59', '24/7 Flight Arrival Hub. Exit through Door 5, cross to Bay 8 rental dispatch booth.', 'active')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  address = VALUES(address),
  address_id = VALUES(address_id),
  city = VALUES(city),
  phone = VALUES(phone);

-- 9. Distribute the 21 fleet vehicles across the 3 hubs
UPDATE vehicles SET home_location_id = 1, current_location_id = 1 WHERE id IN (1, 4, 7, 10, 13, 16, 19);
UPDATE vehicles SET home_location_id = 2, current_location_id = 2 WHERE id IN (2, 5, 8, 11, 14, 17, 20);
UPDATE vehicles SET home_location_id = 3, current_location_id = 3 WHERE id IN (3, 6, 9, 12, 15, 18, 21);

-- Set realistic fuel capacities and service mileage for vehicles
UPDATE vehicles SET fuel_capacity_liters = 42.00, next_service_mileage = 10000, daily_mileage_limit = 200, excess_mileage_rate = 12.00 WHERE category_id = 1;
UPDATE vehicles SET fuel_capacity_liters = 60.00, next_service_mileage = 15000, daily_mileage_limit = 250, excess_mileage_rate = 15.00 WHERE category_id = 2;
UPDATE vehicles SET fuel_capacity_liters = 70.00, next_service_mileage = 20000, daily_mileage_limit = 300, excess_mileage_rate = 20.00 WHERE category_id = 3;

-- 10. Update demo admin to have super_admin role and NULL location (global access)
-- And assign demo staff to Hub 1 (Main Campus)
UPDATE users SET location_id = NULL WHERE email = 'demo.admin@velo.local';
UPDATE users SET location_id = 1 WHERE email = 'demo.staff@velo.local';

-- Grant super_admin role to demo.admin@velo.local
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u, roles r
WHERE u.email = 'demo.admin@velo.local' AND r.name = 'super_admin'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);
