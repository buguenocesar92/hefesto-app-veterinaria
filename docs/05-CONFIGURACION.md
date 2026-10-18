# 05 - Configuración

Cada variable, que hace y por que importa.

Los tres `.env.example` vienen **sin valores reales**. Este documento explica
que hay que poner en cada uno.

## Los tres archivos

| Archivo | Para que es |
|---|---|
| `api/.env` | La API (Laravel): base de datos, Authentik, reglas |
| `.env` (raiz) | El despliegue: dominio y correo de Caddy |
| `web/.env` | La interfaz (Nuxt): solo la URL interna de la API |

`deploy/docker-compose.prod.yml` lee `api/.env` para el servicio `caddy`, y
`.env` (raiz) para el dominio. No se mezclan.

## Aplicación

| Variable | Por omision | Para que |
|---|---|---|
| `APP_ENV` | `local` | `production` en producción |
| `APP_DEBUG` | `false` | **En producción tiene que ser `false`.** El registro con nivel `debug` puede incluir el cuerpo de una peticion, y eso incluye fichas |
| `APP_TIMEZONE` | `America/Santiago` | Zona por omision. Cada clinica tiene la suya |
| `APP_KEY` | *(vacio)* | Generela con `php artisan key:generate`. El arranque **se detiene** si esta vacia |

## Base de datos

| Variable | Por omision | Nota |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | **PostgreSQL, obligatorio.** La RLS no existe en otro motor |
| `DB_HOST` | `postgres` | El nombre del servicio en Compose |
| `DB_DATABASE` | `veterinaria` | |
| `DB_USERNAME` | `veterinaria` | |
| `DB_PASSWORD` | *(vacio)* | Obligatorio. Compose falla si no esta |
| `DB_SSLMODE` | `prefer` | Dentro de la red de Compose. Si la base queda **afuera**, use `require` |

### La conexión de sesiones

| Variable | Nota |
|---|---|
| `DB_SESIONES_USERNAME` | Rol con **SOLO lectura** sobre `sesiones` |
| `DB_SESIONES_PASSWORD` | |
| `DB_SESIONES_DATABASE` | La misma base, normalmente |

Existe por un problema de orden: `sesiones` tiene RLS, y el guard de sesión la
lee **antes** de saber de que clinica es la peticion. Con el manejador de
Laravel, esa lectura lanzaria excepcion y **nadie podria entrar**.

Ver [03](03-MULTITENANCY-Y-ROLES.md), seccion 5.

```sql
CREATE ROLE lectura_sesiones LOGIN PASSWORD '<clave>';
GRANT SELECT ON TABLE sesiones TO lectura_sesiones;
```

Sin `BYPASSRLS` y sin superusuario.

### La conexión de la bitacora

| Variable | Nota |
|---|---|
| `DB_BITACORA_*` | Misma base por omision |

Puede apuntar a **otra base, incluso a otra maquina**: es una de las
separaciones que suele pedir una auditoria externa, porque así los accesos
quedan fuera del alcance de quien opera la aplicación. Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

## Redis

| Variable | Por omision | Nota |
|---|---|---|
| `REDIS_CLIENT` | `predis` | Sin extension de PHP que compilar |
| `REDIS_CACHE_DB` | `1` | Cache separada de las colas |
| `CACHE_STORE` | `redis` | |
| `QUEUE_CONNECTION` | `redis` | |

### Por que las sesiones NO van en Redis

```dotenv
SESSION_DRIVER=database
```

Una sesión autoriza el acceso a una ficha clinica. Si Redis (que es cache) se
pierde o se reinicia, quien estaba atendiendo se queda fuera en mitad de una
consulta. En la base, no pasa.

Ademas, `deploy/api/docker/php.ini` **no fija `session.save_handler`**, a
propósito: dejaria a PHP escribiendo sesiones en archivos locales, que es
exactamente el fallo que se quiere evitar. Un fallo silencioso: la aplicación
arrancaria y cada peticion crearia su sesión sin persistirla.

## Sesión

| Variable | Por omision | Nota |
|---|---|---|
| `SESSION_EXPIRACION_MINUTOS` | `120` | Conveniene poco. Una sesión abierta mucho rato es una sesión abierta a la ficha clinica |
| `SESSION_SECURE_COOKIE` | `false` | **En producción, `true`.** La cookie solo debe viajar por HTTPS |
| `SESSION_SAME_SITE` | `lax` | Evita que una sesión se mande desde otro sitio: es la mitad del ataque CSRF |

## Authentik

