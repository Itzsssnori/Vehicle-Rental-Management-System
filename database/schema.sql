-- Fresh VELO database setup.
-- WARNING: importing this file drops and recreates the vrms database.
DROP DATABASE IF EXISTS vrms;
CREATE DATABASE vrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vrms;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  phone_verified_at DATETIME DEFAULT NULL,
  account_status ENUM('approved','pending','rejected','suspended','archived') NOT NULL DEFAULT 'approved',
  phone VARCHAR(30) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  profile_image TEXT DEFAULT NULL,
  preferred_payment_method ENUM('cash','bank_transfer','gcash','card') DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE auth_tokens (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_type ENUM('email_verification','password_reset') NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_auth_tokens_lookup (token_type, expires_at, used_at),
  INDEX idx_auth_tokens_user_type (user_id, token_type, used_at)
);

CREATE TABLE auth_rate_limits (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(40) NOT NULL,
  identity_hash CHAR(64) NOT NULL,
  window_started_at DATETIME NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_auth_rate_limit (action, identity_hash, window_started_at)
);

CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) DEFAULT NULL
);

CREATE TABLE user_roles (
  user_id INT NOT NULL,
  role_id INT NOT NULL,
  assigned_by INT DEFAULT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE role_permissions (
  role_id INT NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

CREATE TABLE vehicle_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  address VARCHAR(255) NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  opening_time TIME DEFAULT NULL,
  closing_time TIME DEFAULT NULL,
  instructions TEXT DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_location_name (name),
  CHECK (closing_time IS NULL OR opening_time IS NULL OR closing_time > opening_time)
);

CREATE TABLE vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  brand VARCHAR(50) NOT NULL,
  model VARCHAR(50) NOT NULL,
  transmission ENUM('Automatic','Manual') NOT NULL,
  fuel_type VARCHAR(30) DEFAULT NULL,
  seats TINYINT UNSIGNED NOT NULL,
  daily_rate DECIMAL(10,2) NOT NULL,
  description TEXT DEFAULT NULL,
  image TEXT DEFAULT NULL,
  plate_number VARCHAR(30) NOT NULL UNIQUE,
  year YEAR DEFAULT NULL,
  color VARCHAR(50) DEFAULT NULL,
  mileage INT UNSIGNED NOT NULL DEFAULT 0,
  registration_expiry DATE DEFAULT NULL,
  insurance_expiry DATE DEFAULT NULL,
  status ENUM('operational','maintenance','unavailable','retired') NOT NULL DEFAULT 'operational',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES vehicle_categories(id) ON DELETE RESTRICT,
  CHECK (daily_rate >= 0),
  INDEX idx_vehicles_status_category (status, category_id)
);

CREATE TABLE maintenance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE DEFAULT NULL,
  maintenance_status ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  cost DECIMAL(10,2) DEFAULT NULL,
  completed_at TIMESTAMP NULL,
  serviced_by VARCHAR(150) DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (end_date IS NULL OR end_date >= start_date),
  CHECK (cost IS NULL OR cost >= 0),
  INDEX idx_maintenance_vehicle_dates (vehicle_id, start_date, end_date)
);

CREATE TABLE reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reference_number VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  vehicle_id INT NOT NULL,
  pickup_location_id INT NOT NULL,
  return_location_id INT NOT NULL,
  requested_pickup_at DATETIME NOT NULL,
  requested_return_at DATETIME NOT NULL,
  base_amount DECIMAL(10,2) NOT NULL,
  discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  additional_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL,
  payment_method ENUM('cash','bank_transfer','gcash','card') DEFAULT NULL,
  purpose VARCHAR(255) DEFAULT NULL,
  status ENUM('pending','confirmed','rejected','cancelled','no_show') NOT NULL DEFAULT 'pending',
  rejection_reason VARCHAR(255) DEFAULT NULL,
  approved_by INT DEFAULT NULL,
  approved_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE RESTRICT,
  FOREIGN KEY (pickup_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  FOREIGN KEY (return_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (requested_return_at > requested_pickup_at),
  CHECK (base_amount >= 0 AND discount_amount >= 0 AND additional_amount >= 0 AND total_amount >= 0),
  INDEX idx_reservations_customer_status (customer_id, status),
  INDEX idx_reservations_vehicle_dates (vehicle_id, requested_pickup_at, requested_return_at),
  INDEX idx_reservations_status_dates (status, requested_pickup_at)
);

CREATE TABLE reservation_status_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  changed_by INT DEFAULT NULL,
  old_status VARCHAR(30) DEFAULT NULL,
  new_status VARCHAR(30) NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_reservation_history (reservation_id, changed_at)
);

CREATE TABLE reservation_cancellation_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  requested_by INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('requested','approved','rejected','withdrawn') NOT NULL DEFAULT 'requested',
  reviewed_by INT DEFAULT NULL,
  review_reason VARCHAR(255) DEFAULT NULL,
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME DEFAULT NULL,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_cancellation_reservation_status (reservation_id, status)
);

