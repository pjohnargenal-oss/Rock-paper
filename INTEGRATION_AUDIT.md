# Dormitory Management System — Integration Audit

## What was changed in this package

- Removed duplicate CSRF helper declarations: `auth.php` is now the single owner of `csrf_token()` and `verify_csrf()`; `functions.php` keeps flash and activity-log helpers.
- Hardened PHP session cookies (HttpOnly, SameSite, strict mode, HTTPS Secure flag), idle timeout, and periodic session ID rotation.
- Added CSRF validation to the login form and made sign-out a CSRF-protected POST action.
- Added a temporary per-session login throttle (five failed attempts trigger a 15-minute wait). This is a browser-session control, not a global/IP/database lockout.
- Improved PDO error handling and made database credentials overridable with `DORMITORY_DB_HOST`, `DORMITORY_DB_PORT`, `DORMITORY_DB_NAME`, `DORMITORY_DB_USER`, and `DORMITORY_DB_PASS` environment variables.
- Disabled the predictable default admin bootstrap over HTTP. `create_admin.php` now runs only from PHP CLI and requires `DORMITORY_ADMIN_PASSWORD` (minimum 12 characters).
- Added responsive and keyboard-focus UI polish.
- Added `database/migrations/SAFE_SCHEMA_PREFLIGHT.sql`, a read-only inventory / relationship / orphan-check script. It intentionally makes no schema or data changes.

## Important schema facts found in the supplied SQL dump

- The requested application schema has 23 core tables. The ZIP also contains a separate `staff_profiles` SQL dump, making 24 SQL table scripts in total.
- `billing_tbl` primary key is `billing_id`; do not rename it to `bill_id` to satisfy older code.
- The supplied `tenants` table has no `user_id` column. Do not add a tenant/user foreign key until duplicate/orphan mapping is reviewed and approved.
- `payments_tbl.payment_method` is an ENUM in the supplied schema, while `expenses.payment_method_id` references the `payment_methods` table. These are two distinct data models and should not be silently conflated.
- `rooms.location_id` references `dormitory_locations.location_id`.

## Not yet verified / not claimed complete

The execution environment used to prepare this package has PDO but no `pdo_mysql` driver or MySQL server connection. Therefore it was not possible to run the application against your actual `dormitory_db`, inspect its live data, execute migration SQL, or test login and CRUD transactions end-to-end.

The supplied ZIP does not contain PHP module pages for all requested areas. Current PHP pages exist for applicant, archive, billing, dashboard, maintenance, payments, reports, reservations, rooms, tenants, and users. Separate complete CRUD modules are not present for dormitory locations, contracts, documents, expenses, payment methods, room inspections, complaints, visitors, announcements, notifications, activity logs, and system settings. Their SQL table definitions alone do not make those workflows implemented.

Consequently, this is an integration-hardening patch and audit package, **not a completed full-system integration**. Do not deploy it as a fully tested or fully secure release.

## Safe installation

1. Back up the current `dormitory_db` and the entire `C:\xampp\htdocs\Dormitory` folder.
2. Extract this ZIP and merge/replace the `Dormitory` folder only after keeping that backup.
3. Check `config/config.php`. The default local values were retained for compatibility; preferably set the `DORMITORY_DB_*` environment variables and remove any production secrets from source control.
4. In MySQL Workbench, select `dormitory_db` and run `database/migrations/SAFE_SCHEMA_PREFLIGHT.sql`. This script is read-only. Review missing tables and orphan counts before considering any DDL migration.
5. Do **not** import the original `database/dormitory_db_*.sql` dump files into a populated database: they contain `DROP TABLE IF EXISTS` and `INSERT` statements and can destroy or duplicate existing data.
6. Test login, each role, create/edit/archive/restore, billing/payment calculations, uploads, reports, and navigation in a local copy before production.
7. Delete `create_admin.php` after any approved CLI bootstrap use.

## Checks performed for this package

- PHP CLI syntax lint was run on all 30 PHP files after the changes.
- CSRF helper load path was reviewed to remove the duplicate function declarations.
- Live MySQL checks, browser interaction tests, and all workflow tests were not possible in this environment.


