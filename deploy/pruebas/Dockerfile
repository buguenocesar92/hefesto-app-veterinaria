# =============================================================================
# Hefesto Studio — VeterinariaHefesto: imagen SOLO para correr las pruebas
# =============================================================================
# No es la imagen de produccion (esa es deploy/api/Dockerfile). Existe para que
# `herramientas/probar.sh` corra igual en un equipo de desarrollo y en CI, sin
# depender del PHP que tenga instalado cada uno.
#
# PHP 8.4, la misma serie que deploy/api/Dockerfile, con pdo_pgsql (la suite
# exige PostgreSQL: el aislamiento es Row Level Security) y Composer.
# Las dos imagenes de origen van fijadas por etiqueta y por digest.
# =============================================================================
FROM docker.io/library/php:8.4-cli-alpine3.24@sha256:b9014f1cc1983827459faed83b315a40ead9860bdadefc042b31f1540ef8c746

RUN apk add --no-cache libpq unzip \
    && apk add --no-cache --virtual .compilacion postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
    && apk del --no-network .compilacion

COPY --from=docker.io/library/composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c /usr/bin/composer /usr/bin/composer

# Composer y PHPUnit escriben en su carpeta de usuario; el contenedor corre con
# el uid de quien lo lanza, que no tiene carpeta propia dentro de la imagen.
ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer

WORKDIR /repo/api
