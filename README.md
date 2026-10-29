# VELO Vehicle Rental Management System

## Project structure

The application uses `public/` as its web root:

```text
vrms/
├── public/
│   ├── admin/       Administrator pages and vehicle seeding utility
│   ├── customer/    Customer dashboard and rental pages
│   ├── staff/       Faculty/staff workspace
│   ├── workspace/   Workspace pages shared by administrators and staff
│   ├── assets/      CSS, JavaScript, and vehicle images
│   └── *.php        Public landing, authentication, and catalog pages
├── includes/        Shared PHP layout and navigation components
├── database/        Schema and command-line account utility
└── config.php       Database connection and shared application helpers
```

## Local web-server setup

Configure Apache or Nginx so the document root points to the `public/` directory:

```text
/home/nori/Downloads/vrms/public
```

For PHP's built-in development server, run this from the project root:

```bash
php -S localhost:8000 -t public
```

After that, the application URLs are:

- `/` or `/index.php`
- `/login.php`
- `/vehicles.php`
- `/customer/dashboard.php`
- `/staff/staff-dashboard.php`
- `/admin/admin-dashboard.php`

The included `public/.htaccess` enables `index.php` as the directory index and disables directory listings for Apache.

For Apache, make sure `AllowOverride All` (or at least `AllowOverride FileInfo Options`) is enabled for `public/`. For Nginx, use `try_files $uri $uri/ /index.php?$query_string;` and set `root` to the same `public/` directory.

## Database and CLI utilities

Import `database/schema.sql`, configure the `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` environment variables, then run:

```bash
php database/create-account.php --name="Full Name" --email="user@example.com" --password="at-least-8-chars" --role=admin
php public/admin/seed_vehicles.php
```
# Vehicle-Rental-Management-System
