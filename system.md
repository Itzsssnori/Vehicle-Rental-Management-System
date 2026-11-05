Vehicle Rental Management System (VRMS)
SYSTEM REQUIREMENTS • BUSINESS RULES • FUNCTIONAL SPECIFICATION

Primary objective: Develop a complete vehicle rental platform that manages customers, vehicles, reservations, rental operations, payments, maintenance, penalties, and business administration.

1. System scope and objectives
The system should support the entire vehicle rental lifecycle:

CUSTOMER MANAGEMENT

Registration · Verification · Account management

VEHICLE DISCOVERY AND RESERVATION

Browsing · Availability · Booking · Approval

RENTAL OPERATIONS

Payment · Pickup · Inspection · Return

BUSINESS MANAGEMENT

Maintenance · Penalties · Reports · Audit history

The system will have three primary account roles:
1. Administrator
2. Staff
3. Customer

Staff will have configurable operational permissions instead of unrestricted access to every staff function.

2. Account roles and permissions
2.1 Administrator
The administrator has overall control of the business and system.

Account and personnel management
- Create, view, update, suspend, and archive user accounts.
- Approve or reject staff registration requests.
- Assign and revoke staff permissions.
- Change account roles.
- Suspend and restore customer accounts.
- View user activity and audit logs.
Vehicle management
- Add, edit, and archive vehicles.
- Manage vehicle categories.
- Configure rental rates.
- Manage vehicle availability.
- Schedule and manage maintenance.
- Retire vehicles.
Reservation management
- View all reservations.
- Approve or reject reservations.
- Override staff decisions with a recorded reason.
- Review cancellation requests.
- Handle booking disputes.
- Create reservations on behalf of customers.
Financial management
- View all payment records.
- Approve refunds.
- Manage deposits.
- Review late fees and additional charges.
- Generate financial reports.
Business administration
- Manage pickup and return locations.
- Configure cancellation policies.
- Configure rental rates and fees.
- Configure penalty thresholds.
- Review system reports.
- Manage system settings.

2.2 Staff
Staff handles day-to-day rental operations.

General responsibilities
- View operational dashboard.
- View customer information required for assigned tasks.
- View reservation schedules.
- Verify customer documents.
- Process authorized reservations.
- Record operational activities.
- Contact customers regarding reservations.
Reservation responsibilities
- View pending reservations.
- Approve or reject reservations if authorized.
- Provide rejection reasons.
- Review cancellation requests.
- Record no-shows.
Vehicle operations
- Confirm vehicle pickup.
- Record vehicle mileage and fuel level.
- Upload inspection photos.
- Record vehicle condition.
- Confirm vehicle returns.
- Report damages.
- Report maintenance requirements.
Payment responsibilities
- Record payments.
- Verify payment transactions.
- Generate receipts.
- Record additional charges when authorized.
Restrictions
- Cannot manage administrator accounts.
- Cannot assign staff permissions.
- Cannot delete rental or payment records.
- Cannot permanently remove vehicles.
- Cannot waive penalties.
- Cannot approve their own rental requests.
- Cannot modify business-wide policies.
- Cannot erase audit history.
2.3 Customer
Account management
- Register and log in.
- Verify email.
- Update profile.
- Change password.
- Submit identification documents.
- View account verification status.
Vehicle discovery
- Browse vehicles.
- Search and filter vehicles.
- View vehicle specifications.
- View rental rates.
- Check availability.
Reservations
- Submit rental requests.
- View reservation details.
- Track reservation status.
- Request cancellation.
- View reservation history.
- View pickup and return information.
Payments
- View rental charges.
- View payment history.
- Submit payment references or transaction details.
- View deposits and refunds.
- Download receipts.
Account standing
- View violations and penalty points.
- Submit penalty appeals.
- View suspension status.
- Contact staff.
Customers must never be able to modify another customer's records or directly change the status of their own rentals.

2.4 Staff permission groups
Rather than creating separate account roles for every department, use permissions assigned to individual staff accounts.
Permission group	Responsibilities
Reservation Staff	Review, approve, reject, and process reservations
Rental Operations Staff	Vehicle pickup, return, inspection, and damage reporting
Payment Staff	Payment recording, verification, and receipts
Customer Support Staff	Customer inquiries, account assistance, and cancellation coordination
Maintenance Staff	Maintenance records, vehicle inspections, and repair updates

A staff member can have multiple permission groups.

An administrator controls these permissions. Each permission must also be enforced by the backend, not just hidden from the interface.

3. Account registration and verification rules
Customer registration
Recommended workflow:
1. Customer submits registration.
2. System validates required information.
3. System checks whether the email already exists.
4. System hashes the password.
5. System sends an email verification link.
6. Customer verifies their email.
7. Account becomes active.
8. Customer can browse vehicles and submit reservations.

