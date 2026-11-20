# VELO VRMS — Live Presentation & Demo Guide

A step-by-step presentation script, screen-by-screen talking points, and Q&A defense cheat sheet for demonstrating the **VELO Vehicle Rental Management System**.

---

## 1. Pre-Presentation Checklist (30 Seconds Before Starting)

1. Confirm the main site opens at: `http://vrms.local/` (XAMPP boots automatically via `systemd` — no terminal commands needed).
2. Open a second browser tab ready at: `http://vrms.local/database-clusters.html` (for the 3NF database architecture segment).
3. Keep these credentials handy (all accounts use password **`Password123!`**):

| Role | Email | Hub / Scope |
|---|---|---|
| **Customer (Standard)** | `demo.customer@velo.local` | Main Campus Hub (Quezon City) |
| **Customer (Verified Senior)** | `makati.customer@velo.local` | Downtown Business Hub (Makati) — OSCA ID Verified |
| **Operations Staff (QC)** | `demo.staff@velo.local` | Main Campus Hub (Quezon City) |
| **Operations Staff (Makati)** | `makati.staff@velo.local` | Downtown Business Hub (Makati) |
| **Branch Administrator** | `demo.branchadmin@velo.local` | Downtown Business Hub (Makati) |
| **Super Administrator** | `demo.admin@velo.local` | Global (All Hubs) |

---

## 2. Opening Pitch (1 Minute)

> "Good morning/afternoon everyone. Today we are presenting **VELO VRMS** — a multi-hub Vehicle Rental Management System designed around real-world Philippine car rental operations.
>
> Instead of a single-branch CRUD app, VELO coordinates **four distinct roles**—Customer, Operations Staff, Branch Administrator, and Super Administrator—across a **Third Normal Form (3NF) relational database**. Key highlights include conversion-focused customer UX, regional hub isolation, automated turnover and surcharge calculations, a Philippine statutory and promotional pricing engine (RA 9994 Senior Citizen and RA 10754 PWD), and an offline-first local asset architecture so the fleet always loads reliably even without internet."

---

## 3. Screen-by-Screen Live Demo Walkthrough (10 Minutes)

### Part 1: Public Site & Conversion-First Customer Flow (2.5 Minutes)

1. **Open `http://vrms.local/` (Landing Page)**
   - **What to show**: Clean brand identity, hero search/fleet showcase, responsive layout.
   - **What to say**: "Guests can explore our fleet immediately without being forced behind a login wall."

2. **Log in as Customer (`demo.customer@velo.local` / `Password123!`)**
   - **What happens**: The customer is automatically redirected straight to **`/vehicles.php` (Car Listing)** — NOT a passive summary page.
   - **UX Benefit to Mention**: *"We intentionally land customers directly on the live vehicle catalog right after login. Psychologically, this primes them to browse and rent immediately rather than getting distracted by an administrative summary screen. Their account dashboard is still one click away in the navigation."*

3. **Browse & Filter on `/vehicles.php` -> Click Any Car (`/vehicle.php?id=1`)**
   - **What to show**: Category filters (Sedan, SUV, MPV, Van, Pickup), transmission, seating capacity, and crisp local vehicle photos.
   - **On `vehicle.php`**: Point out daily rate, 200 km/day mileage allowance, PHP 12/km excess rate, **Damage Protection Tiers** (Basic Liability, Standard CDW +PHP 350/day, Zero-Excess +PHP 600/day), and **Base Security Deposit Holds** (PHP 5,000 / 8,000 / 15,000 by vehicle class).
   - **Submit a booking** (or open an existing booking).

4. **Show Customer Portal (`/customer/dashboard.php`)**
   - Walk through the clean, non-scrollable 8-item sidebar:
     - **My Rentals & Bookings (`/customer/my-rentals.php`)**: Itemized booking status and charges.
     - **Payments & Receipts (`/customer/payments.php`)**: Simulated Online Payment, Cash, and Bank Transfer checkout with official receipt numbers.
     - **Standing & Penalties (`/customer/standing.php`)**: Transparent customer standing badge (`Good`, `Warning`, `Restricted`, `Suspended`) and formal Dispute/Appeal filing.
     - **Notifications (`/customer/notifications.php`)**: Real-time alerts for approvals and account actions.

---

### Part 2: Operations Staff Turnover Workflow (2.5 Minutes)

1. **Log out -> Log in as Staff (`demo.staff@velo.local` / `Password123!`)**
   - Lands on **`/staff/staff-dashboard.php`**.
   - **What to say**: "Operations staff have a focused, 6-tab workspace dedicated strictly to daily vehicle turnover—they cannot alter fleet pricing or manage admin accounts."

