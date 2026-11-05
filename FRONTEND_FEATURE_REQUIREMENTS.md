# VELO Frontend Feature Requirements

This document defines the frontend redesign scope for VELO Vehicle Rental
Management System. The redesign should preserve the normalized backend
workflow and make the customer, staff, and administrator experiences distinct.

## 1. Product goals

- Make vehicle discovery and reservation simple for customers.
- Make pickup, return, payment, and incident work fast for staff.
- Give administrators clear oversight of users, fleet, finances, penalties,
  and operational incidents.
- Show the current state of every workflow clearly.
- Work well on desktop, tablet, and mobile screens.
- Keep actions understandable and prevent accidental destructive operations.

## 2. Roles and navigation

### Customer

Primary navigation:

- Browse vehicles
- My reservations
- My rentals
- Payments and receipts
- Penalties and account standing
- Account settings
- Log out

### Staff

Primary navigation:

- Operations dashboard
- Reservation queue
- Pickup processing
- Return processing
- Payment collection
- Damage and inspection records
- Non-return incidents
- Account settings
- Log out

### Administrator

Primary navigation:

- Administration dashboard
- Users and roles
- Fleet and vehicle images
- Maintenance
- Reservations and rentals
- Payments, refunds, and adjustments
- Penalties and account standing
- Incidents and escalations
- Reports
- Account settings
- Log out

Navigation must not expose links to actions the current role cannot perform.

## 3. Authentication and account access

### Login

The login screen must include:

- Email address
- Password
- Show/hide password control
- `Forgot password?` link
- Login button
- Link to customer registration
- Clear invalid-credentials message
- Clear pending or rejected staff-account message
- Loading/disabled state while submitting
- Keyboard-accessible controls

The show-password control must:

- Toggle only the password field.
- Use an accessible button with an accessible label.
- Not submit the form.
- Preserve the entered value when toggled.
- Use an icon plus text or an appropriate screen-reader label.

### Public customer registration

The registration screen must include:

- Full name
- Email address
- Password
- Confirm password
- Show/hide control for both password fields
- Password requirements displayed before submission
- Inline validation
- Duplicate-email feedback
- Terms/privacy acknowledgement if required by the institution
- Link back to login

Registration must never provide a role selector. Public registration creates
customers only. Staff and administrator accounts are created by authorized
administrators or the CLI utility.

### Password reset

The frontend must provide a complete password-reset journey:

1. Login page links to `Forgot password?`.
2. User submits an email address.
3. The response must not reveal whether the email exists.
4. A reset-request confirmation page is shown for both existing and unknown
   addresses.
5. A single-use, time-limited reset link is sent for existing accounts.
6. The reset page accepts a valid token and a new password.
7. New password and confirmation must match.
8. The reset token expires after its configured lifetime and cannot be reused.
9. Successful reset invalidates active sessions where appropriate.
10. The user is redirected to login with a success message.

Required reset states:

- Invalid or expired token
- Already-used token
- Weak password
- Mismatched passwords
- Rate-limited reset requests
- Mail delivery failure
- Successful reset

The UI must use a generic request response such as:

> If an account exists for that email, reset instructions will be sent.

The backend reset-token flow must be implemented before this feature is
considered complete. Reset tokens must be stored hashed, not in plaintext.

### Change password

Authenticated users must be able to:

- Enter their current password.
- Enter and confirm a new password.
- Show/hide each password field.
- See password requirements.
- Receive success or validation feedback.

## 4. Customer experience

### Customer dashboard

Show:

- Greeting and account summary
- Account standing: good, warning, restricted, or suspended
- Active penalty points and expiration information
- Upcoming reservation
- Current rental, if any
- Outstanding balance
- Recent payments and receipts
- Recent penalties
- Important notifications and required actions

### Vehicle browsing

Provide:

- Search by make, model, and category
- Filters for seats, category, price, transmission, and availability
- Sort by price, popularity, and relevance
- Vehicle cards with image, name, category, seats, transmission, daily rate,
  and availability
- Placeholder state when no image is available
- Empty search results state
- Vehicle detail page
- Clear reservation call to action

Vehicle images loaded from Pexels must display any attribution required by
the provider or project policy. The frontend must not contain or request the
Pexels API key.

### Reservation flow

The reservation flow must show:

1. Selected vehicle
2. Pickup location and date/time
3. Return location and date/time
4. Price estimate
5. Payment method
6. Reservation policies
7. Account-standing eligibility
8. Confirmation before submission
9. Success page with reservation reference

Supported payment methods:

- Cash / in person
- Bank transfer
- GCash
- Card

Block or explain:

- Restricted or suspended customers
- Invalid date ranges
- Unavailable vehicles
- Missing required information
- Insufficient required pre-pickup payment

### Reservation and rental details

Display:

- Reservation status
- Rental status when pickup has occurred
- Vehicle and location details
- Pickup and return times
- Charges
- Payments
- Receipts
- Refunds
- Adjustments
- Outstanding balance
- Cancellation request status
- Staff notes intended for customer visibility

Use the correct terminology:

- Reservation: booking before pickup
- Rental: active or completed vehicle use

### Customer payments and receipts

Customers must be able to:

- View all payments
- View payment method and date
- Open receipt details
- See receipt number
- See refunds
- See credits and debits
- See current balance
- Distinguish pending, completed, refunded, and failed states

