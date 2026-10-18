#!/bin/sh
# =============================================================================
# Entrada de la API de ClinicaDental
# =============================================================================
# Deja la aplicacion en estado ejecutable: permisos, cache de configuracion y
# migraciones.
#
# POR QUE LAS MIGRACIONES CORREN AL ARRANQUE
# ------------------------------------------
# En un consultorio hay una sola base y el despliegue lo hace el operador o el
# estudio. Correr las migraciones aqui evita el paso manual que siempre se
# olvida, y sobre todo evita el otro problema: que alguien olvide correrlas y
# descubra la tabla faltante cuando un profesional este escribiendo una
# intervencion.
#
# El bloqueo de PostgreSQL hace que dos replicas arrancando a la vez no corran
# migraciones en paralelo.
#
# POR QUE ESTE SCRIPT ABORTA EN VEZ DE AVANZAR
# --------------------------------------------
# Si falta el `.env` o la `APP_KEY`, el contenedor NO arranca con la aplicacion
# a medio hacer. Es deliberado: un contenedor sano que responde mal es peor que
# uno que no responde, porque el balanceador lo manda trafico igual.
# =============================================================================

set -eu

cd /var/www/html

if [ ! -f .env ]; then
    echo "ClinicaDental: falta el archivo .env. Copie api/.env.example y complete los valores." >&2
    exit 1
fi

# El esqueleto de Laravel guarda la APP_KEY con el prefijo `base64:`. Si no esta,
# las cookies de sesion no se pueden cifrar ni verificar.
if ! grep -q '^APP_KEY=base64:' .env; then
    echo "ClinicaDental: APP_KEY vacio. Ejecute 'php artisan key:generate' y guarde el resultado." >&2
    exit 1
fi

# Aviso, no error: la base puede estar todavia levantandose en un despliegue en
# frio. `migrate` es lo que va a fallar con un mensaje util si no esta.
echo "ClinicaDental: preparando permisos..."
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

echo "ClinicaDental: limpiando caches..."
php artisan config:clear --quiet
php artisan route:clear --quiet
php artisan view:clear --quiet

echo "ClinicaDental: ejecutando migraciones..."
php artisan migrate --force --no-interaction

# El aislamiento entre consultorios se instala con las migraciones. Si una
# instalacion recien actualizada se levanta con esto en falso, hay que decirlo.
echo "ClinicaDental: comprobando que PostgreSQL acepta las conexiones..."
php -r '
$host = getenv("DB_HOST") ?: "127.0.0.1";
$pdo = new PDO(
    sprintf("pgsql:host=%s;port=%s;dbname=%s", $host, getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE") ?: "postgres"),
    getenv("DB_USERNAME") ?: "postgres",
    getenv("DB_PASSWORD") ?: "",
    [PDO::ATTR_TIMEOUT => 5]
);
' >/dev/null 2>&1 || echo "ClinicaDental: no se pudo abrir una conexion propia a PostgreSQL (puede ser normal: la RLS bloquea las consultas sin consultorio)."

if [ "${APP_ENV:-production}" = "production" ]; then
    echo "ClinicaDental: compilando caches..."
    php artisan config:cache --quiet
    php artisan route:cache --quiet
    php artisan view:cache --quiet
fi

exec "$@"