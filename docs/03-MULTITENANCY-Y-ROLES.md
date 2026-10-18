# 03 - Multi-tenancy y roles

Este es el documento que explica todo lo demas.

El riesgo número uno no es que falle una pantalla: es que la Clinica Animalitos
vea las fichas de la Clinica Huellitos. Todo lo demas (la interfaz, la
rapidez, el diseno) es menos importante que esto.

## 1. Lo que se eligio, y de donde viene

**Columna `tenant_id` mas Row Level Security de PostgreSQL.**

Este patron no se invento aquí. Esta decidido y documentado en
**`apps/portal-autoservicio/docs/03-MULTITENANCY.md`**, con el analisis de por
que se descarto el esquema por clinica. Ese documento es la referencia; aquí se
repite la decisión y se explica el ajuste a este dominio.

Lo único que cambia es el nombre de la variable de sesión: aquí es
`app.clinica_id`, porque el tenant de esta aplicación es una clinica
veterinaria. El mecanismo es identico.

## 2. El mecanismo

### La función de contexto

```sql
CREATE FUNCTION public.app_clinica_id() RETURNS uuid
    ...
    valor := current_setting('app.clinica_id', true);

    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION 'Falta el contexto de clinica (app.clinica_id). ...'
```

**Lanza excepcion si no hay contexto.** Podria haber devuelto NULL y filtrado
todo en silencio, pero se eligio que falle ruidosamente:

- Con NULL, una consulta sin contexto devuelve cero filas sin aviso. Alguien lo
  "arregla" devolviendo NULL cuando lo necesita, y la puerta queda abierta sin
  que nada lo delate.
- Con excepcion, el error aparece en el registro en el momento, y se sabe
  exactamente que operación se escapo del middleware.

### La política

Para cada tabla de negocio:

```sql
ALTER TABLE public.mascotas ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.mascotas FORCE ROW LEVEL SECURITY;

CREATE POLICY aislamiento_tenant ON public.mascotas
    USING (tenant_id = public.app_clinica_id())
    WITH CHECK (tenant_id = public.app_clinica_id());
```

- `USING` filtra lo que se **lee**.
- `WITH CHECK` impide **escribir** una fila con un `tenant_id` distinto. Sin
  esto, un `INSERT` con el tenant equivocado pasaria la lectura y dejaria una
  fila invisible que nadie podria ver ni borrar.
- `FORCE ROW LEVEL SECURITY` hace que la política aplique **también al dueno de
  la tabla**. Sin FORCE, el dueno la saltaria, y Laravel se conecta como el
  dueno: el aislamiento seria decorativo justo donde se prueba.

### Que no hay, y por que

**No hay `GRANT ... TO PUBLIC`.**

Al activar políticas sigue habiendo que esperar que el dueno tenga privilegios
sobre la tabla, y el dueno los tiene de forma implicita. Lo que hace `FORCE` es
someterlo a las **políticas**, no quitarle permisos.

Y `TO PUBLIC` seria un error grave: en una base multi-clinica, conceder a
PUBLIC significa que cualquier rol conectado a la base (los de respaldo, de
migración, de solo lectura) pasa a tener permiso de escritura sobre datos de
salud. La política lo seguiria limitando por tenant, pero se pierde una capa.

Si alguien usa un rol aparte del dueno para reportar, los permisos se dan a
mano, por rol y con nombre explicito.

## 3. Como viaja el contexto

```php
DB::select('SELECT set_config(?, ?, true)', ['app.clinica_id', $clinicaId]);
```

El `true` final es lo mas importante del archivo: es **alcance de transacción**.
Al confirmar o revertir, PostgreSQL descarta el valor solo.

Con un `SET` normal (alcance de sesión) el valor sobreviviria a la peticion. Y
el **worker de colas reutiliza la misma conexión** para trabajos de clinicas
distintas: un `SET` a sesión significa que un trabajo de una clinica podria
leer los datos de otra.

Ese es exactamente el fallo que este diseno evita, y por eso
`ContextoTenant::establecer()` **lanza excepcion si no esta dentro de una
transacción**.

Solo una clase escribe esa variable:
`App\Services\Tenancy\ContextoTenant`, y solo a traves del middleware
`App\Http\Middleware\ResolverTenant`.

## 4. El punto ciego: la tabla `clinicas`

`clinicas` **no lleva RLS, a propósito.** Se consulta durante el ingreso, cuando
todavia no hay sesión y por lo tanto no hay `app.clinica_id` que poner. Con la
política estandar, la propia función de contexto entraria en recursion.