2. **Walk Through the Operational Lifecycle**:
   - **Tab 1 — Operations Overview**: Live KPIs for pending requests, pickups due today, active rentals, and overdue returns.
   - **Tab 2 — Reservation Approvals**: Approve or reject incoming customer requests with conflict checks.
   - **Tab 3 — Pickup Turnover Desk**: Record starting odometer reading and dispatch fuel tank level before releasing keys.
   - **Tab 4 — Returns & Check-in**: Record return odometer and fuel level.
     - **Small Benefit to Mention**: *"The system enforces a fair 30-minute return grace period, a 2-hour turnaround buffer for cleaning and safety inspection, PHP 65/L gasoline (PHP 62/L diesel) fuel deficiency surcharges, and PHP 12/km excess mileage."*
   - **Tab 5 — Payment Collection Desk**: Record on-site cash/transfer settlements and issue receipts.
   - **Tab 6 — Incidents & Damages**: Log vehicle damage with estimated repair costs or report missed returns.

---

### Part 3: Branch Administrator Hub Isolation (2 Minutes)

1. **Log out -> Log in as Branch Admin (`demo.branchadmin@velo.local` / `Password123!`)**
   - Point out the sidebar profile card: **Branch Administrator** badge + **Downtown Business Hub (Makati)** scope + `HUB OPERATIONS` header.

2. **Demonstrate Regional Hub Locking**:
   - **Fleet Management (`/admin/admin-vehicles.php`)**:
     - The Hub filter is locked to **Makati Hub** (7 vehicles).
     - When adding a vehicle, the hub field is locked to Makati.
     - Cross-hub vehicles from Quezon City or Airport Hub cannot be retired, archived, or modified (`Managed by another hub` protection enforced on both UI and server POST handlers).
   - **Staff & Customers (`/admin/admin-users.php`)**:
     - The `Administrators` filter tab is hidden.
     - Opening **User Settings** shows only `Customer` and `Operations Staff` in the role dropdown—a Branch Admin cannot promote or suspend another administrator.
   - **Account Settings (`/admin/admin-settings.php`)**:
     - Only shows personal profile and password change; system-wide commercial policies are hidden.

---

### Part 4: Super Administrator Governance & 3NF Architecture (3 Minutes)

1. **Log out -> Log in as Super Admin (`demo.admin@velo.local` / `Password123!`)**
   - Point out the purple **Super Administrator** badge, **Global / All Hubs** indicator, and `SYSTEM OVERSIGHT` header.

2. **Show Super-Admin-Only Powers**:
   - **Fleet Management (`/admin/admin-vehicles.php`)**: Full access across all 30 vehicles and all 3 hubs (Quezon City, Makati, Pasay/NAIA). Can retire, unretire, archive, or reassign any car.
   - **Rental Oversight (`/admin/admin-rentals.php`)**: Show the Quick Navigation pill bar linking seamlessly to **Penalties & Standing**, **Incidents & Escalations**, and **Disputes & Appeals**, plus Super-Admin-only **Suspend/Archive Customer** buttons on severe incidents.
   - **User Management (`/admin/admin-users.php`)**: Full directory across all hubs; can assign `Branch Administrator` and `Super Administrator` roles.
   - **System & Settings (`/admin/admin-settings.php`)**: Display the **Operational Policies & Penalty Rates** matrix and **Operating Venue & Depot Directory**.

3. **Switch to Tab 2: `http://vrms.local/database-clusters.html`**
   - Click the cluster filter buttons (**Cluster 1: Identity**, **Cluster 2: Fleet**, **Cluster 3: Operations**, **Cluster 4: Pricing & Promotions**).
   - Scroll down to show the **Cross-Cluster ER Diagram**.
   - **What to say**: *"Our database is strictly normalized to Third Normal Form across four domain clusters. Vehicle makes, models, transmissions, and fuel types are extracted into lookup tables, and our Philippine Pricing Engine freezes exact discount snapshots on every reservation so historical receipts never change even if promo rates are updated later."*

---

## 4. Technical Q&A Defense Cheat Sheet

- **Q: What happens if there is no Wi-Fi during a live deployment or demo?**
  - **A**: Every vehicle image is bundled locally in `public/assets/vehicles/` (zero external image URLs), backed by an SVG fallback (`vehicle-placeholder.svg`), a Service Worker (`sw.js`), and a `localStorage` catalog cache fed by `/api/vehicles.php`.

- **Q: How do Senior Citizen and PWD discounts work in the pricing engine?**
  - **A**: `includes/pricing-engine.php` implements RA 9994 (Senior Citizen) and RA 10754 (PWD): it verifies a non-expired OSCA/NCDA ID record in `customer_eligibility_verifications`, removes 12% Philippine VAT (`Gross / 1.12` per BIR RR 7-2010) when VAT exemption is toggled, applies 20% to the VAT-exclusive base, and automatically compares total savings against commercial promos so the customer gets the highest legal benefit without double-stacking.

- **Q: What happens if an admin deletes a user or vehicle that already has rental history?**
  - **A**: The system refuses hard deletion when financial or rental history exists and automatically performs a **soft-delete archive** instead, preserving foreign-key integrity and legal audit trails in `audit_logs`.

- **Q: Why did you separate Branch Administrator from Super Administrator?**
  - **A**: In real multi-branch rental companies, a Makati depot manager should manage Makati's fleet and staff without accidentally retiring Quezon City cars, changing company-wide penalty rates, or suspending other branch managers. Both UI controls and backend POST handlers enforce this boundary.
