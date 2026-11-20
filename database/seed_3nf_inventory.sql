-- ============================================================================
-- Idempotent 3NF Seeder: Lookup Tables, Philippine Fleet & Discount Rules
-- Safe to run repeatedly (uses INSERT IGNORE / ON DUPLICATE KEY UPDATE)
-- ============================================================================

-- 1. Lookup Tables: Transmissions & Fuel Types
INSERT INTO transmission_types (code, label) VALUES
  ('automatic', 'Automatic'),
  ('manual', 'Manual'),
  ('cvt', 'CVT Automatic')
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO fuel_types (code, label, surcharge_per_liter) VALUES
  ('gasoline', 'Gasoline', 65.00),
  ('diesel', 'Diesel', 62.00),
  ('hybrid', 'Hybrid', 65.00),
  ('electric', 'Electric', 0.00)
ON DUPLICATE KEY UPDATE label = VALUES(label), surcharge_per_liter = VALUES(surcharge_per_liter);

-- 2. Vehicle Categories
INSERT INTO vehicle_categories (name, description, status) VALUES
  ('Sedan', 'Fuel-efficient 5-seater city and highway sedans', 'active'),
  ('SUV', '7-seater high-clearance sport utility vehicles for provincial travel', 'active'),
  ('MPV', 'Versatile multi-purpose family carriers', 'active'),
  ('Van', 'High-capacity commuter and executive transport vans', 'active'),
  ('Pickup', 'Heavy-duty 4x2 and 4x4 utility pickup trucks', 'active')
ON DUPLICATE KEY UPDATE description = VALUES(description), status = 'active';

-- 3. Vehicle Makes
INSERT INTO vehicle_makes (name, country_of_origin) VALUES
  ('Toyota', 'Japan'),
  ('Mitsubishi', 'Japan'),
  ('Ford', 'United States'),
  ('Honda', 'Japan'),
  ('Nissan', 'Japan'),
  ('Hyundai', 'South Korea'),
  ('Mazda', 'Japan')
ON DUPLICATE KEY UPDATE country_of_origin = VALUES(country_of_origin);

-- 4. Vehicle Models (3NF: linked to make_id and category_id)
INSERT IGNORE INTO vehicle_models (make_id, category_id, name, default_seats, default_fuel_capacity_liters)
SELECT m.id, c.id, v.model_name, v.seats, v.fuel_cap
FROM (
  SELECT 'Toyota' AS make_name, 'Sedan' AS cat_name, 'Vios 1.3 XLE' AS model_name, 5 AS seats, 42.0 AS fuel_cap UNION ALL
  SELECT 'Mitsubishi', 'SUV', 'Montero Sport GLS', 7, 68.0 UNION ALL
  SELECT 'Toyota', 'Van', 'HiAce Commuter Deluxe', 15, 70.0 UNION ALL
  SELECT 'Ford', 'Pickup', 'Ranger Wildtrak 4x4', 5, 80.0 UNION ALL
  SELECT 'Honda', 'Sedan', 'City 1.5 RS', 5, 40.0 UNION ALL
  SELECT 'Mitsubishi', 'MPV', 'Xpander Cross', 7, 45.0 UNION ALL
  SELECT 'Toyota', 'SUV', 'Fortuner 2.4 G', 7, 80.0 UNION ALL
  SELECT 'Nissan', 'Pickup', 'Navara VL Calibre', 5, 80.0 UNION ALL
  SELECT 'Toyota', 'MPV', 'Innova 2.8 E', 8, 55.0 UNION ALL
  SELECT 'Ford', 'SUV', 'Everest Titanium', 7, 80.0
) v
INNER JOIN vehicle_makes m ON m.name = v.make_name
INNER JOIN vehicle_categories c ON c.name = v.cat_name;

-- 5. Idempotent Vehicle Inventory Seed (All using bundled local assets)
INSERT INTO vehicles (
  category_id, model_id, brand, model, transmission, transmission_type_id,
  fuel_type, fuel_type_id, seats, daily_rate, description, image,
  plate_number, year, color, mileage, status
)
SELECT
  vm.category_id,
  vm.id,
  mk.name,
  vm.name,
  inv.trans_enum,
  tt.id,
  ft.label,
  ft.id,
  vm.default_seats,
  inv.daily_rate,
  inv.descr,
  inv.local_img,
  inv.plate_no,
  inv.yr,
  inv.clr,
  inv.odo,
  'operational'
