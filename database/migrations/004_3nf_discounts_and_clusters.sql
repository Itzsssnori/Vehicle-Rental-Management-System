-- ============================================================================
-- Migration 004: 3NF Database Restructuring & Philippine Discount Engine
-- Target: MariaDB 10.4+ / MySQL 8.0+ (Database: vrms)
-- Four Logical Domain Clusters:
--   Cluster 1: Identity & Personal (Users, Profiles, Statutory Eligibility)
--   Cluster 2: Fleet & Business Assets (Makes, Models, Lookup Tables, Vehicles)
--   Cluster 3: Operations & Transactions (Reservations, Rentals, Inspections, Payments)
--   Cluster 4: Pricing & Promotions (Discounts, Philippine Holidays, Promo Codes)
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- CLUSTER 1: IDENTITY & PERSONAL (3NF Extraction of Personal & ID Attributes)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS customer_profiles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  date_of_birth DATE DEFAULT NULL,
  drivers_license_number VARCHAR(50) DEFAULT NULL UNIQUE,
  license_expiry_date DATE DEFAULT NULL,
  license_restriction_codes VARCHAR(30) DEFAULT NULL,
  emergency_contact_name VARCHAR(120) DEFAULT NULL,
  emergency_contact_phone VARCHAR(30) DEFAULT NULL,
  residential_address VARCHAR(255) DEFAULT NULL,
  city VARCHAR(80) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_cp_license_expiry (license_expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_eligibility_verifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  eligibility_type ENUM('senior_citizen', 'pwd') NOT NULL,
  id_number VARCHAR(60) NOT NULL,
  issuing_authority VARCHAR(120) NOT NULL COMMENT 'e.g. OSCA Makati, NCDA Quezon City',
  issued_date DATE DEFAULT NULL,
  expiry_date DATE DEFAULT NULL,
  document_path VARCHAR(255) DEFAULT NULL COMMENT 'Local path to uploaded ID scan',
  verification_status ENUM('pending', 'verified', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
  verified_by INT DEFAULT NULL,
  verified_at DATETIME DEFAULT NULL,
  rejection_reason VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cev_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_cev_type_id (eligibility_type, id_number),
  INDEX idx_cev_user_status (user_id, verification_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- CLUSTER 2: FLEET & BUSINESS ASSETS (3NF Normalized Lookup Tables)
-- Eliminates transitive dependencies: brand -> model -> category/specs
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS vehicle_makes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  country_of_origin VARCHAR(60) DEFAULT NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_models (
  id INT AUTO_INCREMENT PRIMARY KEY,
  make_id INT NOT NULL,
  category_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  default_seats TINYINT UNSIGNED NOT NULL DEFAULT 5,
  default_fuel_capacity_liters DECIMAL(5,1) NOT NULL DEFAULT 45.0,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vm_make FOREIGN KEY (make_id) REFERENCES vehicle_makes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_vm_category FOREIGN KEY (category_id) REFERENCES vehicle_categories(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_make_model (make_id, name),
  INDEX idx_vm_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transmission_types (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  label VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fuel_types (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  label VARCHAR(50) NOT NULL UNIQUE,
  surcharge_per_liter DECIMAL(8,2) NOT NULL DEFAULT 65.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link existing vehicles table to 3NF lookup tables (nullable for zero-downtime migration)
ALTER TABLE vehicles
  ADD COLUMN IF NOT EXISTS model_id INT DEFAULT NULL AFTER category_id,
  ADD COLUMN IF NOT EXISTS transmission_type_id TINYINT UNSIGNED DEFAULT NULL AFTER transmission,
  ADD COLUMN IF NOT EXISTS fuel_type_id TINYINT UNSIGNED DEFAULT NULL AFTER fuel_type;

-- Add foreign keys safely using MariaDB 10.4+ IF NOT EXISTS syntax
ALTER TABLE vehicles
  ADD CONSTRAINT fk_vehicles_model FOREIGN KEY IF NOT EXISTS (model_id) REFERENCES vehicle_models(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_vehicles_transmission_type FOREIGN KEY IF NOT EXISTS (transmission_type_id) REFERENCES transmission_types(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_vehicles_fuel_type FOREIGN KEY IF NOT EXISTS (fuel_type_id) REFERENCES fuel_types(id) ON DELETE RESTRICT;

-- ----------------------------------------------------------------------------
-- CLUSTER 4: PRICING & PROMOTIONS (Philippine Statutory + Seasonal + Promo)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS discount_types (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE COMMENT 'statutory_senior, statutory_pwd, holiday_promo, duration_tier, promo_code',
  label VARCHAR(80) NOT NULL,
  is_statutory TINYINT(1) NOT NULL DEFAULT 0,
  requires_id_verification TINYINT(1) NOT NULL DEFAULT 0,
  description VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discount_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  discount_type_id TINYINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  calculation_mode ENUM('percentage', 'fixed_amount') NOT NULL DEFAULT 'percentage',
  discount_value DECIMAL(10,2) NOT NULL COMMENT 'Percentage (e.g. 20.00) or PHP fixed amount',
  max_discount_cap DECIMAL(10,2) DEFAULT NULL COMMENT 'Optional PHP cap on discount amount',
  vat_exempt_eligible TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Remove 12% PH VAT before applying discount',
  min_rental_days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  min_base_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_stackable TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = Mutually exclusive; 1 = Can stack with other stackable rules',
  priority TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Lower number evaluates first',
  valid_from DATE DEFAULT NULL,
  valid_until DATE DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dr_type FOREIGN KEY (discount_type_id) REFERENCES discount_types(id) ON DELETE RESTRICT,
  CHECK (discount_value >= 0),
  INDEX idx_dr_active_dates (is_active, valid_from, valid_until),
  INDEX idx_dr_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holiday_calendar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  holiday_name VARCHAR(120) NOT NULL,
  holiday_date DATE NOT NULL,
  promo_start_date DATE NOT NULL,
  promo_end_date DATE NOT NULL,
  discount_rule_id INT NOT NULL,
  is_recurring_annually TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_hc_rule FOREIGN KEY (discount_rule_id) REFERENCES discount_rules(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_holiday_date_name (holiday_date, holiday_name),
  INDEX idx_hc_window (promo_start_date, promo_end_date, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  discount_rule_id INT NOT NULL,
  max_redemptions INT UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
  current_redemptions INT UNSIGNED NOT NULL DEFAULT 0,
  per_customer_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pc_rule FOREIGN KEY (discount_rule_id) REFERENCES discount_rules(id) ON DELETE RESTRICT,
  INDEX idx_pc_code_active (code, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- CLUSTER 3 EXTENSIONS: TRANSACTION SNAPSHOTS & APPLIED DISCOUNT JUNCTION
-- Preserves immutable financial receipts even if discount_rules change later
-- ----------------------------------------------------------------------------

ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS subtotal_before_tax DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER base_amount,
  ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) NOT NULL DEFAULT 12.00 AFTER subtotal_before_tax,
  ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER vat_rate,
  ADD COLUMN IF NOT EXISTS vat_exempt_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER vat_amount,
  ADD COLUMN IF NOT EXISTS final_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_amount;

CREATE TABLE IF NOT EXISTS reservation_discounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  discount_rule_id INT NOT NULL,
  promo_code_id INT DEFAULT NULL,
  eligibility_verification_id INT DEFAULT NULL,
  snapshot_rule_name VARCHAR(120) NOT NULL COMMENT 'Frozen rule label at time of booking',
  snapshot_calculation_mode ENUM('percentage', 'fixed_amount') NOT NULL,
  snapshot_rate DECIMAL(10,2) NOT NULL COMMENT 'Frozen percentage or fixed rate',
  computed_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  computed_vat_exemption DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rd_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_rd_rule FOREIGN KEY (discount_rule_id) REFERENCES discount_rules(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rd_promo FOREIGN KEY (promo_code_id) REFERENCES promo_codes(id) ON DELETE SET NULL,
  CONSTRAINT fk_rd_verification FOREIGN KEY (eligibility_verification_id) REFERENCES customer_eligibility_verifications(id) ON DELETE SET NULL,
  INDEX idx_rd_reservation (reservation_id),
  INDEX idx_rd_rule (discount_rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
