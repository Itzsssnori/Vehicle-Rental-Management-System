#!/usr/bin/env bash
# ============================================================================
# VELO VRMS — Ubuntu XAMPP Autostart & Apache Permission Hardening Script
# Run once with: sudo bash xampp/setup-autostart.sh
# ============================================================================
set -euo pipefail

PROJECT_ROOT="/home/nori/Downloads/vrms"
PUBLIC_DIR="${PROJECT_ROOT}/public"
UNIT_SRC="${PROJECT_ROOT}/xampp/xampp.service"
UNIT_DST="/etc/systemd/system/xampp.service"
VHOSTS_FILE="/opt/lampp/etc/extra/httpd-vhosts.conf"
HTTPD_CONF="/opt/lampp/etc/httpd.conf"

if [[ $EUID -ne 0 ]]; then
  echo "Please run as root: sudo bash xampp/setup-autostart.sh"
  exit 1
fi

echo "[1/5] Fixing directory traversal permissions (prevents Apache 403 Forbidden)..."
# Apache's 'daemon' user needs execute (+x) traversal on parent directories
chmod o+x /home/nori /home/nori/Downloads "${PROJECT_ROOT}"
chmod -R a+rX "${PUBLIC_DIR}"

echo "[2/5] Ensuring httpd-vhosts.conf include is enabled in httpd.conf..."
sed -i 's|^#Include etc/extra/httpd-vhosts.conf|Include etc/extra/httpd-vhosts.conf|' "${HTTPD_CONF}"

echo "[3/5] Configuring Apache VirtualHost (vrms.local) + localhost/vrms Alias..."
if ! grep -q "ServerName vrms.local" "${VHOSTS_FILE}"; then
  cat >> "${VHOSTS_FILE}" <<EOF

# VELO VRMS VirtualHost + localhost Alias
Alias /vrms "${PUBLIC_DIR}"
<Directory "${PUBLIC_DIR}">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

<VirtualHost *:80>
    ServerName vrms.local
    DocumentRoot "${PUBLIC_DIR}"
    <Directory "${PUBLIC_DIR}">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog "/opt/lampp/logs/vrms-error.log"
    CustomLog "/opt/lampp/logs/vrms-access.log" common
</VirtualHost>
EOF
fi

if ! grep -q "127.0.0.1 vrms.local" /etc/hosts; then
  echo "127.0.0.1 vrms.local" >> /etc/hosts
fi

echo "[4/5] Validating Apache configuration..."
/opt/lampp/bin/apachectl -t

echo "[5/5] Installing and enabling systemd xampp.service..."
install -m 0644 "${UNIT_SRC}" "${UNIT_DST}"
systemctl daemon-reload
systemctl enable xampp.service
systemctl restart xampp.service
systemctl status xampp.service --no-pager

echo "Done. Accessible on boot at http://vrms.local and http://localhost/vrms"
