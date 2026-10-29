CREATE DATABASE IF NOT EXISTS vrms CHARACTER SET utf8mb4;
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
  seats TINYINT NOT NULL,
  daily_rate DECIMAL(10,2) NOT NULL,
  -- operational status: general condition of the vehicle, NOT date availability
  status ENUM('operational','maintenance','retired') NOT NULL DEFAULT 'operational',
  image TEXT DEFAULT NULL
);

CREATE TABLE rentals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  vehicle_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  actual_return DATE DEFAULT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  payment_method ENUM('cash','gcash','card') DEFAULT NULL,
  purpose VARCHAR(255) DEFAULT NULL,
  rejection_reason VARCHAR(255) DEFAULT NULL,
  cancellation_reason VARCHAR(255) DEFAULT NULL,
  cancellation_requested TINYINT(1) NOT NULL DEFAULT 0,
  cancellation_requested_at TIMESTAMP NULL,
  status ENUM('pending','confirmed','ongoing','completed','cancelled','rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  CHECK (end_date >= start_date)
);

CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rental_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('cash','gcash','card') NOT NULL,
  recorded_by INT NOT NULL,
  paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rental_id) REFERENCES rentals(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE TABLE maintenance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE DEFAULT NULL,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
);

-- Sample data only; real listings come from this table.
INSERT INTO vehicles (brand, model, category, transmission, seats, daily_rate, image) VALUES
('Toyota','Vios','Sedan','Automatic',5,1800.00,'vios.jpg'),
('Honda','City','Sedan','Automatic',5,2000.00,'city.jpg'),
('Mitsubishi','Montero Sport','SUV','Automatic',7,3500.00,'montero.jpg');
