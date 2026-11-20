# VELO Vehicle Rental Management System (VRMS)

A multi-hub, production-grade vehicle rental management platform modeled after real-world Philippine vehicle rental operations. Built with PHP, MariaDB (Third Normal Form), role-based multi-branch governance, a Philippine statutory and promotional pricing engine, and an offline-first frontend asset and catalog layer.

---

## 1. Quick Presentation Pitch (Elevator Summary)

> "VELO VRMS is a multi-branch vehicle rental management system built for Philippine operations. It coordinates four distinct roles—Customer, Operations Staff, Branch Administrator, and Super Administrator—across a Third Normal Form (3NF) database. Beyond standard booking and fleet tracking, it includes a real-world Philippine discount and tax engine supporting Senior Citizen and PWD statutory discounts with VAT exemption, hub-isolated branch governance, immutable financial receipts, and an offline-first catalog cache so the fleet remains browsable even without internet connectivity."

---

## 2. Demo Accounts (Presentation Credentials)

All demo accounts share the password: `Password123!`

| Role | Email | Assigned Hub / Scope | What to Demonstrate |
|---|---|---|---|
| **Customer (Standard)** | `demo.customer@velo.local` | Main Campus Hub (Quezon City) | Post-login `/vehicles.php` catalog, promo codes (`VELO10`, `WEEKEND500`), protection tiers, digital receipts |
| **Customer (Verified Senior)** | `makati.customer@velo.local` | Downtown Business Hub (Makati) | Verified OSCA ID (`OSCA-MKT-2026-0042`), live 20% Senior Citizen + 12% PH VAT exemption on `vehicle.php` |
| **Operations Staff (QC)** | `demo.staff@velo.local` | Main Campus Hub (Quezon City) | Approving bookings, vehicle dispatch (odometer/fuel out), return check-in, damage reporting |
| **Operations Staff (Makati)** | `makati.staff@velo.local` | Downtown Business Hub (Makati) | Regional Makati staff account managed by Makati Branch Administrator |
| **Branch Administrator** | `demo.branchadmin@velo.local` | Downtown Business Hub (Makati) | Hub-locked fleet (7 cars), local staff/customers & OSCA ID queue, blocked from cross-hub vehicles & admin accounts |
| **Super Administrator** | `demo.admin@velo.local` | Global (All Hubs) | Cross-hub fleet (30 cars), Promo Codes CRUD, global policies, hub directory, promoting/suspending admins |

---

## 3. Complete Feature Breakdown & Dashboard Sections

### A. Public & Customer Portal (`/customer/`)
- **Landing & Public Fleet Catalog (`index.php`, `vehicles.php`, `vehicle.php`)**:
  - Live filtering by category (Sedan, SUV, MPV, Van, Pickup), transmission, seating capacity, and hub.
  - Full specification sheet showing fuel tank capacity, daily mileage allowance (200 km/day), excess rate (PHP 12.00/km), and stationed depot.
- **Customer Dashboard (`dashboard.php`)**:
  - Account standing badge (`Good`, `Warning`, `Restricted`, `Under Review`, `Suspended`), active reservation summary, and live notification counter.
- **My Rentals & Bookings (`my-rentals.php`)**:
  - Itemized booking ledger showing base rate, protection tier, applied discounts, VAT breakdown, deposit hold, and booking status timeline.
- **Payments & Receipts (`payments.php`)**:
  - Supports Cash at Pickup, Bank Transfer, and provider-neutral Online Payment simulation with official receipt numbers and balance updates.
- **Standing & Penalties (`standing.php`)**:
  - Displays customer standing tier, penalty point history, and a formal Dispute / Appeal submission workflow.
- **Notifications (`notifications.php`) & Account Settings (`account-settings.php`)**:
  - Real-time operational alerts (approvals, damage reviews, account updates), self-service profile/password management, and **Senior Citizen (OSCA) / PWD (NCDA) ID verification submission** (`#statutory-id`).
- **Conversion-First Login Flow & Live Discount Calculator (`login.php`, `vehicle.php`)**:
  - Customers land directly on `/vehicles.php` (Car Listing) after sign-in to encourage immediate rental browsing.
  - `vehicle.php` includes a live Promo Code input (`VELO10`, `WEEKEND500`, `HOLIDAY15`), automatic 7+ day (10%) / 30+ day (20%) tier preview, and a 20% Senior/PWD + 12% PH VAT Exemption toggle for verified customers backed by `PricingEngine`.

