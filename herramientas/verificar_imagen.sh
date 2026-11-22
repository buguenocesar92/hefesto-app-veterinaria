#!/usr/bin/env bash
# =============================================================================
# VeterinariaHefesto: verificacion de la imagen de PRODUCCION de la API
# =============================================================================
# Construye la etapa final de deploy/api/Dockerfile y comprueba:
#
#   1. que no lleva dependencias de desarrollo, Composer, pruebas ni .env;
#   2. que trae el PHP corregido, pdo_pgsql y las mitigaciones de sesion;
#   3. que ARRANCA: con la configuracion solo por variables de entorno (como
#      la pasa `env_file` en compose), migra contra un PostgreSQL desechable y
#      responde 200 en /api/v1/salud a traves de nginx y php-fpm;
#   4. que sin APP_KEY se niega a arrancar.
#
#   bash herramientas/verificar_imagen.sh
#
# Sale con 0 solo si todas las comprobaciones pasan.
#
# AISLAMIENTO: igual que herramientas/probar.sh. PostgreSQL corre con
# `--network none`; la API comparte su espacio de red (`--network
# container:`) y nada publica puertos. Las claves se generan en cada corrida.
# Al salir se borran los contenedores, su volumen y la imagen construida.
#
# Requiere: bash, docker y coreutils (timeout, seq, sort, od, base64).
# =============================================================================
set -euo pipefail

raiz="${VERIFICAR_IMAGEN_RAIZ:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
imagen="veterinaria-api:verificar-$$"
imagen_pg="docker.io/library/postgres:18.6@sha256:e3d24f6906d0760bf4da0dae872415391ededefad23fd471c47dc53b0ddf3c42"
base="veterinaria-imagen-$$"
bd="veterinaria"
rol_app="veterinaria"
fallos=0