Recommended business rules
Decision	Default policy
Automatic customer approval	Yes, after email verification
Email verification	Required
Unique email	One account per email
Driver's license	Required before vehicle pickup
Multiple accounts	Not allowed using the same email
Suspended customer login	Allowed with restricted functionality
Account deletion	Restricted when active rentals or unpaid balances exist

(show password, forgot password should exists).

Staff registration
1. Staff submits registration.
2. Account status becomes PENDING_APPROVAL.
3. Administrator reviews the application.
4. Administrator approves or rejects it.
5. Approved staff receives access.
6. Administrator assigns operational permissions.
Staff registration must never automatically grant administrative access.

Customer verification
Customer verification should include:
- Full name.
- Email address.
- Contact number.
- Valid identification.
- Driver's license.
- License expiration date.
- Verification status.
Suggested verification statuses:

UNVERIFIED, PENDING, VERIFIED, REJECTED, EXPIRED.

A customer can browse vehicles without a verified license, but cannot receive a vehicle until all applicable verification requirements are satisfied.

4. Vehicle management
4.1 Vehicle information
Every vehicle should have:
- Vehicle ID.
- Brand.
- Model.
- Year.
- Plate number.
- Vehicle category.
- Transmission type.
- Fuel type.
- Seating capacity.
- Mileage.
- Rental rate.
- Vehicle description.
- Vehicle images.
- Registration information.
- Insurance information.
- Current operational status.
- Date added.
- Last updated.
4.2 Vehicle categories
Examples:
- Sedan
- SUV
- MPV
- Van
- Pickup truck
- Motorcycle, if supported by the business
Administrators should be able to add and manage categories without changing application code.

4.3 Vehicle status
Status	Description
AVAILABLE	Ready for reservation
RESERVED	Allocated to an approved reservation
RENTED	Currently being used by a customer
MAINTENANCE	Under repair or scheduled maintenance
UNAVAILABLE	Temporarily unavailable
RETIRED	Permanently removed from the rental fleet

A vehicle's reservation calendar should determine whether it is available for specific dates. Its general operational status should not replace date-based availability checks.

4.4 Vehicle management rules
- Only authorized personnel can add or modify vehicles.
- Customers can only see public vehicle information.
- Vehicles under maintenance cannot be reserved.
- Retired vehicles cannot receive new reservations.
- Vehicles with historical rental records must be archived rather than physically deleted.
- Every status change must be recorded.
- Mileage must be updated during pickup and return.
- Vehicle maintenance history must be retained.

5. Reservation and booking management
5.1 Reservation information
Each reservation should contain:
- Reservation ID.
- Unique reservation reference.
- Customer ID.
- Vehicle ID.
- Pickup location.
- Return location.
- Requested pickup date and time.
- Requested return date and time.
- Rental duration.
- Base rental rate.
- Estimated rental fee.
- Additional charges.
- Discount, if applicable.
- Total amount.
- Reservation status.
- Date submitted.
- Approved or rejected by.
- Approval timestamp.
- Rejection reason, if applicable.
5.2 Booking rules
Decision	Default policy
Same-day booking	Allowed if availability permits
Minimum booking notice	2 hours
Multiple active rentals per customer	One initially
Multiple vehicles for the same dates	Not allowed initially
Staff approving their own reservation	Prohibited
Booking overlapping another rental	Prohibited
Booking during maintenance	Prohibited
Booking using a suspended account	Prohibited
Pending reservation availability hold	30 minutes, subject to confirmation

5.3 Availability validation
Before accepting a reservation, the system must check:
1. Whether the vehicle exists and is active.
2. Whether the vehicle is operational.
3. Whether the requested dates are valid.
4. Whether another reservation overlaps the requested period.
5. Whether maintenance conflicts exist.
6. Whether the pickup and return locations are operational.
7. Whether the customer meets account requirements.
8. Whether the customer has outstanding restrictions.
9. Whether the minimum booking notice is satisfied.
Critical requirement: Availability checks and reservation creation must be handled atomically using database transactions or appropriate locking. Otherwise, two customers can submit requests for the same vehicle at nearly the same time.

5.4 Reservation modification
Customers should be allowed to request changes to:
- Pickup date.
- Return date.
- Pickup location.
- Return location.
However, modifications must be reviewed when they affect vehicle availability, pricing, or cancellation conditions.

Customers should not directly overwrite confirmed reservation records.

6. Reservation lifecycle and status management
The system must have a clearly defined reservation lifecycle.

PENDING

Customer submitted a reservation request.



CONFIRMED

