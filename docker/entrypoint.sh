#!/bin/bash
set -e

# Configure PHP-FPM to use unix socket
echo '[www]
listen = /run/php-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660' > /usr/local/etc/php-fpm.d/zz-socket.conf

echo "==> Ensuring directory permissions..."
# storage/ persiste documentos de empleados (EmployeeDocumentService) y suele
# ser un volumen montado: al montarse queda como root, por eso hay que crearlo
# y reasignarlo a www-data en cada arranque.
mkdir -p storage 2>/dev/null || true
chown -R www-data:www-data tmp logs webroot/files storage 2>/dev/null || true
chmod -R 775 tmp logs webroot/files storage 2>/dev/null || true

echo "==> Starting PHP-FPM..."
php-fpm -D

echo "==> Starting Nginx..."
exec nginx -g "daemon off;"
