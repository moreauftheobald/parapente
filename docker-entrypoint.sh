#!/bin/sh
set -e

# Répertoires que PHP-FPM (www-data) doit pouvoir écrire.
# Créés à chaque démarrage pour survivre aux rebuilds de conteneurs.
WRITABLE_DIRS="
    /var/www/html/storage/framework/cache
    /var/www/html/storage/framework/sessions
    /var/www/html/storage/framework/views
    /var/www/html/storage/logs
    /var/www/html/storage/app/public
    /var/www/html/bootstrap/cache
    /var/www/html/public/icons-cache
"

for dir in $WRITABLE_DIRS; do
    mkdir -p "$dir"
done

# Fixer les permissions uniquement sur les répertoires qui doivent
# être accessibles en écriture — pas sur tout /var/www/html (trop lent
# et inutile pour le code source en lecture seule).
chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache \
    /var/www/html/public/icons-cache

# Exécuter la commande passée (php-fpm, artisan queue:work, etc.)
exec "$@"