Authorized staff or Admin approved it.



PICKUP_READY

Vehicle and documents are prepared for release.



ONGOING

Vehicle has been released and is currently rented.



RETURNED

Vehicle has been returned and is awaiting inspection completion.



COMPLETED

Inspection and financial settlement are complete

Alternative outcomes
REJECTED
CANCELLED
NO_SHOW
DISPUTED

The PICKUP_READY state is optional. If you want a simpler first implementation, staff can move directly from CONFIRMED to ONGOING after completing pickup verification.

6.1 Who can change reservation statuses?
Current status	Action	Authorized person	Result
PENDING	Approve	Authorized Staff/Admin	CONFIRMED
PENDING	Reject	Authorized Staff/Admin	REJECTED
PENDING	Request cancellation	Customer	Cancellation request
CONFIRMED	Prepare pickup	Operations Staff	PICKUP_READY
CONFIRMED/PICKUP_READY	Confirm pickup	Operations Staff	ONGOING
CONFIRMED	Request cancellation	Customer	Cancellation request
CONFIRMED	Mark no-show	Authorized Staff	NO_SHOW
ONGOING	Confirm return	Operations Staff	RETURNED
RETURNED	Complete inspection	Operations Staff	COMPLETED
Any eligible status	Resolve dispute	Admin	Appropriate final status

All status changes must create a history record containing:
- Reservation ID.
- Previous status.
- New status.
- User who performed the action.
- Reason or remarks.
- Date and time.
The system must reject unauthorized or invalid status transitions.
7. Cancellation and refund policies
7.1 Cancellation workflow
1. Customer opens their reservation.
2. Customer submits a cancellation request.
3. Customer provides a reason.
4. System checks the reservation status and cancellation deadline.
5. Authorized staff reviews the request.
6. Staff approves or rejects it.
7. System calculates applicable charges.
8. Payment and deposit records are updated.
9. Customer receives a notification.

7.2 Recommended cancellation fee schedule
Time before pickup	Suggested fee
24 hours or more	Free
12–23 hours	10%
Less than 12 hours	25%
No-show	Subject to no-show and deposit policy

These percentages are provisional. They should be configurable by Admin.

7.3 Cancellation rules
- Pending reservations can be cancelled without staff approval if no financial obligation has been incurred.
- Confirmed reservations require a cancellation request.
- Emergency exceptions require Admin approval.
- Cancellation after pickup is not treated as ordinary cancellation.
- Early return must be processed separately.
- Cancellation fees must be visible to customers before confirmation.
- Refunds must be recorded separately from cancellation decisions.

7.4 No-show policy
Recommended procedure:
1. Customer fails to arrive at the scheduled pickup time.
2. Staff attempts to contact the customer.
3. System provides a 30-minute grace period.
4. Staff records the attempted contact.
5. Staff marks the reservation as NO_SHOW.
6. System applies the approved no-show policy.
7. Customer receives a notification.
Deposit forfeiture should only apply when the terms were disclosed and accepted before booking.

8. Vehicle pickup and return management
This module is essential because it connects the digital reservation to the actual physical vehicle.
8.1 Pickup procedure
Before releasing a vehicle, staff must:
- Verify reservation reference.
- Verify customer identity.
- Check driver's license validity.
- Confirm payment requirements.
- Confirm security deposit requirements.
- Inspect the vehicle.
- Record starting mileage.
- Record starting fuel level.
- Record vehicle condition.
- Upload photographs.
- Record pickup date and time.
- Identify the releasing staff member.
- Obtain customer acknowledgment of the rental agreement.

8.2 Return procedure
When the customer returns the vehicle, staff must:
- Verify reservation reference.
- Verify the returning person's identity.
- Record actual return date and time.
- Record ending mileage.
- Record ending fuel level.
- Inspect vehicle condition.
- Record newly discovered damage.
- Check accessories and equipment.
- Upload return photographs.
- Calculate late charges if applicable.
- Record additional expenses.
- Identify the inspecting staff member.



8.3 Pickup and return rules
Decision	Default policy
Pickup photos	Required
Return photos	Required
Starting mileage	Mandatory
Ending mileage	Mandatory
Starting fuel level	Mandatory
Ending fuel level	Mandatory
Third-party return	Allowed with authorization
Wrong return location	Record incident and applicable relocation fee
Vehicle damage	Requires documented inspection
Vehicle availability after return	Only after inspection clearance

8.4 Vehicle inspection records
Each inspection should contain:
- Inspection ID.
- Reservation ID.
- Vehicle ID.
- Inspection type (PICKUP or RETURN).
- Mileage.
- Fuel level.
- Exterior condition.
- Interior condition.
- Existing or newly discovered damage.
- Photos.
- Staff ID.
- Inspection date.
- Remarks.
Do not overwrite pickup inspection records when creating return inspections. Both records must remain available for comparison.