### B. Operations Staff Workspace (`/staff/staff-dashboard.php`)
Organized into 6 focused operational tabs in a clean, non-scrollable sidebar:
1. **Operations Overview**: Daily KPIs for pending approvals, scheduled pickups, active rentals, and overdue returns.
2. **Reservation Approvals**: Review customer requests, check vehicle availability conflicts, and approve or reject bookings.
3. **Pickup Turnover Desk**: Record dispatch odometer reading and starting fuel tank level before handing over keys.
4. **Returns & Check-in**: Record return odometer and fuel level; automatically computes excess mileage fees, fuel deficiency surcharges, and late return penalties past the 30-minute grace period.
5. **Payment Collection Desk**: Record on-site cash/transfer settlements and generate payment receipts.
6. **Incidents & Damages**: Log physical vehicle damage with repair estimates and report missed-return incidents.

### C. Branch Administrator Workspace (`/admin/`, Hub-Scoped)
Designed for regional depot managers (`HUB OPERATIONS`):
- **Overview (`admin-dashboard.php`)**: Hub-scoped KPIs, fleet utilization, and quick staff approval queue.
- **Fleet Management (`admin-vehicles.php`)**: Add and manage vehicles locked to the branch admin's assigned hub. Cross-hub vehicles display `Managed by another hub` and cannot be retired, archived, or modified.
- **Rental Oversight (`admin-rentals.php`)**: Hub-scoped reservations, damage reviews, settlement ledger, plus quick-navigation pills to **Penalties & Standing** (`admin-penalties.php`), **Incidents & Escalations** (`admin-incidents.php`), and **Disputes & Appeals** (`admin-disputes.php`).
- **Maintenance (`admin-maintenance.php`)**: Schedule and complete mechanical servicing exclusively for vehicles stationed at the branch hub; automatically toggles vehicle status between `Maintenance` and `Operational`.
- **Staff & Customers (`admin-users.php`)**: Manage local staff and customers, approve pending staff accounts, and edit customer standing. Cannot assign `admin` or `super_admin` roles and cannot suspend/archive other administrators.
- **Hub Reports (`admin-reports.php`) & Account Settings (`admin-settings.php`)**: Localized performance reports and personal profile/password settings (global commercial policies are hidden).

### D. Super Administrator Workspace (`/admin/`, Global Scope)
Full multi-branch network authority (`SYSTEM OVERSIGHT` — purple `Super Administrator` badge):
- **Cross-Hub Fleet Control**: View, edit, transfer, retire, unretire, archive, and restore vehicles across all hubs (Quezon City, Makati, and beyond).
- **Global User Governance**: Promote users to `Branch Administrator` or `Super Administrator`, suspend or archive accounts tied to unreturned-vehicle incidents, and review all hubs.
- **System & Settings (`admin-settings.php`)**: Exclusive access to the **Operational Policies & Penalty Rates** matrix, **Promo Codes & Philippine Discount Rules CRUD** (create new vouchers and enable/disable codes live), and **Operating Venue & Depot Directory**.
- **Statutory ID Verification Queue (`admin-users.php`)**: Review and verify/reject customer Senior Citizen (OSCA) and PWD (NCDA) ID submissions.
- **Audit Integrity**: Every administrative action writes an immutable entry to `audit_logs` and preserves historical records via soft-delete archiving when legal/financial history exists.

---

## 4. High-Value Talking Points & Small Benefits to Mention When Presenting

Use these points during your presentation or Q&A defense to highlight engineering depth and real-world practicality:

### 1. Real-World Philippine Discount & Tax Engine (`includes/pricing-engine.php`)
- **Statutory Compliance (RA 9994 & RA 10754)**: Supports mandatory 20% Senior Citizen and PWD discounts with an optional 12% Philippine VAT exemption toggle (computing `Gross / 1.12` per BIR RR 7-2010 before applying 20% to the VAT-exclusive base).
- **Mandatory ID Verification**: Statutory discounts are only granted if the customer has a `verified`, non-expired OSCA (Senior) or NCDA (PWD) record in `customer_eligibility_verifications`.
- **Automatic Highest-Benefit Conflict Resolution**: Under Philippine law, Senior/PWD discounts do not stack with commercial promos. Instead of forcing the customer to guess, the pricing engine automatically computes both paths and awards whichever produces the **higher total savings**.
- **Seasonal & Long-Term Promos**: Includes a Philippine Holiday Calendar (Holy Week, Independence Day, Christmas, New Year), automatic long-term rental tiers (7+ days = 10% off, 30+ days = 20% off), and capped voucher codes (`VELO10`, `WEEKEND500`, `HOLIDAY15`).
- **Immutable Receipt Snapshots**: Both `reservations` and `reservation_discounts` freeze the exact rule name, rate, VAT exemption, and computed PHP discount at checkout. Even if an admin changes or deletes a discount rule months later, historical receipts remain 100% accurate for accounting and audits.