## Additional modules implemented in this integration pass

The following PHP module routes were added using the supplied table definitions and a shared allow-listed CRUD renderer (`includes/module_engine.php`):

- `contracts/index.php`: create, list, search, edit contracts with tenant/room lookups and creator attribution.
- `documents/index.php` and `documents/download.php`: validated document upload (PDF/JPG/PNG/WEBP/DOC/DOCX, 8 MB limit), tenant/applicant linking, protected download, archive and restore. Uploaded files are stored under `uploads/documents/`, which denies direct web access.
- `expenses/index.php`: record expenses and void recorded entries without deleting the audit trail.
- `payment_methods/index.php`: add/edit payment methods and activate/deactivate them.
- `room_inspections/index.php`: record and edit room inspections with condition, findings, actions, and next inspection date.
- `complaints/index.php`: create and manage complaint status, priority, assignment, and resolution; `resolved_at` is set/cleared when status changes.
- `visitors/index.php`: visitor log with check-in and check-out action.
- `announcements/index.php`: draft/publish/archive announcements; non-admin views are restricted to published, currently valid announcements for their audience.
- `notifications/index.php`: administrator can send notifications; each non-admin user sees only their own inbox and can mark their own notifications read.
- `activity_logs/index.php`: read-only activity log viewer (Admin only).
- `system_settings/index.php`: Admin-only setting editor with key-based upsert and updater attribution.

The user permission editor (`users/edit.php`) and new-user defaults (`users/add.php`) now include the added permission-controlled modules. Published announcements and the signed-in user's notification inbox are readable for authenticated accounts. Activity logs and system settings are explicitly Admin-only.

### Read-only schema compatibility test

A CLI-only checker was added at `database/migrations/check_schema_compatibility.php`. It checks the 23 supplied core tables, their expected columns and primary keys, the 35 foreign-key definitions, and 54 secondary/unique indexes represented by the supplied SQL files. It performs no CREATE, ALTER, INSERT, UPDATE, DELETE, or DROP operations. Run it on the user's XAMPP machine after confirming `config/config.php` points to the existing database:

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\Dormitory\database\migrations\check_schema_compatibility.php
```

If the project folder has a different name, adjust only the path. A successful run prints `PASS`. Back up the database first as a general precaution; this checker itself is read-only.

### Testing status and limitations

- PHP syntax checks are run against every PHP file in the package.
- Schema field mappings were cross-checked against the supplied SQL table definitions.
- ZIP integrity is checked before delivery.
- This build environment does not provide a MySQL PDO driver or a reachable MySQL server, so a live connection, the compatibility checker against the user's actual database, file upload/download, role permission behavior, and CRUD transactions cannot be executed here. The user must run the included CLI schema checker and then test each module in their local XAMPP environment. Do not treat a PHP lint pass as proof of live database compatibility.

Static field mapping report: `database/migrations/MODULE_SCHEMA_MAPPING_CHECK.txt`. All eleven added module mappings were checked against their bundled SQL `CREATE TABLE` definitions. This does not replace the live read-only check against the actual MySQL database.

## UI interaction update (v2.2)
- Shared module renderer now uses SweetAlert2 confirmations before save/update, status actions, archive/restore, visitor check-out, expense void, and deletion.
- Shared module renderer displays SweetAlert2 success/error messages after server responses.
- Edit, delete, document download, archive/restore, notification read, expense void, visitor check-out, and payment-method status controls use icon-only buttons with accessible labels/tooltips.
- Status fields display state icons and readable labels.
- Delete requires module delete permission and CSRF validation. It is intentionally disabled for activity logs to preserve the audit trail. Foreign-key constraints remain authoritative; deleting a referenced record can be rejected by MySQL.
- Legacy reservation review, maintenance status update, and tenant deactivation keep their existing SweetAlert handlers and now use icon-only controls to prevent duplicate confirmation dialogs.
- No database schema changes were made for this UI pass.