9. Payment and financial management
9.1 Payment types
The system should support:
- Rental payment.
- Reservation deposit.
- Security deposit.
- Late-return fee.
- Damage charge.
- Fuel charge.
- Additional service charge.
- Refund.

9.2 Recommended payment rules
Decision	Default policy
Payment before pickup	Required
Partial payment	Allowed
Reservation deposit	Suggested 30%
Remaining balance	Due before pickup
Security deposit	Required according to vehicle category
Refund approval	Admin or explicitly authorized personnel
Late fee	Calculated by system, reviewed by authorized staff
Payment deletion	Prohibited

9.3 Payment statuses
- UNPAID
- PARTIALLY_PAID
- PAID
- REFUND_PENDING
- PARTIALLY_REFUNDED
- REFUNDED
- FAILED
- REVERSED
9.4 Payment requirements
- Generate unique payment reference numbers.
- Record payment method.
- Record payment amount.
- Record payment date.
- Record the receiving or verifying staff member.
- Keep original transactions.
- Use adjustment and reversal records instead of editing completed transactions.
- Maintain separate records for rental charges and security deposits.
- Generate receipts.
- Allow customers to view their payment history.
- Support financial reports.
9.5 Security deposit handling
Security deposits must not be treated as ordinary rental revenue.

After vehicle return:
1. Staff inspects the vehicle.
2. Staff records damages or additional charges.
3. Authorized personnel review deductions.
4. System calculates the refundable amount.
5. Refund is approved.
6. Deposit transaction is updated.
The system should preserve the original deposit, deductions, and refund history.
10. Late returns, penalties, and customer account standing
10.1 Late-return management
Recommended initial policy:
- Provide a 15-minute grace period.
- Calculate late charges after the grace period.
- Use an hourly rate or a predefined late-return rate.
- Establish a daily maximum charge.
- Require staff verification of actual return time.
- Notify customers when their scheduled return time is approaching.
- Record all late-return incidents.
The fee calculation should be configurable by Admin.
10.2 Penalty point system
Suggested starting values:
Violation	Points
Minor late return	1
Repeated late return	2
Failure to follow rental policies	2
Unauthorized additional driver	3
Significant fuel discrepancy	1
Failure to report vehicle damage	4
Unauthorized vehicle use	5
No-show	2

Points should be associated with documented incidents, not arbitrary staff judgments.
10.3 Suspension thresholds
Points	Suggested action
1–2	Warning
3–4	Temporary restriction
5–6	7-day suspension
7–9	30-day suspension
10+	Admin review

10.4 Penalty rules
- Penalty points expire after 12 months.
- Serious incidents may trigger immediate account restrictions.
- Staff can record violations and submit recommendations.
- Only Admin can approve, adjust, or waive penalties.
- Customers can appeal within seven days.
- Admin must record reasons for penalty adjustments.
- Every penalty decision must have an audit record.
10.5 Immediate suspension cases
Examples:
- Fraudulent identification documents.
- Suspected vehicle theft.
- Unauthorized vehicle transfer or subleasing.
- Serious misuse of the vehicle.
- Repeated deliberate violations.
An immediate restriction should trigger an investigation and appropriate review rather than automatically establishing guilt.
10.6 Penalty appeals
Customer appeal workflow:
1. Customer selects a penalty.
2. Customer submits an appeal reason.
3. System records the appeal.
4. Staff may provide additional documentation.
5. Admin reviews the case.
6. Admin confirms, modifies, or waives the penalty.
7. System records the final decision.
8. Customer receives a notification.
Penalty records should use an append-only event or ledger design rather than a single editable points field.

11. Vehicle maintenance and fleet management
The system must ensure that vehicles are safe and operational before being assigned to customers.
11.1 Maintenance management
Admin and authorized maintenance staff should be able to:
- Create maintenance records.
- Schedule vehicle servicing.
- Record repair activities.
- Record maintenance expenses.
- Upload repair documentation.
- Update maintenance status.
- Record service providers.
- Track inspection dates.
- View previous maintenance history.
11.2 Maintenance statuses
Status	Description
SCHEDULED	Maintenance is planned
IN_PROGRESS	Vehicle is being serviced
COMPLETED	Maintenance is finished
CANCELLED	Scheduled maintenance was cancelled