### 2. Third Normal Form (3NF) Database + Live Visual Cluster Map (`public/database-clusters.html`)
- **Zero Transitive Dependencies**: Vehicle makes, models, categories, transmission types, and fuel types are normalized into lookup tables; customer license and Senior/PWD verification records are extracted out of the core `users` authentication table.
- **Visual Presentation Aid**: Open `http://vrms.local/database-clusters.html` live during your presentation to show all 4 domain clusters (Identity & Personal, Fleet & Business Assets, Operations & Transactions, Pricing & Promotions) with interactive cluster filter tabs, `PK`/`FK`/`UQ` badges, and a cross-cluster ER diagram.

### 3. Offline-First Resilience & Zero Broken Images (`public/api/vehicles.php`, `public/sw.js`, `public/assets/offline-catalog.js`)
- **No Wi-Fi Required in the Defense Room**: All vehicle photos are bundled locally under `public/assets/vehicles/` with an SVG placeholder fallback (`public/assets/img/vehicle-placeholder.svg`)—zero dependency on external image URLs that can break during a live demo.
- **Service Worker + `localStorage` Caching**: On first load, the browser caches the vehicle catalog from `/api/vehicles.php` and pre-caches local vehicle images. If the network drops, browsing the fleet continues seamlessly.

### 4. Commercial Protection & Automated Surcharge Safeguards
- **Turnaround Buffer (2.0 Hours)**: Enforces a cleaning and safety inspection window between consecutive rentals.
- **Return Grace Period (30 Minutes)**: Fair complimentary buffer before late return penalties accrue.
- **Automated Surcharges**: Fuel deficiency billed at PHP 65.00/L (Gasoline) or PHP 62.00/L (Diesel); excess mileage billed at PHP 12.00/km beyond the 200 km/day allowance.
- **Damage Protection Tiers & Deposit Holds**: Customers choose Basic Liability (PHP 0, PHP 15,000 max excess), Standard CDW (+PHP 350/day, PHP 5,000 excess), or Zero-Excess (+PHP 600/day, PHP 0 deductible), paired with automatic security deposit holds by vehicle class (PHP 5,000 Sedan / PHP 8,000 SUV-MPV / PHP 15,000 Premium).

### 5. Security, Auditability & Legal Compliance
- **Role & Hub Isolation**: Enforced at both the UI level (hidden buttons, locked dropdowns) and the server POST handler level (cannot be bypassed by crafting HTTP requests).
- **Safe Soft-Deleting**: Deleting a user or vehicle with existing rental, payment, or incident history automatically archives it instead of dropping database rows, preserving foreign-key integrity and legal audit trails.
- **CSRF Tokens & Password Hashing**: All state-changing forms verify CSRF tokens and store passwords with PHP's secure hashing.

### 6. Persistent Ubuntu XAMPP Autostart (`xampp/xampp.service`)
- Configured as a Linux `systemd` service so Apache and MariaDB boot automatically with Ubuntu—no manual `sudo /opt/lampp/lampp start` needed before a demo, and directory permissions are hardened to eliminate `403 Access Forbidden` errors.

---

## 5. Step-by-Step Live Presentation Flow (10-Minute Demo Script)

```text
[1. Public & Customer]  -->  [2. Staff Desk]         -->  [3. Branch Admin]      -->  [4. Super Admin & 3NF]
Browse & Book Vehicle        Approve & Dispatch Car       Hub-Locked Fleet            Global Policies & Users
Show Protection & Promos     Check-In & Surcharges        Staff Approvals             Show database-clusters.html
```

1. **Step 1 — Customer Journey (2.5 mins)**:
   - Open `http://vrms.local/vehicles.php`, filter by SUV/Sedan, and open a vehicle detail page.
   - Highlight daily rate, 200 km/day limit, Damage Protection Tiers, and Security Deposit hold.
   - Sign in as `demo.customer@velo.local`, show `/customer/dashboard.php`, `/customer/my-rentals.php`, `/customer/payments.php` (digital receipt), and `/customer/standing.php`.
2. **Step 2 — Operations Staff Workflow (2.5 mins)**:
   - Sign in as `demo.staff@velo.local` and open `/staff/staff-dashboard.php`.
   - Walk through the 6 tabs: Approve a reservation -> Dispatch at Pickup Desk (odometer/fuel out) -> Check-in at Returns Desk (fuel/mileage calculation) -> Damage/Incident reporting.