FROM (
  SELECT 'Toyota' AS mk_name, 'Vios 1.3 XLE' AS mdl_name, 'Automatic' AS trans_enum, 'cvt' AS trans_code, 'gasoline' AS fuel_code, 2200.00 AS daily_rate, 'Reliable Metro Manila city sedan with Apple CarPlay and fuel-saving Eco mode.' AS descr, '/assets/vehicles/toyota-vios.jpg' AS local_img, 'NGA-1024' AS plate_no, 2024 AS yr, 'Silver Metallic' AS clr, 12400 AS odo UNION ALL
  SELECT 'Mitsubishi', 'Montero Sport GLS', 'Automatic', 'automatic', 'diesel', 4200.00, '7-seater turbo-diesel SUV ideal for Baguio, Tagaytay, and long provincial trips.', '/assets/vehicles/mitsubishi-montero-sport.jpg', 'NGB-2048', 2024, 'Jet Black Mica', 18500 UNION ALL
  SELECT 'Toyota', 'HiAce Commuter Deluxe', 'Manual', 'manual', 'diesel', 4800.00, '15-seater corporate and family commuter van with dual rear A/C.', '/assets/img/vehicle-placeholder.svg', 'NGC-3072', 2024, 'White Pearl', 21300 UNION ALL
  SELECT 'Ford', 'Ranger Wildtrak 4x4', 'Automatic', 'automatic', 'diesel', 4500.00, '4x4 bi-turbo utility pickup with spacious cargo bed and hill descent control.', '/assets/vehicles/ford-ranger.jpg', 'NGD-4096', 2024, 'Luxe Yellow', 14200 UNION ALL
  SELECT 'Honda', 'City 1.5 RS', 'Automatic', 'cvt', 'gasoline', 2500.00, 'Sporty compact sedan featuring Honda Sensing safety suite.', '/assets/vehicles/honda-city.jpg', 'NGE-5120', 2025, 'Ignite Red', 6800 UNION ALL
  SELECT 'Mitsubishi', 'Xpander Cross', 'Automatic', 'automatic', 'gasoline', 3100.00, 'High-clearance 7-seater MPV with flexible cargo seating.', '/assets/vehicles/mitsubishi-xpander.jpg', 'NGF-6144', 2024, 'Graphite Gray', 15900 UNION ALL
  SELECT 'Toyota', 'Fortuner 2.4 G', 'Automatic', 'automatic', 'diesel', 4400.00, 'Rugged 7-seater ladder-frame SUV for business executives and family outings.', '/assets/vehicles/toyota-fortuner.jpg', 'NGG-7168', 2024, 'Attitude Black', 19100 UNION ALL
  SELECT 'Nissan', 'Navara VL Calibre', 'Automatic', 'automatic', 'diesel', 4100.00, 'Comfortable coil-spring rear suspension pickup with 360-degree camera.', '/assets/vehicles/nissan-navara.jpg', 'NGH-8192', 2024, 'Brilliant Silver', 16400
) inv
INNER JOIN vehicle_makes mk ON mk.name = inv.mk_name
INNER JOIN vehicle_models vm ON vm.make_id = mk.id AND vm.name = inv.mdl_name
INNER JOIN transmission_types tt ON tt.code = inv.trans_code
INNER JOIN fuel_types ft ON ft.code = inv.fuel_code
ON DUPLICATE KEY UPDATE
  model_id = VALUES(model_id),
  transmission_type_id = VALUES(transmission_type_id),
  fuel_type_id = VALUES(fuel_type_id),
  image = VALUES(image),
  daily_rate = VALUES(daily_rate);

