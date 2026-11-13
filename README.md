# VELO Vehicle Rental Management System (VRMS)

A multi-hub, enterprise-grade vehicle rental management platform engineered with role-based operational workflows, regional hub scoping, end-to-end booking turnover lifecycles, and automated financial settlements.

---

## 1. System Overview

VELO VRMS is a centralized vehicle rental operations platform designed for multi-branch regional networks. The platform coordinates four operational roles across a unified database:

- **Customer Portal**: Public fleet catalog, vehicle discovery, reservation submission with damage protection options, online checkout simulation, booking management, and account standing tracking.
- **Operations Staff Workspace**: Real-time reservation approval desk, vehicle dispatch and turnover check-in, fuel deficiency and excess mileage calculations, physical damage reporting, and on-site payment collection.
- **Branch Administrator Hub**: Regional branch governance scoped to a specific operating depot, fleet allocation, staff account approvals, local dispute/incident escalation, and localized fleet maintenance.
- **Super Administrator Oversight**: Global multi-hub governance, system-wide operational policies (turnaround buffer, penalty rates, fuel surcharges, deposit schedules), administrator role assignment, user suspension/archiving, and cross-branch fleet transfers.

---

## 2. Demo User Credentials

All demo accounts use the standard password: `Password123!`

| Role | Email | Operating Scope | Key Responsibilities |
|---|---|---|---|
| **Super Administrator** | `demo.admin@velo.local` | Global (All Hubs) | System policies, admin accounts, global fleet, user suspensions |
| **Branch Administrator** | `demo.branchadmin@velo.local` | Makati Hub (ID: 2) | Branch fleet management, staff approvals, local disputes, hub maintenance |
| **Operations Staff** | `demo.staff@velo.local` | Quezon City Hub (ID: 1) | Reservation verification, vehicle dispatch, check-in, damage reports |
| **Customer** | `demo.customer@velo.local` | Network-wide | Vehicle browsing, reservation booking, digital payment, dispute filing |

---

## 3. Role-Based Access Control Matrix

| System Capability | Super Admin | Branch Admin | Operations Staff | Customer |
|---|:---:|:---:|:---:|:---:|
| Browse Fleet Catalog & Details | Yes | Yes | Yes | Yes |
| Create Reservations & Online Checkout | Yes | Yes | Yes | Yes |
| View Personal Bookings & Receipts | Yes | Yes | Yes | Yes |
| Approve / Reject Rental Reservations | Yes | Yes (Hub) | Yes (Hub) | No |
| Dispatch Vehicle (Check-Out) | Yes | Yes (Hub) | Yes (Hub) | No |
| Check-In Return (Fuel, Mileage, Damage) | Yes | Yes (Hub) | Yes (Hub) | No |
| Report Physical Damage & Incidents | Yes | Yes (Hub) | Yes (Hub) | No |
| Schedule & Complete Maintenance | Yes | Yes (Hub) | No | No |
| Edit Vehicle Metadata & Rates | Yes | Yes (Hub) | No | No |
| Retire / Archive Vehicles | Yes (All Hubs) | Yes (Own Hub Only) | No | No |
| Approve Staff Registrations | Yes | Yes (Hub) | No | No |
| Assign Staff / Customer Roles | Yes | Yes | No | No |
| Assign Administrator Roles | Yes | No | No | No |
| Suspend / Archive User Accounts | Yes | No | No | No |
| Edit Commercial Policies & Matrix | Yes | No | No | No |
| Manage Operating Depot Directory | Yes | No | No | No |
| Multi-Hub Regional Switching | Yes | No (Locked to Hub) | No (Locked to Hub) | No |

---

## 4. End-to-End System Presentation Walkthrough

When presenting this project to evaluators, professors, or clients, follow this four-phase presentation sequence:

```
[Phase 1: Customer]   --> [Phase 2: Staff]        --> [Phase 3: Branch Admin] --> [Phase 4: Super Admin]
Browse & Book Vehicle     Approve & Dispatch Car      Oversee Local Hub Fleet      Configure Global Policies
Submit Online Payment     Check-In Return & Damage    Approve Staff & Disputes     Audit Logs & User Roles
```