11.3 Maintenance rules
- Vehicles undergoing maintenance cannot be reserved.
- Staff can report maintenance issues.
- Only authorized personnel can clear maintenance restrictions.
- Admin can review maintenance expenses.
- Every maintenance activity must be associated with a vehicle.
- Vehicles must pass inspection before becoming available again.
12. Pickup and return location management
If the business operates multiple branches or pickup locations, location management should be a separate module.
Location information
- Location ID.
- Location name.
- Address.
- Contact number.
- Operating hours.
- Location description.
- Location status.
- Assigned personnel.
Location rules
- Customers can select active pickup locations.
- Customers can select eligible return locations.
- Admin can add, edit, and deactivate locations.
- Staff can report temporary location closures.
- Reservations must reference valid locations.
- Return-location changes require authorization.
- Additional relocation charges must be disclosed.
- A location cannot be permanently deleted if historical rentals reference it.
For the initial version, I recommend using company-owned locations only. External pickup partners introduce additional operational and permission requirements.
13. Customer and rental agreement management
A complete rental system should also document the agreement between the business and customer.
Rental agreement information
- Agreement ID.
- Reservation ID.
- Customer ID.
- Vehicle ID.
- Rental duration.
- Rental rate.
- Security deposit.
- Additional charges.
- Terms and conditions version.
- Agreement creation date.
- Customer acknowledgment.
- Staff verification.
- Agreement status.
Agreement rules
- Agreement must be available before pickup.
- Customer must acknowledge the rental conditions.
- Staff must verify the agreement.
- The agreement must retain the terms accepted at the time of booking.
- Changes to future policies must not silently alter existing agreements.
- Completed agreements must be retained as historical records.
For an initial version, a digital acknowledgment is sufficient. Electronic signatures can be added later if the business requires them.
14. Notifications and communication
The system should keep customers and staff informed of important events.
Customer notifications
- Account registration confirmation.
- Email verification.
- Reservation submission.
- Reservation approval.
- Reservation rejection.
- Cancellation request result.
- Payment confirmation.
- Upcoming pickup reminder.
- Upcoming return reminder.
- Late-return notification.
- No-show notification.
- Refund confirmation.
- Penalty notification.
- Suspension notification.
- Appeal result.
- Rental completion.
Staff notifications
- New reservation request.
- Cancellation request.
- Upcoming vehicle pickup.
- Upcoming vehicle return.
- Overdue rental.
- Reported vehicle damage.
- Maintenance request.
- Payment requiring verification.
- Customer appeal.
Admin notifications
- Staff registration request.
- Refund request.
- Serious customer violation.
- Dispute requiring review.
- Vehicle maintenance alert.
- Overdue rental.
- Important system activity.
Notifications can initially be implemented through an in-system notification center. Email and SMS integration can follow.

Each notification should contain:
- Notification ID.
- Recipient ID.
- Notification type.
- Message.
- Related reservation or record.
- Read/unread status.
- Created timestamp.
15. Dashboard and reporting
15.1 Administrator dashboard







carvoapp.online


Display:
- Total vehicles.
- Available vehicles.
- Rented vehicles.
- Vehicles under maintenance.
- Total customers.
- Active staff.
- Pending reservations.
- Confirmed reservations.
- Ongoing rentals.
- Overdue rentals.
- Monthly revenue.
- Outstanding payments.
- Pending refunds.
- Recent activities.
15.2 Staff dashboard
Display only information relevant to their permissions:
- Pending reservations.
- Today's pickups.
- Today's returns.
- Ongoing rentals.
- Overdue rentals.
- Payments requiring verification.
- Vehicle inspection tasks.
- Maintenance reports.
- Recent operational activities.
15.3 Customer dashboard
Display:
- Account verification status.
- Current reservation.
- Upcoming pickup.
- Expected return.
- Payment balance.
- Reservation history.
- Payment history.
- Penalty points.
- Notifications.
15.4 Reports
The system should generate:

Reservation reports
- Daily reservations.
- Monthly reservations.
- Annual reservations.
- Approved reservations.
- Rejected reservations.
- Cancelled reservations.
- No-show reports.
Financial reports
- Daily revenue.
- Monthly revenue.
- Annual revenue.
- Outstanding balances.
- Deposits received.
- Refunds issued.
- Late fees collected.
- Additional charges.
Fleet reports
- Vehicle utilization.
- Most frequently rented vehicles.
- Vehicle availability.
- Maintenance history.
- Maintenance expenses.
- Vehicle rental history.
Customer reports
- Customer rental history.
- Frequent customers.
- Customer violations.
- Suspended accounts.
Staff reports
- Reservations processed.
- Payments recorded.
- Vehicle pickups and returns.
- Operational activity.
Reports should support date filters, search, and PDF or Excel exports.
16. Audit logs and activity tracking
This is particularly important because several staff members may operate the same system.

