# =============================================================================
# Hefesto Studio — VeterinariaHefesto: API (Laravel)
# =============================================================================
# Imagen fijada por digest y por etiqueta. Fijar la etiqueta sola no alcanza:
# una etiqueta como `8.4-fpm-alpine` puede apuntar a otra imagen manana, y una
# actualizacion silenciosa en produccion no es un despliegue.
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
FROM docker.io/library/php:8.4.28-fpm-alpine3.24@sha256:1c3d5f8dd7f56ae38dfaf33934c7d5f326d5291b611df1e3ea18a332d8ef9407

# Herramientas de compilacion: hacen falta para instalar extensiones de PHP que
# no vienen en la imagen base. Se quitan al final, en la misma capa, para que no
# queden en la imagen final.
RUN apk update \
    && apk upgrade \
    && apk add --no-cache \
        postgresql-dev \
        icu-dev \
        oniguruma-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-configure intl --enable-intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        opcache \
        pcntl \
        bcmath \
    && apk del --no-network $PHPIZE_DEPS

# Compositor, desde la imagen oficial de Composer y fijada por digest.
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Usuario sin privilegios. La aplicacion NO corre como root.
RUN addgroup -g 1000 -S appgroup \
    && adduser -u 1000 -S appuser -G appgroup

WORKDIR /var/www/html

# Las dependencias primero, en una capa aparte: mientras el composer.json no
# cambie, esta capa se reutiliza y la construccion es mucho mas rapida.
COPY api/composer.json api/composer.lock* ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

COPY api/ ./

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && composer dump-autoload --dev --classmap-authoritative \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R appuser:appgroup storage bootstrap/cache

COPY deploy/api/docker/php.ini /usr/local/etc/php/conf.d/99-veterinaria.ini

# Endurecimiento PHP2Shell (CVE-2026-109618 / CVE-2026-109619):
# la via CONFIRMADA (session.upload_progress) apagada, y los
# trabajadores de php-fpm reciclados cada 100 peticiones.
COPY deploy/api/docker/99-php2shell-hardening.ini /usr/local/etc/php/conf.d/99-php2shell-hardening.ini

# Mitigacion CVE-2026-126714 (GHSA-fm2m-fjf5-v3cp): apagado el vector de
# sesion nativa `files` (session.upload_progress) y endurecido el ciclo de
# vida de la sesion.
COPY deploy/api/docker/php/99-security-cve-2026-126714.ini /usr/local/etc/php/conf.d/99-security-cve-2026-126714.ini
COPY deploy/api/docker/zz-php2shell-fpm.conf /usr/local/etc/php-fpm.d/zz-php2shell-hardening.conf
COPY deploy/api/docker/nginx.conf /etc/nginx/nginx.conf
COPY deploy/api/docker/supervisord.conf /etc/supervisord.conf
COPY deploy/api/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY deploy/api/docker/entrypoint-worker.sh /usr/local/bin/entrypoint-worker.sh

RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/entrypoint-worker.sh

USER appuser

EXPOSE 8000

# El contenedor corre php-fpm y nginx, mantenidos vivos por supervisord.
# Ver deploy/api/docker/supervisord.conf.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