### Customer penalties and standing

Show:

- Current standing
- Active points
- Point threshold explanation
- Event type, reason, status, and expiration date
- No-show and late-return history
- Clear explanation when reservations are restricted

Do not expose internal legal or security notes that are not intended for
customers.

## 5. Staff operations

The staff interface should prioritize speed, scanning, and task completion.

### Staff dashboard

Show:

- Reservations awaiting review
- Pickups due today
- Returns due today
- Overdue rentals
- Unpaid balances
- Open damage reports
- Open non-return incidents
- Quick actions for pickup, return, payment, and incident recording

### Reservation processing

Staff must be able to:

- Review reservation details
- Confirm a reservation
- Reject a reservation with a required reason
- Record cancellation decisions
- Mark eligible no-shows after the grace period
- See the customer standing before approval

### Pickup processing

Provide a guided checklist for:

- Customer identity verification
- Vehicle and plate confirmation
- Odometer and fuel level
- Existing damage inspection
- Required payment verification
- Customer acknowledgement
- Pickup record creation

### Return processing

Provide a guided checklist for:

- Actual return time
- Odometer and fuel level
- New damage inspection
- Late-fee preview
- Additional charge preview
- Payment collection
- Customer acknowledgement
- Return completion state

### Payments

Staff must be able to record permitted payments with:

- Amount
- Method
- Reservation or rental reference
- Notes
- Receipt creation
- Confirmation before saving

The interface must show the resulting balance after payment.

### Damage and inspections

Staff can record factual inspection findings and damage reports. The UI must
keep a damage report separate from an approved financial charge.

## 6. Administrator experience

### Admin dashboard

Show high-level cards and drill-down links for:

- Reservations
- Active rentals
- Completed rentals
- Fleet availability
- Revenue and payments
- Refunds
- Outstanding balances
- Active penalties
- Open incidents
- Maintenance due

### User management

Provide:

- Search and filtering
- Role display
- Account status
- Staff approval and rejection
- Role assignment
- Suspension controls
- Confirmation dialogs for privileged changes
- Audit visibility where appropriate

### Fleet and image management

Provide:

- Vehicle list and search
- Availability/status
- Category and daily rate editing
- Vehicle image preview
- Placeholder state
- Manual image replacement
- Maintenance status

The image seeder is an administrative/server operation. The browser must
never receive `PEXELS_API_KEY`.

### Financial administration

Provide:

- Charge review
- Payment review
- Receipt lookup
- Refund recording with limits
- Credit/debit adjustment recording
- Balance reconciliation
- Confirmation and audit details

### Penalties and incidents

Provide:

- Pending penalty review
- Approve, waive, or reverse actions
- Point and expiration visibility
- Non-return incident timeline
- Contact attempts
- Suspension action
- Institutional, security, or legal referral recording
- Status and resolution tracking

The application records authorized institutional decisions. It must not
automatically threaten customers, claim that a crime occurred, or impersonate
law enforcement.

### Reports

Reports should support:

- Date filtering
- Vehicle/category filtering
- Export-friendly tables
- Reservation totals
- Utilization
- Revenue and payment totals
- Refund totals
- Late-fee totals
- Damage-charge totals
- Outstanding balances
- No-shows
- Penalty activity
- Incident status and resolution

## 7. Shared UI requirements

- Responsive layout for mobile, tablet, and desktop.
- Consistent buttons, forms, cards, tables, badges, and alerts.
- Distinct colors and labels for success, warning, danger, neutral, and
  pending states.
- Accessible focus states and keyboard navigation.
- Labels associated with every form control.
- Errors shown near the relevant field and summarized when useful.
- Confirmation dialogs for refunds, suspensions, rejection, waiver, deletion,
  and other irreversible actions.
- Empty states for every list and dashboard panel.
- Loading states for asynchronous actions.
- Success feedback after every completed mutation.
- Avoid exposing sensitive data in URLs or client-side scripts.
- Escape all user-controlled output.
- Do not rely on color alone to communicate status.

## 8. Backend prerequisites before frontend completion

The core authentication foundation is implemented. It includes email
verification, password reset, rate limiting, single-use expiring tokens,
session regeneration after reset, audit records, and show-password controls.
The links are demo-delivered through the PHP log/session until a real mail
provider is configured.

The following backend work is required for the frontend features above:

- Password-reset token table and mail delivery integration.
- Password-reset request and reset endpoints/pages.
- Change-password handling.
- Session invalidation after password reset where required.
- Receipt and refund detail endpoints or server-rendered views.
- Report filtering and export support.
- Explicit authorization checks for every role-specific action.
- Consistent notification and audit records.

## 9. Definition of done

The frontend redesign is complete when:

- Each role has a coherent responsive workspace.
- Customers can register, log in, reset passwords, browse, reserve, pay, and
  review receipts and standing.
- Staff can complete pickup, return, payment, inspection, and incident flows.
- Administrators can manage users, fleet, finances, penalties, incidents, and
  reports.
- Every major success, error, empty, loading, and unauthorized state has a
  designed presentation.
- Show-password controls work on registration, login, reset, and change-password
  forms.
- Automated backend tests pass.
- Authentication and role boundaries are verified manually and through tests.
- No secret API key is present in source code or browser responses.
