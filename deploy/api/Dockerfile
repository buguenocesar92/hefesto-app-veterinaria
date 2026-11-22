# =============================================================================
# Hefesto Studio — VeterinariaHefesto: API (Laravel)
# =============================================================================
# Imagen fijada por digest y por etiqueta. Fijar la etiqueta sola no alcanza:
# una etiqueta como `8.4-fpm-alpine` puede apuntar a otra imagen manana, y una
# actualizacion silenciosa en produccion no es un despliegue.
#
# EL CONTEXTO DE CONSTRUCCION ES LA RAIZ DEL REPO
# ------------------------------------------------
# Por eso los `COPY` usan rutas desde la raiz (`COPY api/ ./`) y por eso
# existe el `.dockerignore` de la raiz: es lo que deja fuera `api/vendor/`,
# `api/tests/` y los `.env` del contenedor. Ver deploy/docker-compose.prod.yml
# (`context: ..`).
#
# LAS CUATRO ETAPAS
# -----------------
# 1. `composer`: solo el binario de Composer, de la imagen oficial fijada por
#    digest. No aporta nada a la imagen final.
# 2. `pruebas`: PHP con las extensiones, Composer y las mitigaciones. Es la
#    imagen que usa `herramientas/probar.sh` (`--target pruebas`). Es la MISMA
#    serie de PHP que la de produccion, con las mismas mitigaciones: si una
#    prueba pasa ahi, pasa con lo que corre.
# 3. `dependencias`: `composer install --no-dev` y el autoload de produccion.
#    De aqui sale el `vendor/` de la imagen final.
# 4. La etapa FINAL (sin alias): la que corre. Es un `FROM` de la base fijada
#    por digest, SIN `AS`: el candado (api/tests/Unit/) comprueba que la
#    ultima linea `FROM` sea exactamente esa, con el digest al final.
# =============================================================================

# =============================================================================
# CVE-2026-109618 / CVE-2026-109619 (PHP2Shell, 2026-10-22)
# CVE-2026-126714 (GHSA-fm2m-fjf5-v3cp, 2026-11-19)
# =============================================================================
# La imagen se construye con PHP 8.4.28, que es la version PARCHEADA de la
# serie 8.4 para los dos CVEs:
#
#   - CVE-2026-109618 / CVE-2026-109619 (PHP2Shell): 8.2.35 / 8.3.36 /
#     8.4.27 / 8.5.12.
#   - CVE-2026-126714 (sesion «files» reescrita sin truncar): 8.2.36 /
#     8.3.37 / 8.4.28 / 8.5.13.
#
# Verificado el 2026-11-19 en el servidor de desarrollo, con el tag exacto:
#
#     $ docker run --rm docker.io/library/php:8.4.28-fpm-alpine3.24 php -v
#     PHP 8.4.28 (cli) (built: Nov 19 2026 14:36:19) (NTS)
#
# LA IMAGEN VA FIJADA POR ETIQUETA Y POR DIGEST
# ---------------------------------------------
# Antes esta base era un tag FLOTANTE (`php:8.4-fpm-alpine3.24`): un tag
# flotante puede mudar sin que nadie lo note, y una actualizacion silenciosa
# en produccion no es un despliegue. Ahora la etiqueta lleva la version exacta
# Y el digest: `php:8.4.28-fpm-alpine3.24@sha256:1c3d5f8d...`. Si el digest no
# coincide con la imagen publicada, el build falla en vez de desplegar otra
# cosa. El digest se puede volver a comprobar con:
#
#     $ docker buildx imagetools inspect docker.io/library/php:8.4.28-fpm-alpine3.24
#
# La comprobacion de que la version no baje de la parcheada va en el candado
# (api/tests/Unit/), que falla si la base vuelve a quedar por debajo.
#
# OJO: LA IMAGEN DESPLIEGADA HAY QUE RECONSTRUIRLA. El parche de PHP entra en
# el contenedor por la IMAGEN, no por este repositorio: mientras no se
# reconstruya y se redespliegue, el contenedor sigue con el PHP viejo. El
# endurecimiento de mas abajo (el ini y el pool de php-fpm) es la red de seguridad
# para ese mientras, y lo que sobrevive a un redeploy.
# =============================================================================

# -----------------------------------------------------------------------------
# Etapa 1: el binario de Composer, de la imagen oficial y fijada por digest.
# -----------------------------------------------------------------------------
FROM docker.io/library/composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer

# -----------------------------------------------------------------------------
# Etapa 2: la imagen de las PRUEBAS.
# -----------------------------------------------------------------------------
# Es la imagen que construye `herramientas/probar.sh` con `--target pruebas`.
# Lleva las mismas extensiones y las mismas mitigaciones que la de produccion:
# una prueba que pasa con una imagen mas endurecida que la de produccion no
# dice nada de la de produccion.
FROM docker.io/library/php:8.4.28-fpm-alpine3.24@sha256:1c3d5f8dd7f56ae38dfaf33934c7d5f326d5291b611df1e3ea18a332d8ef9407 AS pruebas

RUN apk update \
    && apk upgrade \
    && apk add --no-cache \
        postgresql-dev \
        icu-dev \
        oniguruma-dev \
        linux-headers \
        libpq \
        icu-libs \
        unzip \
        $PHPIZE_DEPS \
    && docker-php-ext-configure intl --enable-intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        opcache \
        pcntl \
        bcmath \
    && apk del --no-network $PHPIZE_DEPS postgresql-dev icu-dev oniguruma-dev linux-headers

# Composer, desde la etapa que lo trae.
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Composer y PHPUnit escriben en su carpeta de usuario; el contenedor corre con
# el uid de quien lo lanza, que no tiene carpeta propia dentro de la imagen.
ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer

