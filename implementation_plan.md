# VELO Implementation Plan

## Delivery approach

Development will proceed milestone by milestone. Each milestone must work and
be tested before the next domain is added. The master requirements remain in
[system.md](./system.md); this document defines the practical implementation
scope for the current project.

## Milestone 1: Foundation

### Deliverables

- Fresh normalized schema
- Users, roles, permissions, and assignments
- Registration, login, logout, and password hashing
- Admin, staff, and customer authorization guards
- Staff approval
- Customer profile
- CSRF and input validation
- Initial audit logging

### Acceptance checks

- Customers cannot access staff or admin pages.
- Staff cannot access admin-only pages.
- Staff cannot assign permissions.
- Suspended accounts cannot submit reservations.
- Users cannot read another user's private records.

## Milestone 2: Fleet management

### Deliverables

- Vehicle categories
- Vehicle create/read/update/archive operations
- Vehicle status
- VELO-owned locations
- Maintenance scheduling
- Availability queries

### Acceptance checks

- Retired vehicles cannot be reserved.
- Vehicles under maintenance cannot be reserved.
- Inactive locations cannot be selected.
- Historical vehicles and locations are archived, not deleted.

## Milestone 3: Reservations

### Deliverables

- Vehicle catalog and date search
- Reservation creation
- Atomic overlap and maintenance checks
- Staff approval and rejection
- Cancellation requests
- Reservation status history
- In-system notifications

### Acceptance checks

- Invalid dates and insufficient notice are rejected.
- Overlapping reservations are rejected.
- A customer cannot create overlapping active reservations.
- Staff cannot approve their own reservation.
- Every decision records an actor and reason.
- Simultaneous requests cannot incorrectly reserve the same vehicle.

## Milestone 4: Rental operations

### Deliverables

- Pickup confirmation
- Rental creation from an approved reservation
- Pickup record
- Mileage, fuel, and condition records
- Return record
- Return inspection
- No-show handling
- Rental status history

### Acceptance checks

- Rejected or cancelled reservations cannot become rentals.
- Pickup creates exactly one rental.
- Return mileage cannot be lower than pickup mileage.
- A completed rental cannot be returned again.
- No-show cannot be recorded before the grace period.

## Milestone 5: Financial management

### Deliverables

- Base and additional rental charges
- Partial payment recording
- Outstanding balance
- Late-fee calculation
- Return damage reporting
- Deposit and refund records when approved for scope
- Receipts or printable payment summaries

### Acceptance checks

- Payments cannot exceed the remaining balance without an authorized adjustment.
- Refunds cannot exceed the amount paid.
- Deposits are not counted as rental revenue.
- Completed transactions are corrected through adjustment records.
- Late fees begin only after the configured grace period.

The current operational defaults are intentionally conservative and can be
changed when the business policy is approved:

- Late-fee grace period: 30 minutes.
- Late-fee rate: ₱250 per billable hour.
- Late-fee cap: one vehicle daily rate per return.
- Damage is reported at return first; an administrator must review it before a
  damage charge is created.
- Payments are checked against all reservation and rental charge lines.

## Milestone 6: Administration and accountability

### Deliverables

- Penalty event ledger
- Customer account standing
- Admin overrides
- Disputes and appeals when approved for scope
- Audit-log viewer
- Reports
- Notification center
- Policy settings when the group approves configurable policies

Recommended initial policy defaults:

- Base rental charges are settled before pickup.
- Accepted payment methods: cash/in person, bank transfer, GCash, and card.
- No-show events create 3 pending points.
- Late-return events create 1 pending point.
- Penalty events expire after 12 months.
- 3-4 active approved points means warning.
- 5-7 means restricted from new reservations.
- 8 or more means suspended pending administrator review.

Points are event-based rather than a manually edited account score. Each
approved event expires independently after 12 months. A customer can recover
through sustained good behavior as old events expire, while an administrator
may waive an incorrectly recorded pending event.

### Acceptance checks

- Staff cannot waive or delete penalties.
- Admin overrides require a reason.
- Penalty events remain historical.
- Expired penalty events are not counted.
- Suspended customers cannot create reservations.
- Sensitive actions appear in audit history.

## Scope labels for system.md

Each requirement should eventually be labeled:

- **MVP**: required for the initial working release.
- **PLANNED**: intended for a later milestone.
- **OPTIONAL**: depends on time, resources, or adviser approval.
- **OUT OF SCOPE**: not included in this project version.

The current recommended MVP includes Milestones 1 through 4, basic payments
from Milestone 5, and notifications/audit support from Milestone 6. Online
payments, external pickup partners, email delivery, document uploads, and
advanced appeals remain planned or optional.

The selected vehicle-image provider is Pexels. The API key is read from the
`PEXELS_API_KEY` server environment only. Without a key, the seeder preserves
existing image URLs and uses the local placeholder for new records.

Serious non-return incidents should be handled as an operational escalation:
staff document the missed return and attempt approved contact, administrators
may suspend the account and preserve evidence, and authorized institutional
management decides whether to pursue a formal disciplinary, security, or legal
process. The application must record facts and decisions with audit entries; it
must not impersonate law enforcement or automatically threaten legal action.

The non-return workflow includes a missed-return incident, contact-attempt
records, administrator suspension, and optional institutional/security/legal
referral records. These records are append-only operational evidence and do
not change the application into a law-enforcement system.

## Migration strategy

The current combined `rentals` workflow must not be partially modified. The
migration sequence is:

1. Back up the current database.
2. Replace `database/schema.sql` with the normalized schema.
3. Test the schema in a temporary database.
4. Import it into XAMPP because demo data may be discarded.
5. Recreate the administrator account and sample fleet.
6. Implement foundation authorization.
7. Migrate reservation creation and approval.
8. Add rental creation at pickup.
9. Migrate payments and return processing.
10. Remove obsolete combined-rental assumptions after workflow tests pass.

At every stage, the application must remain syntactically valid and the
previously completed milestone must continue to work.
