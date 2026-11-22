#!/bin/sh
# =============================================================================
# Entrada del worker de colas de VeterinariaHefesto
# =============================================================================
# El worker corre trabajos en segundo plano y REUTILIZA LA MISMA CONEXION
# entre trabajos.
#
# POR QUE ESTO IMPORTA MAS QUE EN OTRAS APLICACIONES
# --------------------------------------------------
# El contexto de clinica viaja con `set_config(..., true)`, que es ALCANCE DE
# TRANSICCION: al confirmar o revertir, PostgreSQL descarta el valor solo.
#
# Con un `SET` normal (alcance de sesion), el valor sobreviviria al trabajo. Y
# como el worker reutiliza la conexion, un trabajo de la clinica de Valparaiso
# podria leer los datos de la de Santiago. Ese es exactamente el fallo que el
# alcance de transaccion evita, y es la razon por la que
# App\Services\Tenancy\ContextoTenant::establecer() LANZA EXCEPCION si se usa
# fuera de una transaccion.
#
# Ver docs/03.
#
# LA CLAVE DE LA APLICACION
# -------------------------
# El worker comparte la configuracion con la API: llega por variables de
# entorno, no por un archivo `.env` dentro del contenedor. La comprobacion es
# la misma que hace la API (mismo formato, mismo mensaje): un worker que no
# puede descifrar no tiene por que arrancar.
# =============================================================================

set -eu

cd /var/www/html

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

intentos=0
until php artisan db:show --json > /dev/null 2>&1; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 30 ]; then
        echo "VeterinariaHefesto: la base de datos no respondio tras 30 intentos." >&2
        exit 1
    fi
    sleep 2
done

echo "VeterinariaHefesto: arrancando el worker de colas."

exec php artisan queue:work \
    --queue=default \
    --sleep=3 \
    --tries=3 \
    --backoff=30 \
    --max-time=3600 \
    --memory=256
