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
# =============================================================================

set -eu

cd /var/www/html

if [ ! -f .env ]; then
    echo "VeterinariaHefesto: falta el archivo .env." >&2
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
