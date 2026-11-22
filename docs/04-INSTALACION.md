# 04 - Instalación

De cero a la primera pantalla con datos.

> En el entorno donde se escribio esta aplicación **no habia PHP, ni Composer,
> ni Docker**. Nada de lo que hay en este documento se ejecuto. Ver
> [10](10-PRUEBAS-Y-ESTADO.md).
>
> **Actualización (2026-11-22):** la imagen de la API sí se construyó y se
> verificó (arranca con la configuración por variables de entorno, migra y
> responde). Ver [ESTADO-REAL.md](ESTADO-REAL.md).

## 1. Requisitos

| Necesario | Versión fijada |
|---|---|
| Docker Engine | 24 o superior, con Compose v2 |
| Puertos libres | 80 y 443 (producción), 3000 y 8000 (desarrollo) |

No hace falta instalar PHP, Composer ni Node en la maquina: todo corre dentro
de los contenedores.

## 2. Configurar el `.env`

```bash
# Desde la raíz del repositorio
cp api/.env.example api/.env
cp .env.example .env
cp deploy/.env.example deploy/.env
cp web/.env.example web/.env
```

Los cuatro archivos vienen **sin valores reales**. Hay que completarlos. Ver
[05](05-CONFIGURACION.md) para que significa cada uno.

**Por qué el de `deploy/`:** Compose lee las variables que sustituyen a
`${...}` en `docker-compose.prod.yml` (`DOMINIO`, `ACME_EMAIL`,
`DB_PASSWORD`, `DB_ADMIN_PASSWORD`) del **directorio del proyecto de
Compose**, que es `deploy/` (donde vive el archivo de Compose), no de la raíz
del repositorio. Sin ese archivo, `docker compose ... config` falla antes de
arrancar nada:

```bash
docker compose -f deploy/docker-compose.prod.yml config --quiet
```

Las contraseñas de `deploy/.env` tienen que ser **las mismas** que las de
`api/.env` (`DB_PASSWORD` y `DB_ADMIN_PASSWORD`): si difieren, PostgreSQL le
entrega a la aplicación una contraseña que no es la que ella usa.

Los `.env` no se versionan (ver `.gitignore`); los `.env.example` sí.

Lo minimo para que arranque:

```bash
# En api/.env
APP_KEY=                       # se genera en el paso 4
DB_PASSWORD=<una clave tuya>
AUTHENTIK_ISSUER=https://identidad.hefesto.cl
AUTHENTIK_CLIENT_ID=
AUTHENTIK_CLIENT_SECRET=
AUTHENTIK_REDIRECT_URI=https://veterinaria.ejemplo.cl/api/v1/auth/callback

# En .env (raiz)
DOMINIO=veterinaria.ejemplo.cl
ACME_EMAIL=admin@ejemplo.cl
```

## 3. Levantar

### Desarrollo

```bash
docker compose -f deploy/docker-compose.dev.yml up -d --build
```

- Interfaz: <http://localhost:3000>
- API: <http://localhost:8000>

El código de `api/` y `web/` esta montado como volumen, así que los cambios se
ven sin reconstruir.

### Producción

```bash
docker compose -f deploy/docker-compose.prod.yml up -d --build
```

Solo Caddy pública puertos. PostgreSQL, Redis, la API y la interfaz no se
alcanzan desde afuera de la red de Compose.

## 4. Generar la clave de la aplicación

```bash
docker compose -f deploy/docker-compose.prod.yml run --rm --no-deps \
  --entrypoint php api artisan key:generate --show
```

Copia la salida (una línea que empieza por `base64:`) a `APP_KEY=` en
`api/.env` y vuelve a levantar.

Por qué con `--entrypoint php`: el `entrypoint.sh` **se detiene si `APP_KEY`
no es válida**, y una clave válida es la que empieza por `base64:` seguida de
32 bytes en base64. Sin ese `--entrypoint`, el comando para generar la clave
nunca llega a correr en un contenedor que todavía no tiene clave. Por eso el
procedimiento es: generar la clave **fuera** del arranque, y ponerla en
`api/.env` antes de levantar.

El contenedor no necesita un `.env` dentro: `env_file` le entrega la
configuración como variables de entorno. El `entrypoint.sh` busca la clave en
el entorno y, solo si no está, en un `.env` que se haya montado a mano.

Es a propósito que el arranque se detenga: una aplicación sin clave de cifrado
de cookie tiene las sesiones firmadas con una clave vacia, que cualquiera puede
replicar.

## 5. Migraciones y semilla

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan migrate --force
```

Las migraciones también corren solas al arrancar el contenedor. Correrlas a
mano sirve para ver la salida sin el ruido del arranque.

### La semilla de ejemplo

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan db:seed --force
```

Carga:

- una clinica (`veterinaria-demo`);
- cuatro usuarios: `admin@`, `profesional@`, `recepcion@` y
  `mostrador@veterinaria.local`;
- un tutor con **dos mascotas**: Firulais (perro) y Michi (gato);
- catalogo de atenciones, con el bloque de vacunacion aparte;
- esquemas de vacunacion **sin verificar**;
- dos citas;
- una venta de mostrador con el stock descontado.

