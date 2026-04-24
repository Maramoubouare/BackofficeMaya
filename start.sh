#!/bin/sh
set -e

echo "=== Cache clear ==="
php bin/console cache:clear --env=prod 2>&1 || true

echo "=== Create DB schema ==="
php bin/console doctrine:schema:create --if-not-exists 2>&1 || true

echo "=== Mark migrations ==="
php bin/console doctrine:migrations:version --add --all --no-interaction 2>&1 || true

echo "=== Starting PHP server on port $PORT ==="
exec php -S 0.0.0.0:$PORT -t public/