-- Ensure ALL vehicles use bundled local assets (zero external URLs for offline reliability)
UPDATE vehicles SET image = '/assets/vehicles/toyota-vios.jpg' WHERE brand='Toyota' AND model LIKE 'Vios%';
UPDATE vehicles SET image = '/assets/vehicles/honda-city.jpg' WHERE brand='Honda' AND model LIKE 'City%';
UPDATE vehicles SET image = '/assets/vehicles/mitsubishi-montero-sport.jpg' WHERE brand='Mitsubishi' AND model LIKE 'Montero%';
UPDATE vehicles SET image = '/assets/vehicles/toyota-fortuner.jpg' WHERE brand='Toyota' AND model LIKE 'Fortuner%';
UPDATE vehicles SET image = '/assets/vehicles/toyota-innova.jpg' WHERE brand='Toyota' AND model LIKE 'Innova%';
UPDATE vehicles SET image = '/assets/vehicles/toyota-rav4.jpg' WHERE brand='Toyota' AND model LIKE 'RAV4%';
UPDATE vehicles SET image = '/assets/vehicles/toyota-camry.jpg' WHERE brand='Toyota' AND model LIKE 'Camry%';
UPDATE vehicles SET image = '/assets/vehicles/honda-civic.jpg' WHERE brand='Honda' AND model LIKE 'Civic%';
UPDATE vehicles SET image = '/assets/vehicles/honda-br-v.jpg' WHERE brand='Honda' AND model LIKE 'BR-V%';
UPDATE vehicles SET image = '/assets/vehicles/honda-cr-v.jpg' WHERE brand='Honda' AND model LIKE 'CR-V%';
UPDATE vehicles SET image = '/assets/vehicles/honda-accord.jpg' WHERE brand='Honda' AND model LIKE 'Accord%';
UPDATE vehicles SET image = '/assets/vehicles/mitsubishi-xpander.jpg' WHERE brand='Mitsubishi' AND model LIKE 'Xpander%';
UPDATE vehicles SET image = '/assets/vehicles/mitsubishi-mirage.jpg' WHERE brand='Mitsubishi' AND model LIKE 'Mirage%';
UPDATE vehicles SET image = '/assets/vehicles/nissan-almera.jpg' WHERE brand='Nissan' AND model LIKE 'Almera%';
UPDATE vehicles SET image = '/assets/vehicles/nissan-terra.jpg' WHERE brand='Nissan' AND model LIKE 'Terra%';
UPDATE vehicles SET image = '/assets/vehicles/nissan-navara.jpg' WHERE brand='Nissan' AND model LIKE 'Navara%';
UPDATE vehicles SET image = '/assets/vehicles/ford-everest.jpg' WHERE brand='Ford' AND model LIKE 'Everest%';
UPDATE vehicles SET image = '/assets/vehicles/ford-ranger.jpg' WHERE brand='Ford' AND model LIKE 'Ranger%';
UPDATE vehicles SET image = '/assets/vehicles/mazda-cx-5.jpg' WHERE brand='Mazda' AND model LIKE 'CX-5%';
UPDATE vehicles SET image = '/assets/vehicles/hyundai-tucson.jpg' WHERE brand='Hyundai' AND model LIKE 'Tucson%';
UPDATE vehicles SET image = '/assets/img/vehicle-placeholder.svg' WHERE image IS NULL OR image = '' OR image LIKE 'http%';

-- Backfill existing vehicles' 3NF lookup IDs where missing
UPDATE vehicles v
INNER JOIN transmission_types tt ON LOWER(tt.label) = LOWER(v.transmission)
SET v.transmission_type_id = tt.id
WHERE v.transmission_type_id IS NULL;

UPDATE vehicles v
INNER JOIN fuel_types ft ON LOWER(ft.label) = LOWER(COALESCE(v.fuel_type, 'Gasoline'))
SET v.fuel_type_id = ft.id
WHERE v.fuel_type_id IS NULL;

-- 6. Cluster 4: Discount Types (Philippine Statutory & Commercial)
INSERT INTO discount_types (code, label, is_statutory, requires_id_verification, description) VALUES
  ('statutory_senior', 'Senior Citizen Discount (RA 9994)', 1, 1, 'Mandatory 20% discount plus 12% VAT exemption for verified Philippine Senior Citizens.'),
  ('statutory_pwd', 'PWD Discount (RA 10754)', 1, 1, 'Mandatory 20% discount plus 12% VAT exemption for verified Persons with Disabilities.'),
  ('holiday_promo', 'Philippine Holiday Promo', 0, 0, 'Automatic seasonal promo applied during official Philippine holiday windows.'),
  ('duration_tier', 'Long-Term Rental Tier', 0, 0, 'Automatic tiered discount for weekly (7+ days) or monthly (30+ days) bookings.'),
  ('promo_code', 'Promotional Voucher Code', 0, 0, 'Customer-entered voucher code for marketing campaigns and partner offers.')
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  is_statutory = VALUES(is_statutory),
  requires_id_verification = VALUES(requires_id_verification),
  description = VALUES(description);