**En una base con datos reales, la semilla NO se corre.**

### ADVERTENCIA SOBRE LOS ESQUEMAS DE VACUNACION

Los intervalos que trae la semilla son **EJEMPLOS**. Van con `verificado = false`
y con la fuente escrita, precisamente para que no se confundan con algo
comprobado.

**Este proyecto no consulto ninguna fuente oficial de esquemas de vacunacion.**
Los intervalos dependen de la especie, de la edad, del fabricante y de la
normativa sanitaria de la jurisdiction.

Antes de usar el sistema con animales reales, cada regla tiene que revisarla y
confirmarla un profesional veterinario. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

## 6. Verificar el aislamiento

Este paso no es opcional y no se puede saltar.

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan clinica:verificar-aislamiento
```

Comprueba, en este orden:

1. que el rol de la base **no sea superusuario ni tenga `BYPASSRLS`**;
2. que las 16 tablas de negocio tengan RLS **activado y forzado**;
3. que una clinica no vea las mascotas de otra, **ni por identificador**;
4. que no se pueda escribir una fila declarando el tenant de otra;
5. que una consulta **sin contexto falle**, en vez de devolver cero filas.

**Si falla, no sigas.** No hay nada mas grave en esta aplicación que una
instalación sin aislamiento: el sistema seguiria funcionando con normalidad y
cada quien veria los datos de las demas clinicas.

El comando escribe filas de prueba y las borra al final. **No se corre contra
una base con datos reales.**

### El rol de las sesiones

Hay que crear el rol de `DB_SESIONES_USERNAME`, con SOLO lectura sobre
`sesiones`:

```sql
CREATE ROLE lectura_sesiones LOGIN PASSWORD '<clave>';
GRANT SELECT ON TABLE sesiones TO lectura_sesiones;
```

Sin `BYPASSRLS` y sin `superusuario`. Ver
[03](03-MULTITENANCY-Y-ROLES.md), seccion 5.

## 7. Configurar Authentik

La identidad la emite Authentik. En `config/authentik.php` hay que **completar
el mapa de equipos por rol**, que viene vacio a propósito:

```php
'grupos_por_rol' => [
    'Administradores de Veterinaria' => 'administrador',
    'Profesionales de Veterinaria' => 'profesional',
    'Recepcion de Veterinaria' => 'recepcion',
    'Mostrador de Veterinaria' => 'mostrador',
],
```

Sin ese mapa, `ResolutorDeSesion` lanza excepcion y nadie entra. Ver
[05](05-CONFIGURACION.md).

En Authentik hay que crear:

1. Un proveedor **OAuth2/OpenID Connect** con su PROPIO cliente para esta
   aplicación, apuntando a `AUTHENTIK_REDIRECT_URI`. Su propio cliente, para
   poder revocar el acceso a esta aplicación sin tocar las demas de Hefesto.
2. El alcance `openid` habilitado. Sin el, Authentik no devuelve `id_token` y
   `VerificadorIdToken` falla.
3. Un equipo por rol, con los nombres exactos del mapa de arriba.
4. Un equipo por clinica, llamado `VeterinariaHefesto:<correlacion>`. Por
   ejemplo `VeterinariaHefesto:veterinaria-demo`, que corresponde a
   `clinicas.correlacion_identidad` de la semilla.

Sin un equipo que declare una correlacion, **no hay ingreso**: se responde 403.

### Si la API sigue rechazando tokens

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan clinica:olvidar-cache-oidc
```

El JWKS esta cacheado un dia. Si Authentik roto sus claves, la API sigue
rechazando tokens validos contra la clave anterior.

**Ojo:** si el error dice "la clave que firmo el token no esta en el JWKS", el
problema puede ser que el `issuer` este mal escrito, no que haya rotado. Revisar
antes de limpiar la cache.

## 8. Los datos de ejemplo, a mano

Para comprobar lo importante sin escribir código:

1. Entrar como `recepcion@veterinaria.local`.
2. Abrir **Tutores**. Aparece Javiera Muñoz, con **dos** mascotas.
3. Abrir cada una. Cada una tiene su propia ficha.

Si al abrir Firulais apareciera algo de Michi, el sistema estaria mal. No
deberia pasar: la ficha se consulta por `mascota_id`, y hay una prueba que lo
comprueba (`HistorialNoSeMezclaEntreMascotasDelMismoTutorTest`).

4. Entrar como `mostrador@veterinaria.local` e intentar abrir la ficha de una
   mascota. Debe responder **404**, no 403.

## 9. Correr las pruebas

```bash
docker compose -f deploy/docker-compose.dev.yml run --rm api \
  php artisan test
```

**La base de pruebas tiene que ser PostgreSQL.** En SQLite las pruebas de
aislamiento pasarian sin comprobar nada, porque no hay RLS. Ver
[10](10-PRUEBAS-Y-ESTADO.md).

## 10. Respaldo

Antes de entrar animales reales, hay que hacer un respaldo y ** ensayar que se
restaura**. Un respaldo que nunca se probo de vuelta no es un respaldo. Ver
[08](08-RESPALDOS.md).
