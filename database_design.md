# VELO Database Design

## Purpose

This document defines the database foundation for the next VELO implementation.
It separates a customer's reservation request from the rental period in which the
customer has possession of a vehicle.

The design is intentionally divided into a core release and later extensions.
The core schema must support the complete reservation-to-return flow without
requiring online payments, external pickup partners, or automated email delivery.

## Core business distinction

| Entity | Meaning |
|---|---|
| Reservation | A customer's request to use a vehicle for a planned period |
| Rental | The actual vehicle possession period after pickup |
| Charge | An amount owed for a reservation or rental |
| Payment | Money received by VELO |
| Deposit | A security amount held separately from rental revenue |
| Dispute | A contested charge, incident, or operational decision |

A reservation may be rejected, cancelled, or marked as a no-show without ever
creating a rental. A rental is created only when staff confirms pickup.

## Core entities

### Identity and access

- `users`: account identity, credentials, profile, and account status.
- `roles`: assignable roles such as admin, staff, and customer.
- `permissions`: individual capabilities.
- `user_roles`: many-to-many user-to-role assignments.
- `role_permissions`: many-to-many role-to-permission assignments.
- `user_documents`: planned verification records; document storage is not part
  of the first operational release.

The initial roles are:

```text
admin
staff
customer
```

The `users.role` column should be removed after the application has migrated to
`user_roles`. During migration, it may be retained temporarily as a compatibility
field, but it must not be the long-term authorization source.

### Fleet and locations

- `vehicle_categories`: configurable categories rather than an enum in `vehicles`.
- `vehicles`: fleet identity, public specifications, pricing, and operational status.
- `locations`: VELO-owned pickup and return locations.
- `maintenance`: scheduled or active work that blocks availability.

Vehicle statuses:

```text
operational
maintenance
unavailable
retired
```

Location statuses:

```text
active
inactive
```

Historical vehicles and locations are archived by status, not physically deleted.

### Reservations

- `reservations`: customer request and agreed booking terms.
- `reservation_status_history`: append-only status transitions.
- `reservation_cancellation_requests`: cancellation requests and decisions.

Reservation statuses:

```text
pending
confirmed
rejected
cancelled
no_show
```

Reservations store a pricing snapshot. Later changes to a vehicle's rate must
not change an existing reservation.

Required reservation rules for the first release:

- One active reservation per customer.
- Pickup and return locations are required.
- Pickup and return date/time are required.
- Same-day booking is allowed only when the minimum notice policy permits it.
- Pending reservations do not create a permanent availability hold.
- Overlap checks occur in the same transaction as reservation creation or approval.
- Suspended or restricted customers cannot create new reservations.
- Customers request cancellation; authorized staff or admin decides it.

### Rentals and operations

- `rentals`: actual possession period, linked one-to-one to a reservation.
- `pickup_records`: one pickup event for a rental.
- `return_records`: one return event for a rental.
- `vehicle_inspections`: condition and mileage/fuel evidence.
- `damage_reports`: documented damage incidents.

Rental statuses:

```text
ongoing
returned
completed
```

Disputes are not rental statuses. A completed rental may still have an open
dispute.

Pickup creates the rental and the pickup record in one transaction. Return
creates the return record and changes the rental to `returned`. Completion
follows inspection and financial settlement.

### Financial records

- `rental_charges`: immutable charge lines such as base rental, late fee,
  damage, discount, or other charge.
- `payments`: money received and its recording metadata.
- `deposits`: planned security-deposit lifecycle.
- `refunds`: planned money returned to a customer.
- `payment_adjustments`: planned corrections and reversals.

Financial records are never silently edited or deleted. Corrections use an
adjustment, reversal, or refund record.

The initial operational release records a return-time damage report separately
from a financial damage charge. This prevents a staff inspection note from
automatically becoming an amount owed. Late fees are generated once during
return processing after a 30-minute grace period, charged hourly at the
configured operational rate, and capped at one daily vehicle rate.

Administrators review each open damage report. They may create one finalized
damage charge or waive the report with a required reason. Both outcomes remain
visible in audit history and notify the customer.

Payment settlement includes an immutable receipt for each recorded payment.
Refunds cannot exceed the unrefunded amount of the original payment, and
adjustments require an administrator reason. These records preserve the
financial trail instead of editing or deleting the original payment.

