# =============================================================================
# Hefesto Studio — VeterinariaHefesto: interfaz (Nuxt)
# =============================================================================

FROM docker.io/library/node:24-alpine3.24 AS construccion

WORKDIR /app

# Las dependencias primero, en capa aparte.
COPY web/package.json web/package-lock.json* ./

RUN npm install

COPY web/ ./

RUN npm run build

# -----------------------------------------------------------------------------
# Imagen final: solo los archivos que corren, sin node_modules de desarrollo.
# -----------------------------------------------------------------------------
FROM docker.io/library/node:24-alpine3.24 AS ejecucion

WORKDIR /app

ENV NODE_ENV=production
ENV NITRO_HOST=0.0.0.0
ENV NITRO_PORT=3000

# Usuario sin privilegios.
RUN addgroup -g 1000 -S appgroup \
    && adduser -u 1000 -S appuser -G appgroup

COPY --from=construccion /app/.output ./.output

# Lo unico que se monta como volumen: los archivos generados en caliente.
VOLUME ["/app/.output/public"]

USER appuser

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]
