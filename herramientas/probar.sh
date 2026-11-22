#!/usr/bin/env bash
# =============================================================================
# VeterinariaHefesto: comando unico de verificacion de `api/`
# =============================================================================
# Instala dependencias, levanta un PostgreSQL desechable, corre las
# migraciones y la suite, y compara el resultado con la lista de fallos
# conocidos (api/tests/fallos-conocidos.txt).
#
#   bash herramientas/probar.sh                 todo: instalar, migrar, suite
#   bash herramientas/probar.sh migrar          solo instalar y migrar
#   bash herramientas/probar.sh artisan <args>  un comando de artisan contra
#                                               una base recien migrada
#
# Sale con 0 solo si las migraciones corren y los fallos de la suite son
# EXACTAMENTE los conocidos: un fallo nuevo, o uno conocido que ya pasa y
# sigue en la lista, hacen fallar el comando.
#
# AISLAMIENTO
# -----------
# - PostgreSQL corre con `--network none`: no publica puertos y no se alcanza
#   desde el equipo ni desde otros contenedores.
# - PHP corre en un segundo contenedor que comparte el espacio de red del
#   primero (`--network container:`), asi que llega a la base por 127.0.0.1.
# - Las claves se generan en cada corrida y mueren con los contenedores.
# - El nombre lleva el PID: dos corridas a la vez no chocan. Al salir, el
#   contenedor y su volumen se borran.
#
# Requiere: bash, docker y python3. No requiere PHP ni Composer en el equipo.
# Con PROBAR_SIN_RED=1 no instala dependencias (usa el `vendor/` que haya):
# sirve donde no hay salida a internet.
# =============================================================================
set -euo pipefail

modo="${1:-suite}"
case "$modo" in
    suite | migrar) ;;
    artisan) shift ;;
    *)
        printf 'uso: %s [suite|migrar|artisan <args>]\n' "$0" >&2
        exit 64
        ;;
esac

raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
imagen_php="veterinaria-pruebas:local"
imagen_pg="docker.io/library/postgres:18.6@sha256:e3d24f6906d0760bf4da0dae872415391ededefad23fd471c47dc53b0ddf3c42"
base="veterinaria-pruebas-$$"
bd="veterinaria_test"
rol_app="veterinaria"

aleatorio() {
    head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n'
}

clave_admin="$(aleatorio)"
clave_app="$(aleatorio)"
clave_laravel="base64:$(head -c 32 /dev/urandom | base64)"

limpiar() {
    docker rm -f -v "$base-pg" >/dev/null 2>&1 || true
}
trap limpiar EXIT

en_php() {
    # $1: red del contenedor. El resto: el comando.
    local red="$1"
    shift
    docker run --rm --network "$red" \
        --user "$(id -u):$(id -g)" \
        --volume "$raiz:/repo" --workdir /repo/api \
        --env APP_ENV=testing \
        --env APP_KEY="$clave_laravel" \
        --env DB_CONNECTION=pgsql \
        --env DB_HOST=127.0.0.1 --env DB_PORT=5432 \
        --env DB_DATABASE="$bd" \
        --env DB_USERNAME="$rol_app" --env DB_PASSWORD="$clave_app" \
        "$imagen_php" "$@"
}

printf '[probar] imagen de pruebas\n'
docker build --quiet --file "$raiz/deploy/api/Dockerfile" --target pruebas --tag "$imagen_php" "$raiz" >/dev/null

if [ "${PROBAR_SIN_RED:-0}" = "1" ]; then
    [ -f "$raiz/api/vendor/autoload.php" ] \
        || { printf '[probar] PROBAR_SIN_RED=1 pero no hay api/vendor\n' >&2; exit 1; }
    printf '[probar] sin red: se usa el vendor/ existente\n'
else
    printf '[probar] composer install\n'
    en_php bridge composer install --no-interaction --no-progress
fi

printf '[probar] PostgreSQL desechable (%s)\n' "$base-pg"
docker run --detach --name "$base-pg" --network none \
    --env POSTGRES_DB="$bd" \
    --env POSTGRES_USER=veterinaria_admin \
    --env POSTGRES_PASSWORD="$clave_admin" \
    --env APP_DB_ROLE="$rol_app" \
    --env APP_DB_PASSWORD="$clave_app" \
    --volume "$raiz/deploy/postgres/inicializar-rol.sh:/docker-entrypoint-initdb.d/inicializar-rol.sh:ro" \
    "$imagen_pg" >/dev/null

# Durante la inicializacion la imagen levanta un servidor temporal que solo
# escucha en el socket; por TCP solo responde el definitivo.
lista=0
for _ in $(seq 1 60); do
    if docker exec "$base-pg" pg_isready --quiet --host 127.0.0.1 --username "$rol_app" --dbname "$bd"; then
        lista=1
        break
    fi
    sleep 1
done
if [ "$lista" != "1" ]; then
    docker logs "$base-pg" >&2 || true
    printf '[probar] PostgreSQL no arranco\n' >&2
    exit 1
fi

printf '[probar] migraciones\n'
en_php "container:$base-pg" php artisan migrate --force

if [ "$modo" = "migrar" ]; then
    en_php "container:$base-pg" php artisan clinica:verificar-aislamiento
    printf '[probar] migraciones y aislamiento: OK\n'
    exit 0
fi

if [ "$modo" = "artisan" ]; then
    en_php "container:$base-pg" php artisan "$@"
    exit $?
fi

printf '[probar] suite\n'
junit="$raiz/api/storage/framework/testing/junit.xml"
rm -f "$junit"
salida_suite=0
en_php "container:$base-pg" php vendor/bin/phpunit --no-progress \
    --log-junit storage/framework/testing/junit.xml || salida_suite=$?
printf '[probar] phpunit termino con %s\n' "$salida_suite"

python3 "$raiz/herramientas/comparar_resultados.py" \
    "$junit" "$raiz/api/tests/fallos-conocidos.txt"
