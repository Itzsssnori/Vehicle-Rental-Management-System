# VELO VRMS — One-Paste Implementation & Presentation Cheat Sheet

Everything you need on presentation day — browser URLs, login accounts, terminal commands, live SQL queries, and recovery commands — in one copy-paste reference.

---

## 1. Browser Tabs to Open Before Presenting

| Purpose | URL |
|---|---|
| **Main Website (Landing)** | `http://vrms.local/` |
| **Car Listing / Fleet Catalog** | `http://vrms.local/vehicles.php` |
| **Login Page** | `http://vrms.local/login.php` |
| **3NF Clustered ER Diagram** | `http://vrms.local/database-clusters.html` |
| **Live Database Workbench & SQL Console** | `http://vrms.local/database-explorer.php` |
| **phpMyAdmin (Visual ER Designer)** | `http://vrms.local/phpmyadmin/` *(click `vrms` -> `Designer` tab)* |
| **Offline-Ready JSON Fleet API** | `http://vrms.local/api/vehicles.php` |

---

## 2. Presentation Login Accounts

All accounts use password: **`Password123!`**

| Role | Email | Hub / What It Demonstrates |
|---|---|---|
| **Customer (Standard)** | `demo.customer@velo.local` | Lands directly on `/vehicles.php`; test promo codes (`VELO10`, `WEEKEND500`, `HOLIDAY15`) |
| **Customer (Verified Senior)** | `makati.customer@velo.local` | Pre-verified OSCA ID (`OSCA-MKT-2026-0042`); live 20% + 12% PH VAT exemption on `vehicle.php` |
| **Operations Staff (QC)** | `demo.staff@velo.local` | 6-tab turnover desk: approvals, dispatch, return check-in, payments, damages |
| **Operations Staff (Makati)** | `makati.staff@velo.local` | Regional Makati staff visible to Makati Branch Admin |
| **Branch Administrator** | `demo.branchadmin@velo.local` | Makati Hub locked (7 cars, local staff/customers, OSCA queue, no cross-hub edits) |
| **Super Administrator** | `demo.admin@velo.local` | Global control (30 cars, all hubs, Promo Codes CRUD, commercial policies, user suspension) |

---

## 3. One-Paste Terminal Commands

### Check XAMPP Autostart Service (Already Enabled on Boot)
```bash
systemctl status xampp.service --no-pager
```

### Manual Restart (Only if Ever Needed)
```bash
sudo systemctl restart xampp.service
```

### Open the Live MariaDB Database Terminal
```bash
mysql -u root vrms
```
*(Alternative with dedicated user: `mysql -u vrms -pvrms123 vrms`)*

### One-Paste Presentation SQL Queries (Run Inside `mysql -u root vrms`)
```sql
SHOW TABLES;

DESCRIBE vehicles;

SELECT v.plate_number,
       COALESCE(mk.name, v.brand) AS make,
       COALESCE(vm.name, v.model) AS model,
       vc.name AS category,
       v.daily_rate,
       l.name AS hub
FROM vehicles v
INNER JOIN vehicle_categories vc ON vc.id = v.category_id
LEFT JOIN vehicle_models vm ON vm.id = v.model_id
LEFT JOIN vehicle_makes mk ON mk.id = vm.make_id
LEFT JOIN locations l ON l.id = v.current_location_id
WHERE v.status = 'operational'
LIMIT 10;

SELECT u.id, u.name, u.email, r.name AS role, COALESCE(l.name, 'Global') AS hub
FROM users u
INNER JOIN user_roles ur ON ur.user_id = u.id
INNER JOIN roles r ON r.id = ur.role_id
LEFT JOIN locations l ON l.id = u.location_id
ORDER BY u.id;

SELECT dr.name, dr.calculation_mode, dr.discount_value, dr.vat_exempt_eligible, dr.is_stackable
FROM discount_rules dr
WHERE dr.is_active = 1;

SELECT pc.code, dr.name, pc.current_redemptions, pc.max_redemptions, pc.is_active
FROM promo_codes pc
INNER JOIN discount_rules dr ON dr.id = pc.discount_rule_id;

SELECT u.name, cev.eligibility_type, cev.id_number, cev.issuing_authority, cev.verification_status
FROM customer_eligibility_verifications cev
INNER JOIN users u ON u.id = cev.user_id;
```

### Re-Run 3NF Migration & Idempotent Seeder (Safe Anytime — No Data Loss)
```bash
/opt/lampp/bin/mysql -h 127.0.0.1 -u root vrms < /home/nori/Downloads/vrms/database/migrations/004_3nf_discounts_and_clusters.sql
/opt/lampp/bin/mysql -h 127.0.0.1 -u root vrms < /home/nori/Downloads/vrms/database/seed_3nf_inventory.sql
```

### Quick Health Check (All Pages & Offline Images)
```bash
for path in "/" "/vehicles.php" "/login.php" "/register.php" "/api/vehicles.php" "/database-clusters.html" "/database-explorer.php"; do
  echo "http://vrms.local${path} -> HTTP $(curl -s -o /dev/null -w '%{http_code}' "http://vrms.local${path}")"
done
```

### Optional Desktop ER Diagram App (DBeaver Community — MariaDB 10.4 Compatible)
```bash
sudo snap install dbeaver-ce
```
- **Connection Type**: MariaDB
- **Host**: `127.0.0.1` | **Port**: `3306` | **Database**: `vrms`
- **Username**: `vrms` | **Password**: `vrms123`
- Right-click **`vrms`** -> **View Diagram** to display the full EER diagram.

---

## 4. Quick 4-Phase Demo Flow

1. **Customer (`makati.customer@velo.local`)**:
   - Sign in -> lands immediately on `/vehicles.php` (conversion-first design).
   - Open any car -> show live **Senior Citizen 20% + 12% PH VAT Exemption** checkbox, **Promo Code** box (`VELO10`), **Damage Protection Tiers**, and **Security Deposit Hold**.
   - Show `/customer/account-settings.php#statutory-id` (verified OSCA ID badge), `/customer/my-rentals.php`, `/customer/payments.php`, and `/customer/standing.php`.
2. **Operations Staff (`demo.staff@velo.local`)**:
   - Walk through the 6 tabs on `/staff/staff-dashboard.php` (Overview, Approvals, Pickup Desk, Returns Check-in with 30-min grace period & fuel/mileage surcharges, Payment Desk, Damages).
3. **Branch Admin (`demo.branchadmin@velo.local`)**:
   - Show Makati Hub badge -> `/admin/admin-vehicles.php` (locked hub filter, `Managed by another hub` on QC cars) -> `/admin/admin-users.php` (local Makati staff/customers + OSCA verification queue; no Admin role option).
4. **Super Admin (`demo.admin@velo.local`) + Database Showcase**:
   - Show purple `Super Administrator` badge -> `/admin/admin-settings.php` (Global Commercial Policies + **Promo Codes CRUD** + Hub Directory).
   - Switch to **`http://vrms.local/database-clusters.html`** (4-cluster 3NF ER diagram) and **`http://vrms.local/database-explorer.php`** (live 48-table workbench & 1-click SQL runner).
