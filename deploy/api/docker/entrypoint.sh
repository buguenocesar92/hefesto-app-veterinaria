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
# LA CONFIGURACION LLEGA POR VARIABLES DE ENTORNO
# -----------------------------------------------
# El contenedor NO necesita un archivo `.env` dentro: compose entrega la
# configuracion con `env_file` (deploy/docker-compose.prod.yml), que son
# variables de entorno. Por eso el `.env` no se exige, y por eso la clave de
# la aplicacion se busca primero en el entorno y solo despues en un `.env` que
# se haya montado a mano.
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

# -----------------------------------------------------------------------------
# LA CLAVE DE LA APLICACION
# -----------------------------------------------------------------------------
# La clave tiene que ser VALIDA: el prefijo `base64:` con 32 bytes en base64.
# `APP_KEY=base64:` (la clave vacia) es el error mas comun: la aplicacion
# levanta, pero las cookies van firmadas con una clave que cualquiera puede
# replicar. Por eso se comprueba el formato, no solo que exista.
#
# El mensaje dice COMO arreglarlo, porque un contenedor que no arranca sin
# explicacion es un contenedor que nadie sabe apagar.
clave="${APP_KEY:-}"
if [ -z "$clave" ] && [ -f .env ]; then
    clave="$(sed -n 's/^APP_KEY=//p' .env | tail -n 1 | tr -d '"\r ')"
fi

if ! printf '%s' "$clave" | grep -Eq '^base64:[A-Za-z0-9+/]{43}=$'; then
    echo "VeterinariaHefesto: APP_KEY no esta o no es valida." >&2
    echo "Generela con:" >&2
    echo "  docker compose -f deploy/docker-compose.prod.yml run --rm --no-deps --entrypoint php api artisan key:generate --show" >&2
    echo "y copie la salida a APP_KEY= en api/.env (o exporte APP_KEY en el entorno del contenedor)." >&2
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

# El contenedor corre como `appuser` y los directorios ya son suyos desde la
# imagen: no hay nada que cambiar de propietario, y `chown` pediria root.
echo "VeterinariaHefesto: preparando permisos..."
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs

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