3. **Step 3 — Branch Administrator Isolation (2 mins)**:
   - Sign in as `demo.branchadmin@velo.local`. Point out the **Makati Hub** badge and `HUB OPERATIONS` sidebar.
   - Show `/admin/admin-vehicles.php` (locked hub filter, `Managed by another hub` protection on Quezon City cars).
   - Show `/admin/admin-users.php` (role dropdown restricted to Customer and Staff only; no `Administrators` tab).
4. **Step 4 — Super Admin Governance & Database Architecture (3 mins)**:
   - Sign in as `demo.admin@velo.local`. Point out the purple `Super Administrator` badge and `Global / All Hubs` scope.
   - Open `/admin/admin-settings.php` to display the Commercial Policies matrix and Operating Depot Directory.
   - Open `/admin/admin-rentals.php` and show the quick-navigation bar linking to Penalties, Incidents, and Disputes.
   - Finish by opening `http://vrms.local/database-clusters.html` to present the 4-cluster 3NF database design and ER diagram.

---

## 6. Installation, Database Migrations & Autostart Setup (Ubuntu XAMPP)

### 1. Enable Persistent XAMPP Autostart & Fix Permissions
Run once to register `/etc/systemd/system/xampp.service`, enable boot autostart, configure `http://vrms.local` and `http://localhost/vrms`, and prevent `403 Access Forbidden` errors:

```bash
sudo bash /home/nori/Downloads/vrms/xampp/setup-autostart.sh
```

### 2. Import Base Schema (Fresh Install Only)
```bash
/opt/lampp/bin/mysql -h 127.0.0.1 -u root vrms < /home/nori/Downloads/vrms/database/schema.sql
```

### 3. Apply 3NF Discount & Cluster Migration + Idempotent Seeder
Once MariaDB is running, apply the 3NF upgrade (safe on existing databases; adds new tables and columns without wiping data) and run the idempotent seeder (safe to run multiple times without creating duplicates):

```bash
/opt/lampp/bin/mysql -h 127.0.0.1 -u root vrms < /home/nori/Downloads/vrms/database/migrations/004_3nf_discounts_and_clusters.sql
/opt/lampp/bin/mysql -h 127.0.0.1 -u root vrms < /home/nori/Downloads/vrms/database/seed_3nf_inventory.sql
```

### 4. Key URLs for Presentation
- Main Application: `http://vrms.local/` (or `http://localhost/vrms/`)
- 3NF Clustered Schema Visualizer: `http://vrms.local/database-clusters.html`
- Offline-Ready Vehicle Catalog API: `http://vrms.local/api/vehicles.php`

---

## 7. Project Structure & Design System

```text
vrms/
├── public/
│   ├── admin/                  # 10 Admin oversight & hub management pages
│   ├── api/vehicles.php        # Offline-safe 3NF JSON catalog & discount API
│   ├── assets/                 # style.css, offline-catalog.js, local vehicles/*.jpg
│   ├── customer/               # Customer dashboard, rentals, payments, standing, notifications
│   ├── staff/                  # 6-tab Operations Staff workspace
│   ├── workspace/              # Shared staff account settings
│   ├── database-clusters.html  # Standalone 3NF 4-cluster schema & ER visualizer
│   └── sw.js                   # Offline-first Service Worker
├── includes/
│   ├── pricing-engine.php      # Philippine VAT, Senior/PWD, holiday & promo engine
│   ├── workspace-sidebar.php   # Unified 8-item non-scrollable Admin/Staff sidebar
│   ├── customer-sidebar.php    # Unified 8-item non-scrollable Customer sidebar
│   └── header.php / footer.php
├── database/
│   ├── schema.sql              # Core relational schema
│   ├── migrations/             # Incremental SQL migrations (001 through 004)
│   └── seed_3nf_inventory.sql  # Idempotent 3NF fleet & Philippine discount seeder
├── xampp/
│   ├── xampp.service           # Ubuntu systemd autostart unit
│   ├── setup-autostart.sh      # One-command Ubuntu autostart & permission setup
│   └── vrms-vhost.conf         # Apache VirtualHost template
└── config.php                  # Database PDO connection, CSRF & role helpers
```

- **Typography & Theme**: `Manrope` sans-serif, Coral (`#E66B68`), Deep Navy (`#26384F`), Warm Canvas (`#F8F7F3`).
- **Iconography**: Inline SVG vector graphics exclusively (zero emojis).
- **Responsiveness**: Fully adaptive across desktop (1280px), tablet (900px/768px), and mobile (480px) viewports.
