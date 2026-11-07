DORMITORY MANAGEMENT SYSTEM - UPDATED BUILD

INSTALL
1. Extract this folder to C:\xampp\htdocs\Dormitory
2. Start Apache and MySQL in XAMPP.
3. Open config/config.php and confirm DB_HOST, DB_NAME, DB_USER and DB_PASS.
   Default values are 127.0.0.1, dormitory_db, root, blank password.
4. IMPORTANT: If dormitory_db already exists, DO NOT import database/dormitory.sql.
   That original starter script drops and recreates users, rooms and tenants.
5. For an existing database, open database/upgrade_v2.sql in Workbench and execute it ONCE.
   It adds permission, reservation, billing, payment and maintenance tables and a tenant-user link.
6. Open http://localhost/Dormitory/

DEFAULT STARTER ADMIN
Username: Admin1
Password: 12345
Change this password after signing in.

FEATURES IN THIS BUILD
- Dashboard summary cards and live Chart.js room status / collections charts.
- User creation, edit, delete, roles, and per-user module/action permissions.
- Rooms and tenants starter CRUD.
- Tenant self-registration and login.
- Reservation requests and Admin/Manager approval (requires permissions).
- Monthly billing records and due-date status.
- Payment recording and payment history.
- Maintenance request list and status workflow.
- Basic operational reports and CSV payment export.
- Shared sidebar, header, footer, responsive styles, SweetAlert2 library.

PERMISSIONS
Admin accounts have full access. For Manager/Tenant accounts, open Users & Access,
choose Edit / Access, and check View/Add/Edit/Delete for each module.
Unchecked modules are hidden from the sidebar and protected by page permission checks.
A newly registered tenant is given Dashboard view and Reservation view/add.

IMPORTANT
- The upgrade SQL is intended for the supplied starter schema. Back up your database first.
- The project is a development build. Test workflows, database permissions, privacy,
  backups, and security before using real tenant data or publishing online.
- Configure the database credentials to match your own MySQL server.
- Some legacy room/tenant pages retain their existing form implementation; verify them
  in your local XAMPP installation before production use.


APPLICANT PORTAL UPDATE (v2.1)
1. Back up dormitory_db first.
2. Open database/applicant_portal_upgrade.sql in MySQL Workbench and execute it.
   This adds Applicant to the user role ENUM and creates applicant_profiles and
   applicant_bookings. It does not drop existing tables or records.
3. Applicant registration is available from the login page. Public registration
   creates Applicant accounts only; the role is assigned server-side.
4. Applicant login redirects to /applicant/index.php where available rooms and
   booking requests are shown.
5. Admin-created accounts can use Admin, Manager, Tenant, Cashier or Applicant.
6. SweetAlert2 is loaded from jsDelivr CDN; internet access is needed for the dialogs.
7. Back up files and database before replacing the live project. Test locally first.

NOTE: Applicant booking requests are stored separately from approved tenant
reservations. An Admin/Manager approval-to-tenant conversion workflow is not
included in this update yet. Existing internal CRUD modules and old page-specific
alerts may still need individual integration with SweetAlert2.

ADDITIONAL MODULE DATABASE UPGRADE
After the existing upgrades and archive_upgrade.sql, back up dormitory_db and run:
  database/complete_system_upgrade.sql
See COMPLETE_SYSTEM_ROADMAP.txt for the new tables and important implementation notes.

IMPORTANT INTEGRATION NOTE
Read INTEGRATION_AUDIT.md before installation. The migration file in database/migrations/ is read-only preflight SQL. Do not import the original table dump files into an existing populated database because they include DROP TABLE and INSERT statements.


ADDITIONAL MODULES
- Contracts, Documents, Expenses, Payment Methods, Room Inspections, Complaints, Visitors, Announcements, Notifications, Activity Logs, and System Settings are available from the sidebar according to role permissions.
- Documents upload accepts PDF, JPG, JPEG, PNG, WEBP, DOC, and DOCX up to 8 MB. Download is routed through a permission-checked PHP endpoint.
- Run the read-only database compatibility check with:
  C:\xampp\php\php.exe C:\xampp\htdocs\Dormitory\database\migrations\check_schema_compatibility.php
- Read INTEGRATION_AUDIT.md for exact changes and test limitations. No original database dump should be re-imported into an already-populated database.