bien() { printf '[imagen] OK    %s\n' "$*"; }
mal() {
    printf '[imagen] FALLA %s\n' "$*" >&2
    fallos=$((fallos + 1))
}
aleatorio() { head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

clave_admin="$(aleatorio)"
clave_app="$(aleatorio)"
clave_laravel="base64:$(head -c 32 /dev/urandom | base64)"

limpiar() {
    docker rm -f -v "$base-worker" "$base-api" "$base-pg" >/dev/null 2>&1 || true
    docker image rm -f "$imagen" >/dev/null 2>&1 || true
}
trap limpiar EXIT

printf '[imagen] construyendo la etapa final de deploy/api/Dockerfile\n'
if ! docker build --pull --quiet --file "$raiz/deploy/api/Dockerfile" --tag "$imagen" "$raiz" >/dev/null; then
    printf '[imagen] FALLA docker build\n' >&2
    exit 1
fi
bien "docker build"

# --- 0. La etapa final usa exactamente la base fijada ------------------------
base_fijada="docker.io/library/php:8.4.28-fpm-alpine3.24@sha256:1c3d5f8dd7f56ae38dfaf33934c7d5f326d5291b611df1e3ea18a332d8ef9407"
ultimo_from="$(grep -E '^[[:space:]]*FROM[[:space:]]' "$raiz/deploy/api/Dockerfile" | tail -n 1 | tr -s ' \t' ' ' | sed 's/^ *//; s/ *$//')"
if [ "$ultimo_from" = "FROM $base_fijada" ]; then
    bien "la etapa final parte de la base fijada por digest"
else
    mal "la etapa final no es «FROM $base_fijada» (es «$ultimo_from»)"
fi

en_imagen() {
    docker run --rm --network none --entrypoint sh "$imagen" -c "$1" >/dev/null 2>&1
}

# --- 1. Lo que NO debe llevar la imagen de produccion -----------------------
for paquete in phpunit/phpunit mockery/mockery fakerphp/faker nunomaduro/collision; do
    if en_imagen "test ! -e /var/www/html/vendor/$paquete"; then bien "sin $paquete"; else mal "lleva $paquete (dependencia de desarrollo)"; fi
done
if en_imagen 'test -s /var/www/html/vendor/composer/autoload_classmap.php && ! grep -q "PHPUnit" /var/www/html/vendor/composer/autoload_classmap.php'; then
    bien "el autoload no conoce clases de PHPUnit"
else
    mal "el autoload incluye clases de PHPUnit"
fi
if en_imagen 'grep -Eq "\"dev\": *false" /var/www/html/vendor/composer/installed.json'; then
    bien "vendor/ instalado con --no-dev (installed.json dev=false)"
else
    mal "vendor/composer/installed.json no dice dev=false"
fi
if en_imagen 'test -f /var/www/html/vendor/autoload.php'; then bien "trae vendor/ de produccion"; else mal "no trae vendor/autoload.php"; fi
if en_imagen '! command -v composer'; then bien "sin composer"; else mal "lleva el binario de composer"; fi
if en_imagen 'test ! -e /var/www/html/.env'; then bien "sin .env"; else mal "lleva un .env dentro de la imagen"; fi
if en_imagen 'test ! -e /var/www/html/tests'; then bien "sin tests/"; else mal "lleva la carpeta tests/"; fi

# --- 2. PHP y mitigaciones --------------------------------------------------
salida="$(docker run --rm --network none --entrypoint php "$imagen" -r 'echo PHP_VERSION,"|",ini_get("session.upload_progress.enabled"),"|",ini_get("session.upload_progress.cleanup"),"|",ini_get("session.serialize_handler"),"|",ini_get("session.use_strict_mode"),"|",(extension_loaded("pdo_pgsql") ? "1" : "0");')"
IFS='|' read -r version upload limpieza serializador estricto pgsql <<< "$salida"
if [ "$(printf '%s\n%s\n' 8.4.28 "$version" | sort -V | head -n 1)" = "8.4.28" ] && [ "${version%%.*}" = "8" ]; then
    bien "PHP $version"
else
    mal "PHP $version por debajo de 8.4.28"
fi
if [ -z "$upload" ] || [ "$upload" = "0" ]; then bien "session.upload_progress.enabled=Off"; else mal "session.upload_progress.enabled=$upload"; fi
if [ "$limpieza" = "1" ]; then bien "session.upload_progress.cleanup=On"; else mal "session.upload_progress.cleanup=$limpieza"; fi
if [ "$serializador" = "php_serialize" ]; then bien "session.serialize_handler=php_serialize"; else mal "session.serialize_handler=$serializador"; fi
if [ "$estricto" = "1" ]; then bien "session.use_strict_mode=1"; else mal "session.use_strict_mode=$estricto"; fi
if [ "$pgsql" = "1" ]; then bien "pdo_pgsql cargado"; else mal "falta pdo_pgsql"; fi

# --- 3. Lo que el CMD necesita ----------------------------------------------
if en_imagen 'command -v supervisord && command -v nginx && command -v php-fpm'; then
    bien "supervisord, nginx y php-fpm instalados"
else
    mal "falta supervisord, nginx o php-fpm (el CMD no puede arrancar)"
fi
if en_imagen 'nginx -t'; then bien "nginx -t"; else mal "nginx -t falla"; fi
if en_imagen 'cd /var/www/html && php artisan --version'; then bien "artisan responde"; else mal "artisan no responde"; fi
if [ "$(docker run --rm --network none --entrypoint id "$imagen" -u)" != "0" ]; then bien "corre sin root"; else mal "corre como root"; fi

# --- 4. Sin APP_KEY no arranca ----------------------------------------------
sin_clave=0
mensaje="$(timeout 60 docker run --rm --network none "$imagen" 2>&1)" || sin_clave=$?
if [ "$sin_clave" != "0" ] && [ "$sin_clave" != "124" ] && printf '%s' "$mensaje" | grep -q 'APP_KEY'; then
    bien "sin APP_KEY se niega a arrancar (salida $sin_clave)"
else
    mal "sin APP_KEY no se nego a arrancar con un mensaje sobre APP_KEY (salida $sin_clave)"
fi

# APP_KEY invalida (prefijo sin clave) tambien se rechaza
clave_vacia=0
mensaje="$(timeout 60 docker run --rm --network none --env APP_KEY=base64: "$imagen" 2>&1)" || clave_vacia=$?
if [ "$clave_vacia" != "0" ] && [ "$clave_vacia" != "124" ] && printf '%s' "$mensaje" | grep -q 'APP_KEY'; then
    bien "APP_KEY=base64: vacia se rechaza (salida $clave_vacia)"
else
    mal "APP_KEY=base64: vacia no se rechazo con un mensaje sobre APP_KEY (salida $clave_vacia)"
fi

# El procedimiento documentado para generar la clave funciona sin .env
if docker run --rm --network none --entrypoint php "$imagen" artisan key:generate --show 2>/dev/null | grep -Eq '^base64:[A-Za-z0-9+/]{43}=$'; then
    bien "artisan key:generate --show genera una clave sin .env"
else
    mal "artisan key:generate --show no genera una clave"
fi

# --- 5. Arranca de verdad ---------------------------------------------------
printf '[imagen] PostgreSQL desechable (%s)\n' "$base-pg"
docker run --detach --name "$base-pg" --network none \
    --env POSTGRES_DB="$bd" \
    --env POSTGRES_USER=veterinaria_admin \
    --env POSTGRES_PASSWORD="$clave_admin" \
    --env APP_DB_ROLE="$rol_app" \
    --env APP_DB_PASSWORD="$clave_app" \
    --volume "$raiz/deploy/postgres/inicializar-rol.sh:/docker-entrypoint-initdb.d/inicializar-rol.sh:ro" \
    "$imagen_pg" >/dev/null

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
    printf '[imagen] FALLA PostgreSQL no arranco\n' >&2
    exit 1
fi

# Configuracion SOLO por variables de entorno, sin archivo .env. La sesion,
# la cache y la cola van en memoria para no depender de Redis: esta prueba
# mira la imagen, no la configuracion de produccion.
docker run --detach --name "$base-api" --network "container:$base-pg" \
    --env APP_ENV=production \
    --env APP_DEBUG=false \
    --env APP_KEY="$clave_laravel" \
    --env LOG_CHANNEL=stderr \
    --env DB_CONNECTION=pgsql \
    --env DB_HOST=127.0.0.1 --env DB_PORT=5432 \
    --env DB_DATABASE="$bd" \
    --env DB_USERNAME="$rol_app" --env DB_PASSWORD="$clave_app" \
    --env SESSION_DRIVER=array \
    --env CACHE_STORE=array \
    --env QUEUE_CONNECTION=sync \
    "$imagen" >/dev/null

estado=""
for _ in $(seq 1 90); do
    estado="$(docker exec "$base-api" php -r '$c = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 5]]); @file_get_contents("http://127.0.0.1:8000/api/v1/salud", false, $c); echo isset($http_response_header[0]) ? explode(" ", $http_response_header[0])[1] : "sin-respuesta";' 2>/dev/null || true)"
    [ "$estado" = "200" ] && break
    sleep 1
done
if [ "$estado" = "200" ]; then
    bien "GET /api/v1/salud por nginx responde 200"
else
    mal "GET /api/v1/salud no respondio 200 (ultimo: ${estado:-nada})"
    docker logs "$base-api" >&2 || true
fi

vendor_http="$(docker exec "$base-api" php -r '$c = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 5]]); @file_get_contents("http://127.0.0.1:8000/vendor/composer/installed.json", false, $c); echo isset($http_response_header[0]) ? explode(" ", $http_response_header[0])[1] : "sin-respuesta";' 2>/dev/null || true)"
if [ "$vendor_http" != "200" ]; then bien "vendor/ no se sirve por HTTP ($vendor_http)"; else mal "vendor/ se sirve por HTTP"; fi

# El worker tambien arranca con la configuracion por variables de entorno
docker run --detach --name "$base-worker" --network "container:$base-pg" \
    --env APP_ENV=production --env APP_KEY="$clave_laravel" --env LOG_CHANNEL=stderr \
    --env DB_CONNECTION=pgsql --env DB_HOST=127.0.0.1 --env DB_PORT=5432 \
    --env DB_DATABASE="$bd" --env DB_USERNAME="$rol_app" --env DB_PASSWORD="$clave_app" \
    --env CACHE_STORE=array --env QUEUE_CONNECTION=database \
    --entrypoint /usr/local/bin/entrypoint-worker.sh "$imagen" >/dev/null
worker_ok=0
for _ in $(seq 1 60); do
    if docker logs "$base-worker" 2>&1 | grep -q 'arrancando el worker de colas'; then
        worker_ok=1
        break
    fi
    sleep 1
done
if [ "$worker_ok" = "1" ]; then bien "el worker arranca"; else mal "el worker no arranco"; docker logs "$base-worker" >&2 || true; fi

estado_migraciones="$(docker exec "$base-api" sh -c 'cd /var/www/html && php artisan migrate:status' 2>&1 || true)"
if printf '%s' "$estado_migraciones" | grep -q 'activar_row_level_security' \
    && ! printf '%s' "$estado_migraciones" | grep -q 'Pending'; then
    bien "migraciones aplicadas al arrancar"
else
    mal "las migraciones no quedaron aplicadas al arrancar"
fi

if [ "$fallos" -ne 0 ]; then
    printf '[imagen] %s comprobaciones fallaron\n' "$fallos" >&2
    exit 1
fi
printf '[imagen] imagen de produccion verificada\n'
