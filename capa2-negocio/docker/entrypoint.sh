#!/bin/sh
# Arranque del backend Laravel dentro de Docker.
#   serve     -> instala dependencias, espera la BD, migra + siembra y levanta la API en el puerto API_PUERTO
#   scheduler -> ejecuta las tareas programadas (recordatorios y alertas automaticas)
set -e
cd /var/www

if [ ! -f vendor/autoload.php ]; then
    echo "[canal11] Instalando dependencias de Composer..."
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64' .env; then
    php artisan key:generate --force
fi

echo "[canal11] Esperando a PostgreSQL en ${DB_HOST}:${DB_PORT}..."
until pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" >/dev/null 2>&1; do
    sleep 1
done

# Las tablas viven en el esquema DB_SCHEMA (canal11): se crea antes de migrar (la tabla migrations tambien va ahi)
PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -q \
    -c "CREATE SCHEMA IF NOT EXISTS ${DB_SCHEMA:-canal11}" || true

case "$1" in
    serve)
        php artisan config:clear >/dev/null
        php artisan migrate --force
        php artisan db:seed --force
        echo "[canal11] API lista en el puerto ${API_PUERTO}"
        # Servidor PHP con varios workers (artisan serve descarta las variables de entorno de Docker)
        export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-6}"
        cd public
        exec php -d upload_max_filesize=320M -d post_max_size=330M -d max_execution_time=300 -d memory_limit=512M \
            -S "0.0.0.0:${API_PUERTO}" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
        ;;
    scheduler)
        # Espera a que la API haya migrado la base antes de revisar avisos
        until php artisan migrate:status >/dev/null 2>&1; do sleep 3; done
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