For a vehicle that is not returned, the application records an operational
incident, immediately prevents further reservations through account standing,
and preserves all contact and audit records. Any demand letter, institutional
disciplinary action, security response, or police/legal report must be handled
by authorized VELO management under applicable law and institutional policy;
the application should support documentation and escalation status, not make
legal conclusions or threaten the customer.

Return incidents are tracked separately from rental status. Staff can record
the missed return and contact attempts. Administrators can suspend the account
and record an institutional, security, or legal referral with a reason. The
referral is a record of an authorized decision, not an automatic external
notification.

The recommended payment policy is to settle the base reservation charge before
pickup. Customers may choose cash/in-person payment, bank transfer, GCash, or
card; staff records the payment before the pickup action is allowed. Late fees
and reviewed damage charges are assessed after return and must be settled
before completion. No card numbers or banking credentials are stored.

The initial standing policy uses a 12-month expiry window:

- No-show: 3 pending penalty points.
- Late return: 1 pending penalty point per late-return event.
- 0-2 approved active points: good standing.
- 3-4 points: warning.
- 5-7 points: restricted from new reservations.
- 8 or more points: suspended pending administrator review.

Operational events are pending until an administrator approves or waives them.
Points do not decrease through an untracked monthly decay. Each approved event
expires independently after 12 months, so sustained good behavior gradually
restores eligibility. A customer who reaches restricted or suspended standing
cannot bypass the restriction by simply waiting a few days; they must either
wait for enough event expirations or submit the matter for administrator review.

### Accountability and support

- `penalty_events`: append-only documented customer incidents.
- `penalty_appeals`: customer challenges to penalty events.
- `customer_account_standing`: current derived or reviewed account restriction.
- `disputes`: contested rental, charge, damage, or operational decisions.
- `notifications`: in-system messages.
- `audit_logs`: append-only record of sensitive actions.

Penalty points expire according to policy and must be calculated from active
events rather than maintained as an unrestricted editable number.

## Relationship summary

```text
users ──< reservations >── vehicles
  │            │              │
  │            │              └──< maintenance
  │            └── locations
  │
  └──< user_roles >── roles ──< role_permissions >── permissions

reservations ── 0..1 ── rentals
rentals ── 1 ── pickup_records
rentals ── 0..1 ── return_records
rentals ──< rental_charges
rentals ──< payments
rentals ──< vehicle_inspections
rentals ──< damage_reports

reservations ──< reservation_status_history
reservations ──< reservation_cancellation_requests
rentals/reservations ──< disputes
users ──< penalty_events ──< penalty_appeals
users ──< notifications
users ──< audit_logs
```

## Status transition rules

### Reservations

| Current | Action | Authorized actor | Result |
|---|---|---|---|
| pending | approve | authorized staff/admin | confirmed |
| pending | reject | authorized staff/admin | rejected |
| pending | cancel request | customer | cancellation request |
| confirmed | cancel request | customer | cancellation request |
| confirmed | no-show | authorized staff | no_show |
| pending/confirmed | approve cancellation | staff/admin | cancelled |
| pending/confirmed | reject cancellation | staff/admin | unchanged |

### Rentals

| Current | Action | Authorized actor | Result |
|---|---|---|---|
| none | confirm pickup | operations staff/admin | ongoing |
| ongoing | confirm return | operations staff/admin | returned |
| returned | complete inspection/settlement | authorized staff/admin | completed |

Every transition records the previous status, new status, actor, reason, and
timestamp. Invalid transitions are rejected server-side.

## Integrity and security rules

- Use foreign keys and restrictive deletes for business history.
- Use unique email addresses, role names, permission names, and vehicle plates.
- Use decimal types for money.
- Use transactions for reservation creation, approval, pickup, return, payments,
  refunds, and status changes.
- Lock the vehicle/reservation rows during overlap validation.
- Enforce authorization in PHP, not only in navigation or forms.
- Customers may access only their own records.
- Staff permissions must be checked for every operational action.
- Admin overrides require a reason and an audit record.
- Audit and status history are append-only from the application.
- Store uploaded documents and inspection photos outside the public web root.
- Never store raw card numbers, CVV values, or other payment credentials.

## Deliberately deferred

These are compatible with the design but should not block the core release:

- Email verification and password-reset delivery
- External pickup partners
- Online payment gateway
- Automated deposits and refunds
- Document and inspection-photo upload workflows
- Penalty appeals and advanced disputes
- PDF/Excel exports
- SMS notifications
- Configurable policy administration