Es una excepcion consciente con dos compensaciones:

**Compensacion 1: hoy no está activa.** El código lee la **tabla** `clinicas`, no
una vista. `App\Services\Auth\ResolutorDeSesion` resuelve la clínica por
`correlacion_identidad` con un `Clinica::query()->where(...)->first()`, que trae la
fila entera, sin `select()`. Antes del ingreso no hay `app.clinica_id`, y por eso
`clinicas` es la excepción controlada del patrón.

La tabla `clinicas` guarda `id`, `slug`, `nombre`, `zona_horaria`,
`correlacion_identidad` y `activo`. **No tiene columnas de contacto**: ni correo,
ni teléfono, ni RUT, ni razón social. Tampoco hay datos de pacientes ni de mascotas
en esta tabla, así que leerla antes del ingreso no expone fichas clínicas.

La vista `vw_clinicas_publicas` **existe** (migración `2026_10_17_000018`, que
expone `slug`, `nombre` y `activo` de las clínicas activas) y **queda sin uso**: el
código de ejecución nunca la consulta. Solo aparece en
`RowLevelSecurityTest::la_vista_de_clinicas_publicas_no_expone_datos_personales`, que
comprueba que exponga exactamente esas tres columnas. La vista no se eliminó: se
conserva como defensa en profundidad y como respaldo para cuando se decida activar
la protección.

**Pendiente, no comprobado:** activar la vista exige revisar qué espera la
interfaz del ingreso y si la fila completa aporta algo que la vista no traiga. No se
ha podido comprobar contra una base de datos porque no había una disponible, así
que la decisión sigue abierta. Una versión anterior de este documento afirmaba que
la aplicación leía la vista; era falso y se corrige aquí.

**Compensacion 2: el rol de la aplicación no es superusuario ni tiene
BYPASSRLS.** `clinica:verificar-aislamiento` lo comprueba **primero**, antes
que cualquier otra cosa, porque es el fallo mas grave y el mas silencioso: un
rol con `BYPASSRLS` ignora todas las políticas, el sistema sigue funcionando
con normalidad y cada quien ve los datos de las demas clinicas.

**Riesgo residual, dicho con todas las letras:** un `slug` se puede enumerar
antes de autenticarse, porque el slug es público. Un atacante puede descubrir
que la Clinica Animalitos usa el sistema. No puede ver mas que su nombre. Si
incluso eso se considera inaceptable, la alternativa es dejar el campo en
blanco hasta el ingreso y resolver siempre por dominio de correo; queda
anotado como decisión pendiente, no como algo resuelto.

## 5. El problema de orden: leer la sesión antes del contexto

Este es el punto mas delicado de la implementacion, y no aparece en ninguna
aplicación que no tenga sesión en base.

El orden real de una peticion es:

1. El navegador manda la cookie de sesión.
2. Laravel lee la fila de `sesiones` para reconstruir al usuario y **saber de
   que clinica es**.
3. Solo en el paso 3 se puede fijar `app.clinica_id`.

Pero `sesiones` tiene RLS, y `app_clinica_id()` **lanza excepcion** cuando no
hay contexto. O sea: con la política normal, leer `sesiones` en el paso 2
lanzaria excepcion, la sesión no se reconstruiria, y **nadie podria entrar al
sistema**.

### Tres salidas, y por que se eligio esta

**A. Poner el tenant en un claim de la cookie, firmado, y fijar el contexto
desde ahi.** Descartada: obliga a mantener el tenant en dos lugares (la cookie y
la fila) y a resolver que pasa cuando no coinciden.

**B. Quitarle RLS a `sesiones`.** Descartada: `sesiones.payload` guarda el
estado de autenticación. Sin RLS, cualquier rol con acceso a la base puede
leer sesiones de otras clinicas. Es la tabla mas sensible para ese error.

**C. Leer la sesión por una conexión sin RLS, solo por identificador.**
**Elegida.** Es lo que hace `App\Services\Auth\AlmacenDeSesion`.

Por que la conexión sin RLS es segura: la consulta es `SELECT ... WHERE id = ?`,
y el `id` de sesión es una cadena aleatoria de 40 caracteres que genera el
servidor y viaja en una cookie firmada. No hay `tenant_id` que adivinar: sin la
cookie correcta no hay fila que traer, y traer la equivocada exigiria adivinar
un valor de 40 caracteres.

El alcance es minimo: solo se usa para LEER la fila durante el ingreso. En
cuanto se conoce el usuario, `ResolverTenant` fija el contexto y todo lo demas
vuelve a pasar por la conexión normal, con RLS.