The system must record important actions.
Actions requiring audit records
- Login attempts and account security events.
- Account creation and role changes.
- Staff permission changes.
- Vehicle creation and status changes.
- Reservation approval and rejection.
- Reservation cancellation.
- Pickup confirmation.
- Return confirmation.
- Payment creation.
- Payment adjustments.
- Refund approvals.
- Penalty creation and adjustments.
- Account suspension and restoration.
- Maintenance updates.
- Business policy changes.
- Administrative overrides.
Audit record structure
Field	Description
audit_id	Unique audit record
user_id	User who performed the action
action	Action performed
entity_type	Type of affected record
entity_id	Affected record ID
old_value	Previous value, when applicable
new_value	New value, when applicable
reason	Explanation for sensitive actions
ip_address	Originating IP, when appropriate
created_at	Date and time

Audit records must not be editable or deletable through ordinary application functions.
17. Database design and required entities
The database should be normalized and designed around the business processes rather than individual screens.

Here is the proposed database structure.
Module	Suggested tables
User management	users, roles, permissions, role_permissions, user_permissions
Customer information	customer_profiles, customer_documents, customer_verifications
Staff management	staff_profiles, staff_approval_requests
Vehicle management	vehicles, vehicle_categories, vehicle_images
Reservation	reservations, reservation_status_history, reservation_cancellation_requests
Rental operations	pickup_records, return_records, vehicle_inspections, inspection_photos
Locations	locations
Payments	payments, payment_adjustments, payment_refunds, security_deposits, additional_charges
Maintenance	maintenance_records, maintenance_expenses
Penalties	penalty_incidents, penalty_points, penalty_appeals, account_suspensions
Agreements	rental_agreements
Notifications	notifications
Audit	audit_logs
System configuration	system_settings, business_policies

These are logical entities, not necessarily a requirement to create every table immediately. Some may be combined depending on your finalized schema.
Important database rules
- Use primary keys and foreign keys.
- Use unique constraints for email addresses and vehicle plate numbers.
- Use appropriate CHECK constraints.
- Use indexes for frequently searched columns.
- Use transactions for reservations, payments, and refunds.
- Preserve historical records.
- Use timestamps for important records.
- Use consistent naming conventions.
- Avoid storing calculated values unnecessarily.
- Separate rental charges from actual payment transactions.
- Use decimal types for financial amounts.
- Store document and image metadata separately from the actual files.
- Avoid storing raw payment credentials.
Important design distinction
Do not confuse these three things:
Record	Purpose
Reservation	The customer's request to rent a vehicle
Rental	The actual period during which the vehicle is in the customer's possession
Payment	A financial transaction associated with the reservation or rental

They are related, but they represent different business events.
18. Security requirements
Security must be implemented before production deployment.
Authentication
- Password hashing using Argon2id or bcrypt.
- Secure login and logout.
- Email verification.
- Password reset.
- Session expiration.
- Login attempt rate limiting.
- Secure session cookies.
Authorization
- Role-based access control.
- Individual staff permissions.
- Server-side permission validation.
- Protection against unauthorized record access.
- Customers can only access their own records.
- Staff can only perform authorized operations.
- Admin-only operations must be protected.
Application security
- Prepared statements or PDO parameterized queries.
- Input validation and sanitization.
- XSS protection.
- CSRF protection.
- Secure file uploads.
- File type and size restrictions.
- HTTPS.
- Secure error handling.
- No exposed database credentials.
- No publicly accessible identification documents.
Data protection
- Database backups.
- Recovery procedures.
- Restricted access to personal information.
- Secure document storage.
- Audit history.
- Appropriate document retention policies.
19. User interface requirements
The interface should have separate dashboards and navigation based on user permissions.
Customer interface




















13










Required pages:
1. Home page.
2. Vehicle catalog.
3. Vehicle details.
4. Registration.
5. Login.
6. Customer dashboard.
7. Profile management.
8. Reservation form.
9. Reservation details.
10. Reservation history.
11. Payment history.
12. Notifications.
13. Penalty and appeal page.
14. Support/contact page.
Staff interface




















16










Required pages:
1. Staff dashboard.
2. Reservation management.
3. Reservation details.
4. Customer verification.
5. Pickup management.
6. Return management.
7. Vehicle inspection.
8. Payment management.
9. Penalty incident management.
10. Maintenance reporting.
11. Notifications.
12. Staff profile.
Only authorized modules should appear in the navigation.
Administrator interface




















10










Required pages:
1. Administrator dashboard.
2. User management.
3. Staff management.
4. Role and permission management.
5. Vehicle management.
6. Vehicle category management.
7. Reservation management.
8. Customer management.
9. Payment management.
10. Refund management.
11. Maintenance management.
12. Location management.
13. Penalty management.
14. Appeal management.
15. Reports and analytics.
16. Audit logs.
17. Business policies.
18. System settings.

