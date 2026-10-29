#!/usr/bin/env bash
set -eu

XAMPP_ROOT="${XAMPP_ROOT:-/opt/lampp}"
PROJECT_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PUBLIC_ROOT="$PROJECT_ROOT/public"
HTTPD_CONF="$XAMPP_ROOT/etc/httpd.conf"
VHOSTS_CONF="$XAMPP_ROOT/etc/extra/httpd-vhosts.conf"
HOSTS_FILE="/etc/hosts"

if [ "$(id -u)" -eq 0 ]; then
    SUDO=''
else
    SUDO='sudo'
fi

if [ ! -x "$XAMPP_ROOT/bin/httpd" ]; then
    printf 'XAMPP Apache was not found at %s.\n' "$XAMPP_ROOT" >&2
    exit 1
fi

if ! grep -Fq 'Include etc/extra/httpd-vhosts.conf' "$HTTPD_CONF"; then
    "$SUDO" sed -i 's/^#Include etc\/extra\/httpd-vhosts\.conf$/Include etc\/extra\/httpd-vhosts.conf/' "$HTTPD_CONF"
fi

if ! grep -Fq 'ServerName vrms.local' "$VHOSTS_CONF"; then
    "$SUDO" tee -a "$VHOSTS_CONF" >/dev/null <<EOF

<VirtualHost *:80>
    ServerName vrms.local
    DocumentRoot "$PUBLIC_ROOT"

    <Directory "$PUBLIC_ROOT">
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog "$XAMPP_ROOT/logs/vrms-error.log"
    CustomLog "$XAMPP_ROOT/logs/vrms-access.log" common
</VirtualHost>
EOF
fi

if ! grep -Eq '^[[:space:]]*127\.0\.0\.1[[:space:]]+vrms\.local([[:space:]]|$)' "$HOSTS_FILE"; then
    printf '127.0.0.1 vrms.local\n' | "$SUDO" tee -a "$HOSTS_FILE" >/dev/null
fi

# Apache runs as daemon in XAMPP. Grant it traversal only; project files remain
# owned by the developer and are not made broadly writable.
if command -v setfacl >/dev/null 2>&1; then
    "$SUDO" setfacl -m u:daemon:--x /home/nori
else
    "$SUDO" chmod o+x /home/nori
fi

"$SUDO" "$XAMPP_ROOT/bin/httpd" -t -f "$HTTPD_CONF"
"$SUDO" "$XAMPP_ROOT/lampp" restart

printf '\nVELO is available at: http://vrms.local/\n'