CREATE TABLE rentals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  vehicle_id INT NOT NULL,
  expected_return_at DATETIME NOT NULL,
  status ENUM('ongoing','returned','completed') NOT NULL DEFAULT 'ongoing',
  completed_at DATETIME DEFAULT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE RESTRICT,
  FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_rentals_customer_status (customer_id, status),
  INDEX idx_rentals_vehicle_status (vehicle_id, status)
);

CREATE TABLE rental_status_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rental_id INT NOT NULL,
  changed_by INT DEFAULT NULL,
  old_status VARCHAR(30) DEFAULT NULL,
  new_status VARCHAR(30) NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_rental_history (rental_id, changed_at)
);

CREATE TABLE rental_incidents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rental_id INT NOT NULL,
  incident_type ENUM('missed_return','vehicle_not_recovered','other') NOT NULL,
  status ENUM('open','contact_attempted','escalated','resolved','closed') NOT NULL DEFAULT 'open',
  description TEXT NOT NULL,
  reported_by INT NOT NULL,
  reviewed_by INT DEFAULT NULL,
  resolution TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT,
  FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_incidents_status (status, created_at)
);

CREATE TABLE incident_contact_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  incident_id INT NOT NULL,
  contact_method ENUM('phone','email','in_person','other') NOT NULL,
  attempted_at DATETIME NOT NULL,
  outcome VARCHAR(255) NOT NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (incident_id) REFERENCES rental_incidents(id) ON DELETE RESTRICT,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE incident_escalations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  incident_id INT NOT NULL,
  escalation_type ENUM('institutional_review','security_review','legal_review') NOT NULL,
  status ENUM('referred','accepted','resolved','declined') NOT NULL DEFAULT 'referred',
  reason TEXT NOT NULL,
  referred_by INT NOT NULL,
  reviewed_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (incident_id) REFERENCES rental_incidents(id) ON DELETE RESTRICT,
  FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE pickup_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rental_id INT NOT NULL UNIQUE,
  recorded_by INT NOT NULL,
  picked_up_at DATETIME NOT NULL,
  starting_mileage INT UNSIGNED NOT NULL,
  starting_fuel_level DECIMAL(5,2) DEFAULT NULL,
  condition_notes TEXT DEFAULT NULL,
  customer_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (starting_fuel_level IS NULL OR (starting_fuel_level >= 0 AND starting_fuel_level <= 100))
);

CREATE TABLE return_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rental_id INT NOT NULL UNIQUE,
  recorded_by INT NOT NULL,
  returned_at DATETIME NOT NULL,
  ending_mileage INT UNSIGNED NOT NULL,
  ending_fuel_level DECIMAL(5,2) DEFAULT NULL,
  condition_notes TEXT DEFAULT NULL,
  damage_reported TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (ending_fuel_level IS NULL OR (ending_fuel_level >= 0 AND ending_fuel_level <= 100))
);

CREATE TABLE vehicle_inspections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rental_id INT NOT NULL,
  inspection_type ENUM('pickup','return') NOT NULL,
  inspected_by INT NOT NULL,
  mileage INT UNSIGNED NOT NULL,
  fuel_level DECIMAL(5,2) DEFAULT NULL,
  condition_notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT,
  FOREIGN KEY (inspected_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (fuel_level IS NULL OR (fuel_level >= 0 AND fuel_level <= 100)),
  INDEX idx_inspections_rental_type (rental_id, inspection_type)
);

CREATE TABLE damage_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rental_id INT NOT NULL,
  reported_by INT NOT NULL,
  description TEXT NOT NULL,
  estimated_cost DECIMAL(10,2) DEFAULT NULL,
  status ENUM('reported','under_review','resolved','waived') NOT NULL DEFAULT 'reported',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT,
  FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (estimated_cost IS NULL OR estimated_cost >= 0)
);

CREATE TABLE rental_charges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT DEFAULT NULL,
  rental_id INT DEFAULT NULL,
  charge_type ENUM('rental','late_fee','damage','discount','cancellation','other') NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE RESTRICT,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT,
  CHECK (quantity > 0 AND unit_price >= 0 AND amount >= 0),
  CHECK (reservation_id IS NOT NULL OR rental_id IS NOT NULL),
  INDEX idx_charges_reservation (reservation_id),
  INDEX idx_charges_rental (rental_id)
);

CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT DEFAULT NULL,
  rental_id INT DEFAULT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('cash','bank_transfer','gcash','card') NOT NULL,
  reference_number VARCHAR(100) DEFAULT NULL,
  recorded_by INT NOT NULL,
  paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE RESTRICT,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (amount > 0),
  CHECK (reservation_id IS NOT NULL OR rental_id IS NOT NULL),
  INDEX idx_payments_reservation (reservation_id),
  INDEX idx_payments_rental (rental_id)
);