El `tenant_id` de la fila de sesión se escribe **despues**, con el contexto ya
fijado y por la conexión normal (`escribirTenantDeLaSesion`). Si se escribiera
por ahi, se tendria un `tenant_id` sin que la RLS lo compruebe, que es
exactamente lo que la RLS existe para impedir.

## 6. Doble filtro dentro de la clinica

La RLS ya garantiza que nada sale del tenant. Dentro hay un segundo filtro, y
en este dominio tiene una forma particular.

### Cuatro roles, y por que mostrador es uno

| | Agenda | Identificacion de mascota | Ficha clinica | Prescribir | Mostrador | Administración |
|---|---|---|---|---|---|---|
| **Profesional** | si | si | **si** | **si** | si | no |
| **Recepción** | si | si | **no** | no | si | no |
| **Mostrador** | no | si | **no** | no | si | no |
| **Administración** | si | si | **no** | no | si | **si** |

Por que el mostrador es un rol aparte de recepción, y no parte de el: una
persona de mostrador que puede ver el padron de mascotas ve el peso de cada
animal de la clinica. Cruzado con las visitas, eso dice que animal esta bajo de
peso cronicamente, que es información de salud. Y quien cobra no necesita verlo
para cobrar.

Mezclarlos obligaria a elegir entre abrir la agenda a quien cobra, o abrir el
historial a quien agenda.

Por que la direccion NO abre fichas clinicas: **la direccion de la clinica no
es un cargo clinico**. Abrirle por cargo la historia de todos los animales es
exactamente lo que la Ley 19.628 pide acotar.

### Las dos preguntas sobre la misma mascota

En este dominio hay DOS preguntas distintas sobre la misma fila de `mascotas`,
y se autorizan por caminos distintos:

| Pregunta | Como se autoriza | Quien |
|---|---|---|
| ¿Puede ver la IDENTIFICACION? | `view` de `MascotaPolicy` | quien opera la clinica |
| ¿Puede abrir la FICHA CLINICA? | ability `ver-ficha-clinica` | solo profesional |

Laravel asocia UNA Policy a cada modelo, así que no podian ser dos metodos de
la misma. Ver `App\Providers\AuthServiceProvider`, que explica por que se
eligio una ability suelta y no un metodo mas.

### El calendario de vacunas es mas abierto, a propósito

`ver-calendario-vacunas` la pueden ver recepción y mostrador; la ficha clinica
no.

Porque "la polivalente vence en marzo" es una **obligacion pendiente de la
clinica**, no un dato de salud del animal. Quien atiende el telefono tiene que
poder verlo para llamar. Lo que no se abre con esa ability es el historial con
sus lotes: para eso esta `ver-ficha-clinica`.

### Un rechazo responde 404, no 403

Un 403 confirmaria que el recurso existe. En una ficha clinica eso importa mas
que en un portal: no se le dice a quien pregunto si esa mascota existe en esta
clinica. Ver `bootstrap/app.php`.

## 7. El aislamiento de la cache

La agenda va a Redis, y eso exige una regla que no es opcional:

**La clave de cache empieza siempre por el `tenant_id`.**

No es cosmetico. La RLS protege la consulta, pero la **cache guarda el
resultado**: si dos clinicas compartieran clave, la clinica B recibiria de
Redis la agenda que se calculo para la clinica A, y la RLS no tendria nada que
decir porque la consulta ya ocurrio hace un rato.

Ver `App\Services\Agenda\CacheAgenda::clave()`.

Y en la interfaz, `nuxt.config.ts` pone `cache-control: no-store` en todas las
rutas: una agenda cacheada por un proxy es una agenda que se le muestra a quien
no es de esa clinica.

## 8. Lo que quedo sin resolver

- **No hay revision de seguridad externa.**
- **No hay pruebas de penetracion.**
- **Las pruebas de aislamiento estan escritas pero NO se ejecutaron**: no habia
  PostgreSQL en el entorno donde se escribio el código. Ver
  [10](10-PRUEBAS-Y-ESTADO.md).
- **La lectura de la sesión por conexión sin RLS** esta resuelta y acotada,
  pero es la pieza mas delicada del sistema. Requiere que el rol configurado en
  `DB_SESIONES_*` tenga SOLO lectura sobre `sesiones`, y que no tenga
  `BYPASSRLS`. Eso hay que revisarlo a mano en cada instalación.