### Phase 1: Customer Experience (Discovery, Protection & Booking)

1. **Fleet Catalog Discovery**:
   - Navigate to `http://vrms.local/vehicles.php`.
   - Demonstrate category filters (Sedan, SUV, MPV, Premium), transmission filters (Automatic, Manual), and seating capacity.
   - Click on any vehicle to view the Vehicle Specification page (`vehicle.php?id=X`) displaying fuel tank capacity, daily mileage allowance (200 km), excess mileage rate (12.00 PHP/km), and stationed venue hub.

2. **Reservation Booking & Commercial Protection**:
   - Select rental pickup and return dates.
   - Choose pickup and return locations (same hub or one-way transfer).
   - Demonstrate the three Damage Protection Tiers:
     - **Basic Liability (0 PHP)**: Standard commercial coverage with 15,000 PHP maximum excess.
     - **Standard CDW (+350 PHP/day)**: Reduced excess liability capped at 5,000 PHP.
     - **Zero-Excess Protection (+600 PHP/day)**: Complete coverage with 0 PHP deductible.
   - Point out the automatically calculated Base Security Deposit hold (5,000 PHP for sedans, 8,000 PHP for SUVs, 15,000 PHP for premium fleet).
   - Submit the booking request.

3. **Customer Portal & Digital Payment Simulation**:
   - Sign in as `demo.customer@velo.local`.
   - Open `/customer/dashboard.php` showing the pending booking, current account standing, and notification badge.
   - Open `/customer/my-rentals.php` to inspect the breakdown of base rates, protection package, deposit hold, and total ledger.
   - Demonstrate `/customer/payments.php` and the simulated checkout workflow issuing official digital receipts with printable receipt numbers.
   - Review `/customer/standing.php` demonstrating customer standing score, infraction history, and active dispute submission forms.

---

### Phase 2: Operations Staff Workspace (Turnover & Check-In Workflow)

1. **Reservation Approval Desk**:
   - Sign in as `demo.staff@velo.local`.
   - Open `/staff/staff-dashboard.php?tab=reservations`.
   - Point out the pending reservations queue with badge counters.
   - Inspect customer profile, requested dates, and license verification before clicking `Approve Reservation`.

2. **Vehicle Dispatch (Turnover Desk)**:
   - Switch to the `Pickup Turnover Desk` tab (`?tab=pickups`).
   - Locate the approved reservation ready for customer collection.
   - Verify identity, record initial odometer reading and dispatch fuel percentage level (e.g. 100%), and execute `Dispatch Vehicle`.
   - The vehicle status transitions to active rental, updating fleet availability across the entire platform in real time.

3. **Return Check-In, Surcharges & Damage Assessment**:
   - Switch to the `Returns & Check-in` tab (`?tab=returns`).
   - Execute a vehicle return check-in by entering:
     - Return odometer reading (automatic calculation of excess mileage fee at 12.00 PHP/km if over limit).
     - Return fuel tank percentage (automatic fuel deficiency surcharge calculation at 65.00 PHP/liter if below dispatch level).
     - Late return grace period calculation (30-minute complimentary buffer before hourly penalties accrue).
   - If damage occurred, demonstrate the `Incidents & Damages` reporting tool, uploading notes and repair estimates.

---

### Phase 3: Branch Administrator Workspace (Regional Hub Governance)

1. **Hub-Scoped Fleet Management**:
   - Sign in as `demo.branchadmin@velo.local`.
   - Notice the sidebar identity card automatically scopes to **Downtown Business Hub (Makati)** with navigation header `HUB OPERATIONS`.
   - Open `/admin/admin-vehicles.php`:
     - Demonstrate that the Hub filter dropdown is locked to Makati Hub.
     - Creating a new vehicle automatically locks `Stationed Venue / Hub` to Makati.
     - Cross-hub vehicles from Quezon City cannot be retired, archived, or edited by the branch administrator; they display a clear `Managed by another hub` indicator.