CREATE TABLE payment_receipts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT NOT NULL UNIQUE,
  receipt_number VARCHAR(40) NOT NULL UNIQUE,
  issued_by INT NOT NULL,
  issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE refunds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  method ENUM('cash','bank_transfer','gcash','card') NOT NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (amount > 0)
);

CREATE TABLE payment_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT DEFAULT NULL,
  rental_id INT DEFAULT NULL,
  adjustment_type ENUM('credit','debit') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE RESTRICT,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (amount > 0),
  CHECK (reservation_id IS NOT NULL OR rental_id IS NOT NULL)
);

CREATE TABLE penalty_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  reservation_id INT DEFAULT NULL,
  rental_id INT DEFAULT NULL,
  event_type VARCHAR(50) NOT NULL,
  points INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','waived','reversed') NOT NULL DEFAULT 'pending',
  expires_at DATE DEFAULT NULL,
  recorded_by INT NOT NULL,
  reviewed_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE RESTRICT,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (points > 0)
);

CREATE TABLE customer_account_standing (
  user_id INT PRIMARY KEY,
  standing ENUM('good','warning','restricted','suspended','under_review') NOT NULL DEFAULT 'good',
  reviewed_by INT DEFAULT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE disputes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  opened_by INT NOT NULL,
  reservation_id INT DEFAULT NULL,
  rental_id INT DEFAULT NULL,
  reason TEXT NOT NULL,
  status ENUM('open','under_review','resolved','rejected') NOT NULL DEFAULT 'open',
  resolution TEXT DEFAULT NULL,
  resolved_by INT DEFAULT NULL,
  opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME DEFAULT NULL,
  FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE RESTRICT,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT,
  FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (reservation_id IS NOT NULL OR rental_id IS NOT NULL)
);

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  reservation_id INT DEFAULT NULL,
  rental_id INT DEFAULT NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  read_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE SET NULL,
  INDEX idx_notifications_user_read (user_id, read_at, created_at)
);

CREATE TABLE audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  action VARCHAR(100) NOT NULL,
  table_name VARCHAR(100) DEFAULT NULL,
  record_id INT DEFAULT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_logs_user (user_id, created_at),
  INDEX idx_audit_logs_record (table_name, record_id)
);

INSERT INTO roles (name, description) VALUES
  ('admin', 'Full system administration'),
  ('staff', 'Operational rental management'),
  ('customer', 'Vehicle rental customer');

INSERT INTO permissions (name, description) VALUES
  ('users.manage', 'Manage user accounts'),
  ('roles.manage', 'Manage roles and staff permissions'),
  ('vehicles.manage', 'Manage vehicles and fleet status'),
  ('locations.manage', 'Manage pickup and return locations'),
  ('maintenance.manage', 'Manage vehicle maintenance'),
  ('reservations.view', 'View reservation requests'),
  ('reservations.approve', 'Approve or reject reservations'),
  ('reservations.cancel', 'Review cancellation requests'),
  ('rentals.pickup', 'Record vehicle pickup'),
  ('rentals.return', 'Record vehicle return'),
  ('payments.record', 'Record rental payments'),
  ('penalties.manage', 'Review and manage penalty events'),
  ('reports.view', 'View system reports'),
  ('audit.view', 'View audit logs');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'admin';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'staff'
  AND p.name IN (
    'reservations.view', 'reservations.approve', 'reservations.cancel',
    'rentals.pickup', 'rentals.return', 'payments.record',
    'maintenance.manage', 'vehicles.manage'
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'customer'
  AND p.name IN ('reservations.view');

INSERT INTO vehicle_categories (name, description) VALUES
  ('Sedan', 'Compact and standard passenger vehicles'),
  ('SUV', 'Sport utility vehicles'),
  ('Premium', 'Premium passenger vehicles');

INSERT INTO locations (name, address, instructions) VALUES
  ('VELO Main Office', 'VELO Main Office', 'Bring a valid ID and arrive before the scheduled pickup time.');

-- Sample fleet data. Real listings can be managed from the admin workspace.
INSERT INTO vehicles
  (category_id, brand, model, transmission, fuel_type, seats, daily_rate, plate_number, image)
SELECT id, 'Toyota', 'Vios', 'Automatic', 'Gasoline', 5, 1800.00, 'VELO-001', 'vios.jpg'
FROM vehicle_categories WHERE name = 'Sedan';

INSERT INTO vehicles
  (category_id, brand, model, transmission, fuel_type, seats, daily_rate, plate_number, image)
SELECT id, 'Honda', 'City', 'Automatic', 'Gasoline', 5, 2000.00, 'VELO-002', 'city.jpg'
FROM vehicle_categories WHERE name = 'Sedan';

INSERT INTO vehicles
  (category_id, brand, model, transmission, fuel_type, seats, daily_rate, plate_number, image)
SELECT id, 'Mitsubishi', 'Montero Sport', 'Automatic', 'Diesel', 7, 3500.00, 'VELO-003', 'montero.jpg'
FROM vehicle_categories WHERE name = 'SUV';
