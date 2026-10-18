#!/bin/sh
# =============================================================================
# Entrada de la API de VeterinariaHefesto
# =============================================================================
# Deja la aplicacion en estado ejecutable: permisos, cache de configuracion y
# migraciones.
#
# POR QUE LAS MIGRACIONES CORREN AL ARRANQUE
# ------------------------------------------
# En una clinica hay una sola base y el despliegue lo hace el operador o el
# estudio. Correr las migraciones aqui evita el paso manual que siempre se
# olvida. El bloqueo de PostgreSQL hace que dos replicas arrancando a la vez no
# corran migraciones en paralelo.
#
# LAS PALABRAS CLAVE DE SHELL NO SE TRADUCEN
# ------------------------------------------
# `until`, `while`, `for`, `do`, `done`, `case`, `esac`, `if`, `then`, `else`,
# `fi`, `function`. Un `hasta` en vez de `until` rompe el contenedor en
# tiempo de ejecucion, no al escribir el archivo: es el error mas caro de
# revisar porque no aparece hasta que algo arranca.
#
# Este archivo se valida con `bash -n` antes de publicarse. Ver README.
# =============================================================================

set -eu

cd /var/www/html

if [ ! -f .env ]; then
    echo "VeterinariaHefesto: falta el archivo .env. Copie api/.env.example y complete los valores." >&2
    exit 1
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "VeterinariaHefesto: APP_KEY vacio. Ejecute 'php artisan key:generate' y guarde el resultado." >&2
    exit 1
fi

# Se espera a PostgreSQL. `until` es la palabra clave; no existe un `hasta`.
# Se limita la cantidad de intentos para que un contenedor no quede dando
# vueltas para siempre si la base nunca levanta.
intentos=0
until php artisan db:show --json > /dev/null 2>&1; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 30 ]; then
        echo "VeterinariaHefesto: la base de datos no respondio tras 30 intentos." >&2
        exit 1
    fi
    echo "VeterinariaHefesto: esperando a PostgreSQL (intento $intentos)..."
    sleep 2
done

echo "VeterinariaHefesto: preparando permisos..."
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

echo "VeterinariaHefesto: limpiando caches..."
php artisan config:clear --quiet
php artisan route:clear --quiet
php artisan view:clear --quiet

echo "VeterinariaHefesto: ejecutando migraciones..."
php artisan migrate --force --no-interaction

if [ "${APP_ENV:-production}" = "production" ]; then
    echo "VeterinariaHefesto: compilando caches..."
    php artisan config:cache --quiet
    php artisan route:cache --quiet
    php artisan view:cache --quiet
fi

echo "VeterinariaHefesto: lista."

exec "$@"
