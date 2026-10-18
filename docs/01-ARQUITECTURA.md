# 01 - Arquitectura

> PostgreSQL corre con dos roles: uno de arranque y otro de aplicación. Solo el
> de aplicación conecta con el sistema, y es el que las políticas de
> aislamiento pueden alcanzar. Ver [03](03-MULTITENANCY-Y-ROLES.md).

Que corre, donde corre y como llega una peticion desde el consultorio hasta
PostgreSQL.

## Las piezas

| Pieza | Que es | Tecnologia |
|---|---|---|
| Interfaz | Lo que ve quien atiende | Nuxt 4, render en el servidor |
| BFF | Puente entre la interfaz y la API | `web/server/api`, mismo proceso Nuxt |
| API | Reglas, autorización y datos | Laravel 13 |
| Base | Datos y aislamiento | PostgreSQL 18 con Row Level Security |
| Cache | Agenda y descubrimiento OIDC | Redis 8 |
| Colas | Trabajos en segundo plano | Redis, con `queue:work` |
| Identidad | Quien entra | Authentik (OpenID Connect) |
| Puerta | TLS y un solo origen | Caddy |

## Por que el navegador no habla con la API

El navegador pide `/api/agenda` al **servidor de Nuxt**, nunca a Laravel
directamente. El servidor de Nuxt reenvia la cookie de sesión a la API y le
devuelve la respuesta.

Eso tiene tres efectos:

1. **La cookie de sesión nunca es llamada por nombre por el código del
   navegador.** Es `HttpOnly`, y el único que la reenvia es el servidor de Nuxt.
2. **No hay ningun token en el código del navegador.** No hay `localStorage`,
   ni `sessionStorage`, ni un token de API. La sesión ES la cookie.
3. **La API no tiene una URL pública.** Solo Caddy la alcanza, y solo por red
   interna. Ver `deploy/docker-compose.prod.yml`.

Es la mismadecision que en las demas aplicaciones de Hefesto, y esta
explicada en `apps/portal-autoservicio/docs/01-ARQUITECTURA.md`.

## El recorrido de una peticion

Para `GET /api/mascotas/{id}/historial`, que es la mas importante:

```
Navegador
  │  cookie de sesion (HttpOnly)
  ▼
Caddy ──── TLS, cabeceras de seguridad, un solo origen
  │
  ▼
Nuxt (BFF) ──── render en el servidor, reenvia Cookie y X-XSRF-TOKEN
  │
  ▼
Laravel
  │
  ├─ ResolverTenant        envuelve la peticion en una transaccion
  │                       y fija app.clinica_id con set_config(..., true)
  │
  ├─ auth                  reconstruye la sesion desde la tabla `sesiones`
  │                       (conexion sin RLS: ver docs/03)
  │
  ├─ AuditarFicha          anota el intento en accesos_ficha
  │                       (antes de que la Policy decida)
  │
  ├─ Gate: ver-ficha-clinica   solo un profesional pasa
  │
  ├─ HistorialController   consulta por mascota_id, NUNCA por tutor_id
  │
  └─ PostgreSQL            la politica RLS compara fila por fila
```

Lo que sale de aquí no lleva datos de otras clinicas porque **la base no
devuelve filas ajenas**, no porque la aplicación se acuerde de filtrarlas.

## El orden que casi se rompe

Tres cosas en este recorrido dependen del orden, y cualquier cambio las rompe
sin avisar:

1. **`ResolverTenant` va antes que `auth`.** Tiene que resolver el usuario por
   su cuenta con el guard, porque todavia no hay sesión disponible en la
   peticion. Ver `api/app/Http/Middleware/ResolverTenant.php`.

2. **La sesión se lee antes de que exista el contexto de clinica.** Por eso
   `sesiones` se lee por una conexión aparte, sin RLS, y solo por identificador
   de sesión. Ver `docs/03` y `App\Services\Auth\AlmacenDeSesion`.

3. **`AuditarFicha` anota antes de que la Policy decida.** Si anotara despues,
   los intentos denegados no existirian, y son justo los que hay que poder
   revisar.

## Donde esta el cache

Solo dos cosas, y las dos justificadas:

| Que | Donde | Por que |
|---|---|---|
| Agenda del dia | Redis, clave por clinica y fecha | Es la pantalla mas cara y mas abierta |
| Descubrimiento OIDC | Redis, un dia | Las claves de Authentik rotan pocas veces |

**La agenda se cachea; la ficha clinica no.** Una ficha se lee pocas veces y
cambia seguido; cachearla daria datos viejos de salud a un profesional. Y
ninguna de las dos se cachea en un proxy: `nuxt.config.ts` pone
`cache-control: no-store` en todo, porque una agenda cacheada por un proxy es
una agenda que se le muestra a quien no es de esa clinica.

## El worker de colas

Es un servicio aparte, no un proceso dentro de la API, por una razon que
importa: **el worker reutiliza la misma conexión entre trabajos**.

Por eso el contexto de clinica viaja con `set_config(..., true)`, que es
alcance de transacción: al terminar el trabajo, PostgreSQL descarta el valor.
Con un `SET` de sesión, un trabajo de una clinica podria leer los datos de
otra. Ver `deploy/api/docker/entrypoint-worker.sh` y `docs/03`.

## Donde esta el código

```
api/app/
├── Enums/          Reglas del dominio: rol, estado de cita, vigencia de vacuna
├── Models/         Tablas y sus relaciones
├── Policies/       Quien puede abrir que
├── Http/
│   ├── Middleware/  ResolverTenant, AuditarFicha, SoloAdministrador
│   └── Controllers/ Agenda, Ficha, Vacunas, Mostrador, Administracion
├── Services/
│   ├── Tenancy/    ContextoTenant: la unica que escribe app.clinica_id
│   ├── Auth/       OIDC contra Authentik
│   ├── Agenda/     CacheAgenda
│   ├── Vacunas/    Calculo de proxima dosis y de vigencia
│   └── Ficha/      Bitacora de accesos
└── Providers/      Registro de Policies y abilities
```

## Lo que NO hay, y por que

| No hay | Por que |
|---|---|
| SQLite | La RLS es de PostgreSQL. Sin ella no hay aislamiento |
| Esquema por clinica | Decidido en el portal de autoservicio: columna + RLS |
| Contrasenas | La identidad la emite Authentik |
| Token en el navegador | La sesión ES la cookie |
| Motor de recetas por regla | El sistema no valida prescripciones |
| Calendarios de vacunacion en el código | Van configurados por la clinica, y verificados por ella |