| Variable | Nota |
|---|---|
| `AUTHENTIK_ISSUER` | URL pública del emisor, **sin barra final**. El descubrimiento se lee de `<issuer>/.well-known/openid-configuration` |
| `AUTHENTIK_CLIENT_ID` | Cliente OIDC **exclusivo de esta aplicación**, para poder revocarlo sin tocar las demas |
| `AUTHENTIK_CLIENT_SECRET` | |
| `AUTHENTIK_REDIRECT_URI` | Debe coincidir **exactamente** con lo configurado en Authentik |
| `AUTHENTIK_SCOPES` | `openid profile email`. Sin `openid`, Authentik no devuelve `id_token` |
| `AUTHENTIK_ALGORITMO` | `RS256`. Se comprueba **antes** de la firma: corta el ataque de confusion de algoritmo |
| `AUTHENTIK_CACHE_JWKS` | `86400`. Las claves rotan pocas veces |
| `AUTHENTIK_LEEWAY` | `60`. Margen de reloj para `exp`, `iat` y `nbf` |
| `AUTHENTIK_GRUPO_POR_CLINICA` | Prefijo de los equipos que declaran la clinica |

### El mapa de equipos por rol

`config/authentik.php` trae `grupos_por_rol` **vacio a propósito**. Hay que
completarlo:

```php
'grupos_por_rol' => [
    'Administradores de Veterinaria' => 'administrador',
    'Profesionales de Veterinaria' => 'profesional',
    'Recepcion de Veterinaria' => 'recepcion',
    'Mostrador de Veterinaria' => 'mostrador',
],
```

**El orden importa**: si alguien esta en varios equipos, gana el primero que
coincida. `administrador` va primero.

Sin este mapa, `ResolutorDeSesion` lanza excepcion al ingresar y **nadie puede
entrar**. No es un aviso: es un rechazo.

### El equipo de clinica

Se llama `VeterinariaHefesto:<correlacion>`, donde `<correlacion>` se busca en
`clinicas.correlacion_identidad`. Por ejemplo, la semilla crea
`veterinaria-demo`, así que el equipo es `VeterinariaHefesto:veterinaria-demo`.

Sin un equipo que declare una correlacion, **no hay ingreso**.

## Reglas de la aplicación

Viven en `config/veterinaria.php`.

| Variable | Por omision | Que es |
|---|---|---|
| `VETERINARIA_DURACION_MAXIMA_MINUTOS` | `240` | Tope de una atención |
| `VETERINARIA_VENTANA_POR_VENCER_DIAS` | `30` | **Regla de trabajo, no sanitaria** |
| `VETERINARIA_DIAS_AVISO_ATRASO` | `60` | Anticipacion del tablero de llamadas |
| `VETERINARIA_PERMITIR_STOCK_NEGATIVO` | `false` | **No cambiar** sin decisión de la clinica |
| `VETERINARIA_RETENCION_BITACORA_DIAS` | `3650` | **No verificado.** Ver [07](07-SEGURIDAD-Y-LEY-19628.md) |

### Sobre `VENTANA_POR_VENCER_DIAS`

Es una regla de **trabajo**, no una regla sanitaria. Una dosis que vence manana
no se considera atrasada, porque en la práctica hay turnos, hay colas y hay
gente que llega tarde. Cada clinica la ajusta.

**No dice nada sobre si un intervalo de vacunacion es correcto.** Eso no esta en
la configuración: esta en `esquemas_vacunacion`, y lo configura y verifica la
clinica.

### Sobre `PERMITIR_STOCK_NEGATIVO`

Vender con stock negativo significa aceptar cobrar por algo que no habia. Se
descubre en el cierre de caja, cuando ya no se puede arreglar.

Dejalo en `false`.

### Sobre `RETENCION_BITACORA_DIAS`

El valor por omision es un **dato por omision, no una recomendacion juridica**.
El plazo que corresponda lo define la normativa aplicable y la política de la
institucion. **Este valor no fue verificado.** Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

## Despliegue

Solo en `.env` (raiz):

| Variable | Nota |
|---|---|
| `DOMINIO` | El dominio público. Caddy emite el certificado por ACME |
| `ACME_EMAIL` | Para los avisos de renovacion |

## Interfaz

Solo en `web/.env`:

| Variable | Nota |
|---|---|
| `NUXT_API_BASE` | URL **interna** de la API. Solo la usa el servidor de Nuxt; nunca viaja al navegador |
| `NUXT_DIAS_AGENDA` | Dias de agenda por omision |

## Lista de comprobacion antes de producción

1. [ ] `APP_ENV=production` y `APP_DEBUG=false`
2. [ ] `SESSION_SECURE_COOKIE=true`
3. [ ] `APP_KEY` generada y copiada al host
4. [ ] `DB_PASSWORD` con una clave real, no la de ejemplo
5. [ ] `AUTHENTIK_*` completo, con cliente propio de esta aplicación
6. [ ] `grupos_por_rol` completo en `config/authentik.php`
7. [ ] Rol de sesiones creado, con SOLO lectura y sin `BYPASSRLS`
8. [ ] `clinica:verificar-aislamiento` en verde
9. [ ] `LOG_LEVEL=info` o mas alto
10. [ ] Respaldo probado, con una restauración de verdad