- **No hay migración que niegue el aislamiento si falta una tabla.** Si alguien
  agrega una tabla y la forgets en la lista de la migración, queda sin política
  y nada avisa. La red que lo agarra es
  `SinTablasSinAislamientoTest`, que recorre el catalogo del sistema, y
  `clinica:verificar-aislamiento`.

---

## Los dos roles de PostgreSQL

La base de esta aplicación corre con **dos** roles, no con uno. La separación es
deliberada: es lo que hace que el aislamiento entre clínicas veterinarias
funcione en vez de quedar como decoración.

### Qué es cada rol

| Rol | Nombre | De dónde sale | Para qué sirve | ¿Lo usa la aplicación? |
|---|---|---|---|---|
| **Arranque** | `veterinaria_admin` | `POSTGRES_USER` | Crear la base `veterinaria` y las tareas administrativas del despliegue | **Nunca** |
| **Aplicación** | `veterinaria` | `DB_USERNAME`, creado por `deploy/postgres/inicializar-rol.sh` | Todo el tráfico de la API | **Sí, este y solo este** |

El rol de aplicación **no** lleva sufijo `_app`: se llama `veterinaria`, igual que
la base.

El rol de arranque no es una convención del estudio: la imagen oficial de
PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`, así que ese rol nace
**superusuario**.

### Por qué con un solo rol no servía

La documentación de PostgreSQL 18 lo dice de forma textual:

> *«Superusers and roles with the `BYPASSRLS` attribute always bypass the row
> security system when accessing a table. Table owners normally bypass row
> security as well, though a table owner can choose to be subject to row
> security with `ALTER TABLE ... FORCE ROW LEVEL SECURITY`.*

La segunda frase es la clave. `FORCE ROW LEVEL SECURITY` sujeta al **dueño** de
la tabla, pero **no al superusuario**. Mientras la aplicación conectara con el
rol de `POSTGRES_USER`, ninguna política de este documento se iba a aplicar: una
clínica veterinaria habría visto las fichas de los pacientes y los tratamientos
de las demás, sin error y sin aviso.

### Los atributos van todos escritos

`deploy/postgres/inicializar-rol.sh`, montado en
`/docker-entrypoint-initdb.d/`, crea el rol con `NOSUPERUSER`, `NOCREATEDB`,
`NOCREATEROLE`, `NOINHERIT` y `NOBYPASSRLS` **explícitos todos**, aunque
`NOBYPASSRLS` ya sea el valor por omisión.

Se escriben igual a propósito: un rol verificado no debería depender de un valor
por omisión que una versión nueva puede cambiar. El script también falla
ruidosamente si falta `POSTGRES_DB`, `APP_DB_ROLE` o `APP_DB_PASSWORD`.

### Por qué el rol de aplicación es dueño de la base

Justo después de crearlo, el script corre:

```sql
ALTER DATABASE veterinaria OWNER TO veterinaria;
```

Las migraciones de Laravel se ejecutan con el rol de la aplicación y necesitan
**crear tablas**. La única forma de darles ese permiso sin convertirlos en
superusuario es que ese rol sea el dueño de la base. Desde PostgreSQL 15 el
esquema `public` pertenece al dueño de la base, así que con esto el rol ya
puede crear tablas ahí.

Y este es el punto exacto del diseño: al ser el dueño, **las tablas quedan a su
nombre**, y por eso el `FORCE ROW LEVEL SECURITY` que usan las tablas sí lo
sujeto. Ser dueño no es saltarse la política; es lo que hace que la política lo
alcance. Un superusuario la saltearía siempre.

### Cómo se comprueba

```
php artisan clinica:verificar-aislamiento
php artisan clinica:verificar-aislamiento --clinica
```

El prefijo del comando es `clinica:`, no `veterinaria:`. Es el mismo prefijo que
usan las otras dos aplicaciones clínicas de Hefesto, `clinica-salud` y
`clinica-dental`. Son bases distintas, así que no hay conflicto, pero es
justamente el tipo de nombre compartido que hace que haya que leer de qué
aplicación se está hablando.

La opción `--clinica` recibe la correlación de una clínica existente para usar
en la prueba; si no se indica, se crean clínicas temporales.

A diferencia de las otras dos aplicaciones clínicas, este comando **sí**
consulta `pg_roles` sobre el rol de la conexión y falla con código 1 si ese rol
es superusuario o tiene `BYPASSRLS`. Después revisa que la RLS esté activa y
forzada en las tablas de negocio.

**Este comando no se ha ejecutado.** En el equipo donde se escribió este cambio
no había PostgreSQL ni Docker. El diseño es correcto y está sostenido en la
documentación oficial, pero nadie lo ha corrido. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).
