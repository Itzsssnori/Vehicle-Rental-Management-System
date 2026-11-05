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
  role ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer',
  account_status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  phone VARCHAR(30) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  profile_image TEXT DEFAULT NULL,
  preferred_payment_method ENUM('cash','gcash','card') DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  brand VARCHAR(50) NOT NULL,
  model VARCHAR(50) NOT NULL,
  category ENUM('Sedan','SUV','Premium') NOT NULL,
  transmission ENUM('Automatic','Manual') NOT NULL,
  seats TINYINT UNSIGNED NOT NULL,
  daily_rate DECIMAL(10,2) NOT NULL,
  status ENUM('operational','maintenance','retired') NOT NULL DEFAULT 'operational',
  image TEXT DEFAULT NULL,
  plate_number VARCHAR(30) UNIQUE DEFAULT NULL,
  year YEAR DEFAULT NULL,
  color VARCHAR(50) DEFAULT NULL,
  mileage INT UNSIGNED NOT NULL DEFAULT 0,
  registration_expiry DATE DEFAULT NULL,
  insurance_expiry DATE DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vehicle_identity (brand, model, plate_number),
  INDEX idx_vehicles_status_category (status, category)
);

CREATE TABLE rentals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  vehicle_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  pickup_location VARCHAR(255) DEFAULT NULL,
  return_location VARCHAR(255) DEFAULT NULL,
  pickup_time TIME DEFAULT NULL,
  return_time TIME DEFAULT NULL,
  actual_pickup DATETIME DEFAULT NULL,
  actual_return DATE DEFAULT NULL,
  actual_return_at DATETIME DEFAULT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  payment_method ENUM('cash','gcash','card') DEFAULT NULL,
  purpose VARCHAR(255) DEFAULT NULL,
  rejection_reason VARCHAR(255) DEFAULT NULL,
  cancellation_reason VARCHAR(255) DEFAULT NULL,
  cancellation_requested TINYINT(1) NOT NULL DEFAULT 0,
  cancellation_requested_at TIMESTAMP NULL,
  status ENUM('pending','confirmed','ongoing','completed','cancelled','rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE RESTRICT,
  CHECK (end_date >= start_date),
  CHECK (total_amount >= 0),
  INDEX idx_rentals_vehicle_dates (vehicle_id, start_date, end_date),
  INDEX idx_rentals_user_status (user_id, status),
  INDEX idx_rentals_status (status)
);

CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rental_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('cash','gcash','card') NOT NULL,
  recorded_by INT NOT NULL,
  paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (amount > 0),
  INDEX idx_payments_rental (rental_id),
  INDEX idx_payments_recorded_by (recorded_by)
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
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  CHECK (end_date IS NULL OR end_date >= start_date),
  CHECK (cost IS NULL OR cost >= 0),
  INDEX idx_maintenance_vehicle_dates (vehicle_id, start_date, end_date)
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
  INDEX idx_rental_status_history_rental (rental_id, changed_at)
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

CREATE TABLE user_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  document_type ENUM('license','government_id','proof_of_address') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  verification_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  rejection_reason VARCHAR(255) DEFAULT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  verified_by INT DEFAULT NULL,
  verified_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user_documents_user_status (user_id, verification_status)
);

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  rental_id INT DEFAULT NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  read_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE SET NULL,
  INDEX idx_notifications_user_read (user_id, read_at, created_at)
);

CREATE TABLE rental_charges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rental_id INT NOT NULL,
  charge_type ENUM('rental','late_fee','damage','discount','other') NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE,
  CHECK (quantity > 0),
  CHECK (amount >= 0),
  INDEX idx_rental_charges_rental (rental_id)
);

-- Sample fleet data. Real listings can be managed from the admin workspace.
INSERT INTO vehicles (brand, model, category, transmission, seats, daily_rate, image) VALUES
('Toyota','Vios','Sedan','Automatic',5,1800.00,'vios.jpg'),
('Honda','City','Sedan','Automatic',5,2000.00,'city.jpg'),
('Mitsubishi','Montero Sport','SUV','Automatic',7,3500.00,'montero.jpg');