2. **Operations Staff Approvals & Customer Oversight**:
   - Open `/admin/admin-users.php` (labeled `Staff & Customers` in the sidebar).
   - Notice that the branch admin only sees users assigned to their hub.
   - Open the `User Settings` modal on a customer row:
     - The role selection dropdown only permits assigning `Customer` or `Operations Staff`. Elevated administrator roles are hidden.
     - The branch administrator cannot suspend, archive, or delete another administrator account.
     - Demonstrate updating customer account standing (`Good`, `Warning`, `Restricted`, `Under Review`, `Suspended`) with review justification notes.

3. **Hub Maintenance Scheduling**:
   - Open `/admin/admin-maintenance.php`.
   - The vehicle selection dropdown only includes vehicles stationed at the Makati Hub.
   - Schedule routine mechanical servicing, setting the vehicle status to `Maintenance (In Service)`.
   - Complete maintenance to automatically return the vehicle to `Operational` status.

---

### Phase 4: Super Administrator Governance (Global Multi-Hub Oversight)

1. **Global Fleet & Cross-Hub Authority**:
   - Sign in as `demo.admin@velo.local`.
   - Point out the purple `Super Administrator` badge and `Global / All Hubs` scope.
   - Open `/admin/admin-vehicles.php`:
     - Full unrestricted access across all hubs (Quezon City, Makati, BGC, Alabang).
     - Ability to retire, unretire, archive, and soft-delete vehicles at any hub.
     - Ability to reassign any vehicle between hubs.

2. **System Policies & Commercial Matrix**:
   - Open `/admin/admin-settings.php` (labeled `System & Settings`).
   - Highlight the **Operational Policies & Penalty Rates** matrix visible exclusively to Super Administrators:
     - Turnaround Buffer: 2.0 hours (mandatory cleaning and detailing buffer between rentals).
     - Fuel Deficiency Surcharge: 65.00 PHP / Liter.
     - Excess Mileage Rate: 12.00 PHP / km.
     - Return Grace Period: 30 minutes.
     - Damage Protection Tier pricing schedule.
     - Base Security Deposit holds by vehicle tier.
   - Review the **Operating Venue & Depot Directory** showing active depot counts and physical facility addresses.

3. **Global User Governance & Audit Security**:
   - Open `/admin/admin-users.php`:
     - Full system directory across all branches.
     - Ability to promote users to `Branch Administrator` or `Super Administrator`.
     - Ability to permanently suspend or archive accounts with unreturned vehicle flags.
     - Direct access to audit logs tracking who approved, modified, or transitioned each entity.

---

## 5. Technical Architecture

### Directory Structure

```text
vrms/
├── public/                     # Public web root served by Apache/Nginx
│   ├── admin/                  # Administrative management portals
│   │   ├── admin-dashboard.php     # Executive KPI overview & pending queues
│   │   ├── admin-vehicles.php      # Fleet inventory & status management
│   │   ├── admin-rentals.php       # Master reservation & financial ledger
│   │   ├── admin-maintenance.php   # Fleet servicing & mechanical logs
│   │   ├── admin-users.php         # User directory & role assignment
│   │   ├── admin-penalties.php     # Penalties & standing audit ledger
│   │   ├── admin-incidents.php     # Return incident escalation desk
│   │   ├── admin-disputes.php      # Customer dispute resolution desk
│   │   ├── admin-reports.php       # Revenue, utilization & export reports
│   │   └── admin-settings.php      # System policies & depot directory
│   ├── customer/               # Customer portal pages
│   │   ├── dashboard.php           # Customer dashboard & active booking
│   │   ├── my-rentals.php          # Booking history & breakdown
│   │   ├── payments.php            # Receipts & payment records
│   │   ├── standing.php            # Account standing & dispute filing
│   │   ├── notifications.php       # Notification inbox
│   │   └── account-settings.php    # Customer profile & credentials
│   ├── staff/                  # Operations staff workspace
│   │   └── staff-dashboard.php     # Turnover, check-in, dispatch & damage
│   ├── workspace/              # Shared workspace utilities
│   │   └── workspace-account-settings.php
│   ├── assets/                 # CSS stylesheets, JS scripts, vehicle images
│   │   ├── style.css               # Unified theme, layout & responsive styles
│   │   └── script.js              # Password toggles & modal helpers
│   ├── index.php               # Landing page with hero banner & fleet showcase
│   ├── vehicles.php            # Public vehicle catalog with live filters
│   ├── vehicle.php             # Individual vehicle detail & booking modal
│   ├── login.php               # Secure authentication portal
│   ├── register.php            # Customer self-registration
│   └── logout.php              # Session termination
├── includes/                   # Reusable PHP layout components
│   ├── header.php              # Global navigation header & meta tags
│   ├── footer.php              # Standard site footer & copyright
│   ├── workspace-sidebar.php   # Unified sidebar for Admin and Staff
│   └── customer-sidebar.php    # Sidebar for Customer portal
├── database/                   # Database scripts & schema definitions
│   ├── schema.sql              # Master table schema, constraints & seed data
│   └── create-account.php      # CLI account generator
├── xampp/                      # Web server configuration templates
│   ├── vrms-vhost.conf         # Apache VirtualHost configuration
│   └── setup-apache.sh         # Linux XAMPP automation script
└── config.php                  # Database connection, role checks & helpers
```