20. Non-functional requirements
These requirements describe how well the system should operate, not just what it can do.
Category	Requirement
Performance	Pages and common queries should load efficiently.
Reliability	Critical transactions must not produce inconsistent records.
Security	Unauthorized access must be prevented.
Usability	Interfaces must be understandable and consistent.
Compatibility	Support modern Chrome, Firefox, Edge, and other major browsers.
Responsiveness	Support desktop, tablet, and mobile layouts.
Maintainability	Organized code, reusable functions, and clear documentation.
Scalability	Allow additional vehicles, users, and locations.
Availability	Production hosting should have appropriate uptime and recovery plans.
Data integrity	Preserve relationships and historical records.

21. Testing requirements before deployment
Every major module must have test cases.
Module	Important test cases
Registration	Duplicate email, invalid email, weak password
Authentication	Incorrect password, suspended account, session expiration
Authorization	Customer accessing admin pages, unauthorized staff actions
Vehicles	Invalid vehicle data, duplicate plate number, archived vehicle
Reservations	Overlapping bookings, invalid dates, unavailable vehicle
Cancellation	Cancellation deadline, fee calculation, unauthorized cancellation
Pickup	Invalid license, unpaid balance, missing inspection
Return	Incorrect mileage, late return, reported damage
Payments	Duplicate payment, partial payment, failed transaction
Refunds	Unauthorized refund, excessive refund, duplicate refund
Penalties	Duplicate violation, expired points, appeal processing
Maintenance	Reserving a vehicle under maintenance
Reports	Incorrect totals, date filtering, missing records
Audit	Missing activity records, unauthorized audit modification

Critical scenarios to test
These should be treated as deployment blockers:
1. Two customers attempt to reserve the same vehicle simultaneously.
2. A suspended customer tries to make a reservation.
3. A customer attempts to access another customer's information.
4. Staff attempts to approve their own reservation.
5. Staff without payment permissions attempts to record a payment.
6. A vehicle is placed under maintenance while it has future reservations.
7. A customer requests cancellation after pickup.
8. A payment succeeds but the reservation update fails.
9. A customer returns a damaged vehicle.
10. A staff member attempts to delete a historical transaction.
11. A refund exceeds the amount originally paid.
12. An administrator overrides a staff decision without providing a reason.
22. Deployment requirements
Before making the application publicly accessible:
- [ ] Production database is configured.
- [ ] Production environment variables are configured.
- [ ] Database credentials are secured.
- [ ] Database schema and migrations are verified.
- [ ] Initial administrator account is created securely.
- [ ] Default credentials are removed.
- [ ] Debugging is disabled.
- [ ] HTTPS is enabled.
- [ ] Application permissions are configured.
- [ ] File upload directories are protected.
- [ ] Database backups are scheduled.
- [ ] Database restoration is tested.
- [ ] Error logging is enabled.
- [ ] Audit logging is enabled.
- [ ] All role permissions are tested.
- [ ] Reservation conflicts are tested.
- [ ] Payment and refund calculations are tested.
- [ ] Mobile responsiveness is tested.
- [ ] Production environment is tested independently of the development environment.

23. Recommended implementation phases
I would not build all these modules simultaneously. The system has enough moving parts already.

Here's the implementation order I recommend.
Phase 1
Foundation and account management

Establish the database and access control.
- Database schema and relationships
- User registration and authentication
- Admin, Staff, Customer roles
- Staff registration approval
- Staff permissions
- Customer profiles and verification


Phase 2
Vehicle and reservation management

Build the main booking functionality.
- Vehicle CRUD
- Vehicle categories
- Vehicle images
- Search and filtering
- Date-based availability
- Reservation requests
- Approval and rejection
- Reservation status history


Phase 3
Rental operations

Connect reservations to physical vehicle operations.
- Pickup management
- Return management
- Vehicle inspections
- Inspection photos
- Mileage and fuel tracking
- Rental agreements
- Multiple locations


Phase 4
Financial management

Implement financial transactions and settlement.
- Rental pricing
- Reservation deposits
- Security deposits
- Payment recording
- Payment verification
- Refund management
- Late fees
- Receipts


Phase 5
Maintenance and customer accountability

Implement fleet maintenance and violation handling.
- Maintenance scheduling
- Repair records
- Damage reports
- Penalty points
- Account suspension
- Appeals and disputes


Phase 6
Dashboards and communication

Complete the user-facing management experience.
- Customer dashboard
- Staff dashboard
- Admin dashboard
- Notifications
- Reports and analytics
- PDF/Excel exports


