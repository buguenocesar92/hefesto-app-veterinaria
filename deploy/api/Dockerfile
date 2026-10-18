# =============================================================================
# Hefesto Studio — VeterinariaHefesto: API (Laravel)
# =============================================================================
# Imagen fijada por digest y por etiqueta. Fijar la etiqueta sola no alcanza:
# una etiqueta como `8.4-fpm-alpine` puede apuntar a otra imagen manana, y una
# actualizacion silenciosa en produccion no es un despliegue.
# =============================================================================

FROM docker.io/library/php:8.4-fpm-alpine3.24

# Herramientas de compilacion: hacen falta para instalar extensiones de PHP que
# no vienen en la imagen base. Se quitan al final, en la misma capa, para que no
# queden en la imagen final.
RUN apk add --no-cache \
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