-- 7. Discount Rules
INSERT INTO discount_rules (
  discount_type_id, name, calculation_mode, discount_value, max_discount_cap,
  vat_exempt_eligible, min_rental_days, min_base_amount, is_stackable, priority, is_active
)
SELECT dt.id, r.rule_name, r.calc_mode, r.d_val, r.d_cap, r.vat_ex, r.min_days, r.min_amt, r.stackable, r.prio, 1
FROM (
  SELECT 'statutory_senior' AS dt_code, 'Senior Citizen 20% Statutory Discount' AS rule_name, 'percentage' AS calc_mode, 20.00 AS d_val, NULL AS d_cap, 1 AS vat_ex, 1 AS min_days, 0.00 AS min_amt, 0 AS stackable, 10 AS prio UNION ALL
  SELECT 'statutory_pwd', 'PWD 20% Statutory Discount', 'percentage', 20.00, NULL, 1, 1, 0.00, 0, 10 UNION ALL
  SELECT 'duration_tier', 'Weekly Rental Saver (7+ Days)', 'percentage', 10.00, NULL, 0, 7, 0.00, 1, 20 UNION ALL
  SELECT 'duration_tier', 'Monthly Corporate Tier (30+ Days)', 'percentage', 20.00, NULL, 0, 30, 0.00, 1, 15 UNION ALL
  SELECT 'holiday_promo', 'Philippine Holiday 15% Special', 'percentage', 15.00, 3000.00, 0, 2, 0.00, 0, 30 UNION ALL
  SELECT 'promo_code', 'VELO Welcome 10% Voucher', 'percentage', 10.00, 1500.00, 0, 1, 2000.00, 1, 40 UNION ALL
  SELECT 'promo_code', 'Weekend Flat PHP 500 Off', 'fixed_amount', 500.00, 500.00, 0, 2, 3000.00, 1, 45
) r
INNER JOIN discount_types dt ON dt.code = r.dt_code
WHERE NOT EXISTS (SELECT 1 FROM discount_rules dr WHERE dr.name = r.rule_name);

-- 8. Philippine Holiday Calendar
INSERT IGNORE INTO holiday_calendar (holiday_name, holiday_date, promo_start_date, promo_end_date, discount_rule_id, is_recurring_annually)
SELECT h.h_name, h.h_date, h.p_start, h.p_end, dr.id, 1
FROM (
  SELECT 'Holy Week Travel Promo' AS h_name, '2026-04-02' AS h_date, '2026-03-29' AS p_start, '2026-04-05' AS p_end UNION ALL
  SELECT 'Independence Day Drive', '2026-06-12', '2026-06-10', '2026-06-14' UNION ALL
  SELECT 'Christmas Holiday Special', '2026-12-25', '2026-12-18', '2026-12-31' UNION ALL
  SELECT 'New Year Getaway Promo', '2027-01-01', '2026-12-30', '2027-01-04'
) h
INNER JOIN discount_rules dr ON dr.name = 'Philippine Holiday 15% Special';

-- 9. Promo Codes
INSERT INTO promo_codes (code, discount_rule_id, max_redemptions, per_customer_limit, is_active)
SELECT pc.p_code, dr.id, pc.max_red, pc.per_cust, 1
FROM (
  SELECT 'VELO10' AS p_code, 'VELO Welcome 10% Voucher' AS r_name, 500 AS max_red, 1 AS per_cust UNION ALL
  SELECT 'WEEKEND500', 'Weekend Flat PHP 500 Off', 300, 3 UNION ALL
  SELECT 'HOLIDAY15', 'Philippine Holiday 15% Special', 200, 2
) pc
INNER JOIN discount_rules dr ON dr.name = pc.r_name
ON DUPLICATE KEY UPDATE is_active = 1;
