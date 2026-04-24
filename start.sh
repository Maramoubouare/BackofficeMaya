#!/bin/sh

export PORT="${PORT:-8080}"
echo "PORT = $PORT"

php bin/console cache:clear --env=prod 2>&1 || true
php bin/console doctrine:schema:create --if-not-exists 2>&1 || true
php bin/console doctrine:migrations:version --add --all --no-interaction 2>&1 || true

echo "Starting PHP on 0.0.0.0:$PORT"
exec php -S 0.0.0.0:$PORT -t public/
