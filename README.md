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

## Apache/XAMPP setup

Apache must use `public/` as the document root. Do not use the project root as the web root because it contains `config.php`, database utilities, and shared PHP files.

```text
/home/nori/Downloads/vrms/public
```

### XAMPP on Linux

This repository includes a ready-to-copy virtual-host template at [xampp/vrms-vhost.conf](xampp/vrms-vhost.conf). XAMPP should serve the `public/` directory, not the repository root.

From the project root, the included setup script can configure the virtual host, enable Apache's virtual-host include, add `vrms.local` to `/etc/hosts`, validate Apache, and restart XAMPP:

```bash
bash xampp/setup-apache.sh
```

The script may ask for your Linux password through `sudo`.

Create an Apache virtual host in `/opt/lampp/etc/extra/httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName vrms.local
    DocumentRoot "/home/nori/Downloads/vrms/public"

    <Directory "/home/nori/Downloads/vrms/public">
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog "/opt/lampp/logs/vrms-error.log"
    CustomLog "/opt/lampp/logs/vrms-access.log" common
</VirtualHost>
```

You can append the included template with:

```bash
sudo tee -a /opt/lampp/etc/extra/httpd-vhosts.conf < xampp/vrms-vhost.conf
```

Add this entry to `/etc/hosts`:

```text
127.0.0.1 vrms.local
```

Enable the virtual-host file in `/opt/lampp/etc/httpd.conf` if it is commented:

```apache
Include etc/extra/httpd-vhosts.conf
```

Then restart XAMPP:

```bash
sudo /opt/lampp/lampp restart
```

In XAMPP, enable Apache and MySQL, then open:

```text
http://vrms.local/
```

If Apache is configured as the default local host instead, use the same `DocumentRoot` and `<Directory>` settings for that host.

### XAMPP on Windows

Place the project outside or inside `htdocs`, then point an Apache virtual host to the `public` directory. In `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName vrms.local
    DocumentRoot "C:/xampp/htdocs/vrms/public"

    <Directory "C:/xampp/htdocs/vrms/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The Linux template is not used on Windows because its paths are different. Copy the Windows block above into XAMPP's `httpd-vhosts.conf`.

Add this to `C:\Windows\System32\drivers\etc\hosts`:

```text
127.0.0.1 vrms.local
```

Restart Apache from the XAMPP Control Panel and open `http://vrms.local/`.

Make sure Apache has `mod_rewrite` enabled and that `AllowOverride All` is permitted for the `public/` directory. The included [public/.htaccess](public/.htaccess) sets `index.php` as the directory index, disables directory listings, and enables the application fallback route.

### MySQL database

Start MySQL in XAMPP, open phpMyAdmin, and import [database/schema.sql](database/schema.sql). Configure the connection before opening the application:

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=vrms
DB_USER=vrms
DB_PASS=vrms123
```

These values can be supplied as Apache/PHP environment variables, or you can update the local values in [config.php](config.php). For a real deployment, use a dedicated database user and a password that is not committed to Git.

## PHP built-in development server

Apache/XAMPP is the recommended supported setup. For quick local testing without XAMPP, run this from the project root:

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

For Nginx, use `try_files $uri $uri/ /index.php?$query_string;` and set `root` to the same `public/` directory.

## Database and CLI utilities

Import `database/schema.sql`, configure the `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` environment variables, then run:

```bash
php database/create-account.php --name="Full Name" --email="user@example.com" --password="at-least-8-chars" --role=admin
php public/admin/seed_vehicles.php
```

The vehicle seeder retrieves image URLs from Wikimedia Commons. Run it with the same PHP binary and database environment used by XAMPP:

```bash
sudo /opt/lampp/bin/php public/admin/seed_vehicles.php
```

The command reports vehicles for which no image was found and uses the bundled placeholder for those records. If every vehicle uses the placeholder, check that XAMPP PHP has outbound HTTPS access and that the machine is connected to the internet.
# Vehicle-Rental-Management-System