Phase 7
Testing and deployment

Prepare the system for real users.
- Functional testing
- Security testing
- Database integrity testing
- Performance testing
- Backup and recovery
- Production deployment



24. Final business decisions that your group must approve
The specification above provides recommended defaults, but your group should formally approve the following policies before finalizing the database.

I've consolidated the remaining decisions into one checklist.
Business policy approval
Approved by your development group
0/49



Check a policy only after your group has discussed and agreed to it. These are proposed defaults, not automatically approved decisions.

Account and verification
[ ] Automatic customer approval after email verification
[ ] Email verification is mandatory
[ ] Driver's license required before pickup
[ ] One account per email
[ ] Suspended customers can log in with restricted access
[ ] Staff registration requires Admin approval

Booking and availability
[ ] Same-day reservations allowed
[ ] Two-hour minimum booking notice
[ ] One active rental per customer initially
[ ] One vehicle per customer for overlapping dates
[ ] Staff cannot approve their own rental
[ ] Pending reservations have a 30-minute availability hold
[ ] Customers can request reservation modifications

Cancellation and no-show
[ ] Free cancellation at least 24 hours before pickup
[ ] 10% cancellation fee 12–23 hours before pickup
[ ] 25% cancellation fee less than 12 hours before pickup
[ ] 30-minute no-show grace period
[ ] No-show deposit forfeiture subject to disclosed terms
[ ] Cancellation after pickup handled as early return
[ ] Emergency cancellation exceptions require Admin approval

Pickup and return
[ ] Pickup and return photos required
[ ] Mileage mandatory
[ ] Fuel level mandatory
[ ] Third-party vehicle return allowed with authorization
[ ] Vehicle damage requires documented inspection
[ ] Return location changes require authorization
[ ] Vehicle requires inspection clearance before becoming available

Payments and penalties
[ ] Payment completed before pickup
[ ] 30% suggested reservation deposit
[ ] Security deposit based on vehicle category
[ ] Refunds require authorization
[ ] 15-minute late-return grace period
[ ] Late fees configurable by Admin
[ ] Penalty points expire after 12 months
[ ] Customers can appeal penalties within seven days
[ ] Admin has authority to waive penalties

Roles and permissions
[ ] Admin has full administrative authority
[ ] Staff permissions are configurable
[ ] Reservation approval is permission-controlled
[ ] Pickup and return can be restricted to operations staff
[ ] Payment recording can be restricted to payment staff
[ ] Staff cannot delete financial or rental history
[ ] Only Admin can change system policies

Business operations
[ ] Only company-owned pickup locations in version one
[ ] Rental agreements required before pickup
[ ] Notifications available in the system
[ ] Maintenance blocks vehicle availability
[ ] Historical records are archived, not deleted
[ ] Audit logs record sensitive operations
Reset Copy decisions

25. Final system architecture recommendation
For your existing PHP, MySQL, HTML, CSS, and JavaScript project, I recommend keeping the architecture straightforward.
Frontend

HTML · CSS · JavaScript · Responsive UI


Backend

PHP · Authentication · Business Logic · Authorization · Validation


Database

MySQL · Relational Tables · Constraints · Transactions · Indexes


File and System Services

Vehicle Images · Documents · Inspection Photos · Backups · Logs


Suggested backend organization:
vrms/
├── config/
│   ├── database.php
│   └── app.php
│
├── includes/
│   ├── auth.php
│   ├── permissions.php
│   ├── validation.php
│   └── functions.php
│
├── modules/
│   ├── users/
│   ├── staff/
│   ├── vehicles/
│   ├── reservations/
│   ├── rentals/
│   ├── inspections/
│   ├── payments/
│   ├── maintenance/
│   ├── penalties/
│   ├── locations/
│   ├── notifications/
│   └── reports/
│
├── admin/
├── staff/
├── customer/
│
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
│
├── uploads/
│
├── database/
│   ├── schema.sql
│   └── migrations/
│
└── index.php


Keep business logic out of presentation files as much as possible. Each module should have clear responsibilities, and database operations should use a shared connection and consistent validation and authorization mechanisms.

Final recommendation
I would treat this specification as your group's primary development reference.

There are three things I particularly want you to get right:
1. Reservation integrity: Never allow overlapping vehicle bookings, inconsistent reservation statuses, or invalid availability.
2. Role-based security: Admin, Staff, and Customer must have clearly separated permissions, with additional staff-level restrictions.
3. Operational records: Pickup, return, inspection, payment, penalties, and administrative decisions must have traceable histories.
A system with these foundations will be substantially more complete than a typical CRUD-based academic rental project.