### Database Schema Highlights

- **`users` & `user_roles`**: Multi-role support (`customer`, `staff`, `admin`, `super_admin`) with hub affiliation via `location_id`.
- **`vehicles`**: Stores operational status (`operational`, `maintenance`, `retired`), location tracking (`current_location_id`, `home_location_id`), daily rates, fuel capacities, and odometer readings.
- **`reservations` & `rentals`**: Multi-stage booking lifecycle tracking reservation requests, staff dispatch turnover, return odometer/fuel validation, and final settlement.
- **`rental_charges` & `payments`**: Comprehensive accounting ledger supporting multiple line items (base rental, protection tiers, fuel deficiency, excess mileage, late fees, damage charges).
- **`penalty_events` & `customer_account_standing`**: Automated standing score calculation (`good`, `warning`, `restricted`, `under_review`, `suspended`) with administrative review workflows.
- **`rental_incidents` & `incident_escalations`**: Tracks missed returns, contact attempts, and institutional/legal review referrals.
- **`audit_logs` & `entity_change_logs`**: Immutable audit trails recording every administrative decision, timestamp, actor ID, and changed payload.

---

## 6. Installation & Local Setup

### System Prerequisites
- PHP 8.1 or higher with PDO and PDO_MySQL extensions.
- MariaDB 10.4+ or MySQL 8.0+.
- Apache 2.4+ with `mod_rewrite` enabled.

### Quick Setup Steps

1. **Configure Web Server Virtual Host**:
   Point your Apache VirtualHost document root to `/home/nori/Downloads/vrms/public`:

   ```apache
   <VirtualHost *:80>
       ServerName vrms.local
       DocumentRoot "/home/nori/Downloads/vrms/public"
       <Directory "/home/nori/Downloads/vrms/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

2. **Add Local Domain Resolution**:
   Add this line to `/etc/hosts` (Linux/macOS) or `C:\Windows\System32\drivers\etc\hosts` (Windows):

   ```text
   127.0.0.1 vrms.local
   ```

3. **Import Database Schema**:
   Import `database/schema.sql` into your MySQL/MariaDB server:

   ```bash
   mysql -h 127.0.0.1 -u root vrms < database/schema.sql
   ```

4. **Verify Database Configuration**:
   Check `config.php` to ensure database credentials match your environment (default: `127.0.0.1:3306`, database `vrms`, username `root`, password empty).

5. **Access Application**:
   Open your browser and navigate to:
   ```text
   http://vrms.local
   ```

---

## 7. Design System & Frontend Guidelines

- **Typography**: `Manrope`, sans-serif.
- **Color Palette**:
  - Brand Accent: Coral (`#E66B68` / `#D9534F`)
  - Deep Navy: (`#26384F` / `#0F172A`)
  - Warm Canvas Background: (`#F8F7F3` / `#F1F5F9`)
  - Emerald Success: (`#059669` / `#10B981`)
  - Amber Warning: (`#D97706` / `#F59E0B`)
- **Iconography**: Inline SVG vector graphics exclusively (Zero emojis across all code, markup, and styling).
- **Responsive Breakpoints**: Fully adaptive layout covering 1280px desktop, 1024px tablet, 768px portrait, and 480px mobile screens.