# Las mitigaciones tambien van en la imagen de pruebas.
COPY deploy/api/docker/php.ini /usr/local/etc/php/conf.d/99-veterinaria.ini
COPY deploy/api/docker/99-php2shell-hardening.ini /usr/local/etc/php/conf.d/99-php2shell-hardening.ini
COPY deploy/api/docker/php/99-security-cve-2026-126714.ini /usr/local/etc/php/conf.d/99-security-cve-2026-126714.ini
COPY deploy/api/docker/zz-php2shell-fpm.conf /usr/local/etc/php-fpm.d/zz-php2shell-hardening.conf

# El repositorio se monta en /repo (ver herramientas/probar.sh).
WORKDIR /repo/api

# -----------------------------------------------------------------------------
# Etapa 3: las dependencias de PRODUCCION.
# -----------------------------------------------------------------------------
# El `composer install` va con `--no-dev`: en la imagen final no puede haber
# PHPUnit, Mockery, Faker ni Collision. Y el autoload se genera DESPUES de
# copiar la aplicacion, para que el mapa de clases incluya `app/`.
FROM pruebas AS dependencias

WORKDIR /var/www/html

# El `composer.lock` sin comodin: si falta, el build falla aca y no a mitad
# de una instalacion.
COPY api/composer.json api/composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

COPY api/ ./

# `dump-autoload` corre `package:discover`, que escribe el manifiesto de
# paquetes en bootstrap/cache. Sin ese paso, la aplicacion no arranca.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# -----------------------------------------------------------------------------
# Etapa 4 (FINAL): la imagen que corre.
# -----------------------------------------------------------------------------
# Sin `AS`: el candado (api/tests/Unit/MitigacionCve2026126714VeterinariaTest.php)
# exige que la ultima linea `FROM` sea la base fijada por digest, y que la
# linea termine en el digest.
FROM docker.io/library/php:8.4.28-fpm-alpine3.24@sha256:1c3d5f8dd7f56ae38dfaf33934c7d5f326d5291b611df1e3ea18a332d8ef9407

# Herramientas de compilacion: hacen falta para instalar extensiones de PHP que
# no vienen en la imagen base. Se quitan al final, en la misma capa, para que no
# queden en la imagen final. `libpq` e `icu-libs` se quedan: son las librerias
# de las que dependen `pdo_pgsql` e `intl` en tiempo de ejecucion.
RUN apk update \
    && apk upgrade \
    && apk add --no-cache \
        postgresql-dev \
        icu-dev \
        oniguruma-dev \
        linux-headers \
        libpq \
        icu-libs \
        nginx \
        supervisor \
        $PHPIZE_DEPS \
    && docker-php-ext-configure intl --enable-intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        opcache \
        pcntl \
        bcmath \
    && apk del --no-network $PHPIZE_DEPS postgresql-dev icu-dev oniguruma-dev linux-headers

# Usuario sin privilegios. La aplicacion NO corre como root.
RUN addgroup -g 1000 -S appgroup \
    && adduser -u 1000 -S appuser -G appgroup

WORKDIR /var/www/html

# La aplicacion, con el `vendor/` de produccion de la etapa de dependencias.
# De aqui NO viene Composer, ni `tests/`, ni ningun `.env`: el `.dockerignore`
# de la raiz deja los dos ultimos fuera del contexto.
COPY --from=dependencias /var/www/html/ ./

# Endurecimiento PHP2Shell (CVE-2026-109618 / CVE-2026-109619):
# la via CONFIRMADA (session.upload_progress) apagada, y los
# trabajadores de php-fpm reciclados cada 100 peticiones.
COPY deploy/api/docker/99-php2shell-hardening.ini /usr/local/etc/php/conf.d/99-php2shell-hardening.ini

# Mitigacion CVE-2026-126714 (GHSA-fm2m-fjf5-v3cp): apagado el vector de
# sesion nativa `files` (session.upload_progress) y endurecido el ciclo de
# vida de la sesion.
COPY deploy/api/docker/php/99-security-cve-2026-126714.ini /usr/local/etc/php/conf.d/99-security-cve-2026-126714.ini
COPY deploy/api/docker/zz-php2shell-fpm.conf /usr/local/etc/php-fpm.d/zz-php2shell-hardening.conf
COPY deploy/api/docker/php.ini /usr/local/etc/php/conf.d/99-veterinaria.ini
COPY deploy/api/docker/nginx.conf /etc/nginx/nginx.conf
COPY deploy/api/docker/supervisord.conf /etc/supervisord.conf
COPY deploy/api/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY deploy/api/docker/entrypoint-worker.sh /usr/local/bin/entrypoint-worker.sh

# `resources/views` tiene que existir aunque la aplicacion no tenga vistas:
# `php artisan view:cache` (que corre al arrancar en produccion) falla si el
# directorio no esta.
#
# `/var/log/nginx` es donde nginx abre su registro de errores ANTES de leer la
# configuracion (`/var/lib/nginx/logs` es un enlace ahi): sin ese directorio,
# cada arranque avisa de que no puede abrirlo. Los temporales van a /tmp; ver
# deploy/api/docker/nginx.conf.
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/entrypoint-worker.sh \
    && mkdir -p resources/views \
        storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
        bootstrap/cache \
        /var/log/nginx \
    && chown -R appuser:appgroup storage bootstrap/cache /var/lib/nginx /var/log/nginx

USER appuser

EXPOSE 8000

# El contenedor corre php-fpm y nginx, mantenidos vivos por supervisord.
# Ver deploy/api/docker/supervisord.conf.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
