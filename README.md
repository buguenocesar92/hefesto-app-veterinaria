# VeterinariaHefesto

Aplicación propia de **Hefesto Studio** para clinicas veterinarias de tamano
pequeno o mediano en Chile. Tutores y mascotas, agenda clinica con bloque de
vacunacion, ficha clinica, control de vacunas y venta en mostrador.

> **Este proyecto es software original de Hefesto.** No es un despliegue de una
> herramienta de terceros ni una reubicacion de código ajeno. Se uso Laravel,
> Nuxt, Socialite, `firebase/php-jwt`, PostgreSQL, Redis y Authentik como
> librerias, que es lo normal: lo que se escribio aquí es la aplicación.

---

## Aviso que hay que leer antes que nada

> **Esto NO esta listo para atender animales reales.**
>
> Las prescripciones veterinarias y los esquemas de vacunacion tienen
> implicancias que **este sistema no reemplaza**: no valida una prescripcion, no
> verifica un intervalo de vacunacion contra ninguna fuente oficial, y no
> comprueba un registro profesional.
>
> El sistema **si** registra y muestra datos personales de tutores e historiales
> de salud de animales. Manejarlos exige acuerdos de tratamiento de datos,
> designacion de responsable, protocolos de incidentes, capacitacion y control
> fisico del acceso.
>
> Poner esto en producción sin esas garantias es un riesgo para el animal, para
> el cliente de la clinica y para Hefesto Studio. Lo que si y lo que no esta
> hecho esta separado, sin adornos, en
> **[docs/07-SEGURIDAD-Y-LEY-19628.md](docs/07-SEGURIDAD-Y-LEY-19628.md)** y en
> **[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md)**.

## Estado

**Código escrito, sin ejecutar.**

En el entorno donde se escribio esto no hay PHP, ni Composer, ni Docker. **No se
corrio ni una sola prueba, ni las migraciones, ni una construccion de las
imagenes.** Nada de lo que hay aquí esta probado por ejecución, salvo lo que
[docs/10](docs/10-PRUEBAS-Y-ESTADO.md) dice textualmente que si.

## Que problema resuelve

Una clinica veterinaria pequena gestiona hoy las fichas, las vacunas y las ventas
en papel o en un sistema que fue pensado para medicina humana. El resultado son
fichas perdidas, una agenda que no distingue una consulta de una vacuna, y lo mas
grave: **historiales clinicos repartidos en lugares donde cualquiera los ve**.

Un historial medico de mascota identifica a una persona y a su domicilio. Este
sistema lo trata como lo que es.

## Las tres decisiones que explican el resto

### 1. El aislamiento entre clinicas lo impone PostgreSQL, no la aplicación

Columna `tenant_id` mas Row Level Security, con `WITH CHECK`, `FORCE ROW LEVEL
SECURITY` y el contexto viajando con `set_config(..., true)`.

El patron no se invento aquí: esta decidido y documentado en
`apps/portal-autoservicio/docs/03-MULTITENANCY.md`, y se reutilizo tal cual.

Que el contexto sea de **alcance de transacción** no es un detalle: el worker de
colas reutiliza la misma conexión entre trabajos, y con un `SET` de sesión un
trabajo podria leer los datos de otra clinica.

Ver **[docs/03](docs/03-MULTITENANCY-Y-ROLES.md)**.

### 2. El historial cuelga de la mascota, nunca del tutor

Este es el dominio, y no es un detalle de implementacion.

Un tutor puede tener tres mascotas. **Ese es el caso normal, no la excepcion.**
Si el historial se guardara o se consultara por tutor, las tres historias se
mezclarian en una sola linea del tiempo: la radiografia del perro aparece pegada
al diagnóstico de la gata, el peso de una se compara con la temperatura de la
otra, y al recetar se elige el medicamento que se le dio al otro animal.

**Ninguno de esos animales esta enfermo. Lo que se mezclo son los registros.**

Ninguna tabla clinica tiene `tutor_id`. No es una convencion: es lo que hace
imposible la mezcla por escritura.

### 3. La proxima dosis de una vacuna se guarda, no se recalcula

Igual que el control de vencimientos de la flota de vehiculos de Hefesto.

`vacunas.proxima_dose_en` se calcula **una vez**, al registrar la aplicación, y
se guarda. No se vuelve a tocar, ni aunque se edite el esquema.

- Recalcular contra el esquema actual moveria hacia atras las fechas de una
  dosis ya aplicada, y dejaria desmentido un certificado ya impreso.
- Recalcular contra "hoy" haria que el resultado cambiara solo con el paso del
  tiempo, y el dia que mas importa el sistema podria decir una cosa y ayer otra.

Lo que **si** se deriva en cada lectura es la vigencia: si esa fecha ya paso.
Separar lo guardado de lo derivado es lo que evita que el sistema siga diciendo
que una mascota esta al dia dos anos despues.

Ver **[docs/02](docs/02-MODELO-DE-DATOS.md)**.

## El bloque de vacunacion

En una clinica veterinaria tipo, la atención mas frecuente del dia no es una
consulta: **es una vacuna**. Es rapida, se hace en cadena y es la que llena la
agenda y la que da la plata.

`tipos_atencion.bloque_vacunacion` la separa del resto. Mezclarlas produce el
problema de siempre: la gente va por una vacuna y espera veinte minutos a que se
desocupe el consultorio de al lado.

## Los cuatro roles

| | Agenda | Identificacion | Ficha clinica | Prescribir | Mostrador | Administración |
|---|---|---|---|---|---|---|
| **Profesional** | si | si | **si** | **si** | si | no |
| **Recepción** | si | si | **no** | no | si | no |
| **Mostrador** | no | si | **no** | no | si | no |
| **Administración** | si | si | **no** | no | si | **si** |

El mostrador es un rol **aparte** de recepción porque quien cobra no necesita
ver el historial: y ver el padron de mascotas ya expone el peso de cada animal
de la clinica, que cruzado con las visitas dice quien esta bajo de peso.

La direccion **no** abre fichas clinicas: la direccion de la clinica no es un
cargo clinico.

## Documentos

| | |
|---|---|
| [01 Arquitectura](docs/01-ARQUITECTURA.md) | componentes y recorrido de una peticion |
| [02 Modelo de datos](docs/02-MODELO-DE-DATOS.md) | tablas, las tres reglas estructurales y las vacunas |
| [03 Multi-tenancy y roles](docs/03-MULTITENANCY-Y-ROLES.md) | **el documento que explica todo lo demas** |
| [04 Instalación](docs/04-INSTALACION.md) | de cero a la primera pantalla |
| [05 Configuración](docs/05-CONFIGURACION.md) | cada variable y por que |
| [06 Primer paciente](docs/06-PRIMER-PACIENTE.md) | la sesión completa, paso a paso |
| [07 Seguridad y Ley 19.628](docs/07-SEGURIDAD-Y-LEY-19628.md) | controles, lo que falta y **el aviso** |
| [08 Respaldos](docs/08-RESPALDOS.md) | respaldo, restauración, y la trampa de `pg_dump` |
| [09 API](docs/09-API.md) | rutas, convenciones y limites |
| [10 Pruebas y estado](docs/10-PRUEBAS-Y-ESTADO.md) | **que se probo y que no** |

## Carpeta

```
apps/veterinaria/
├── api/        Laravel 13: Policies, abilities, migraciones, RLS, auditoria
├── web/        Nuxt 4 con render en el servidor, y el BFF que reenvia la cookie
├── deploy/     Dockerfiles, compose de desarrollo y produccion, Caddy, entrypoints
├── docs/       los diez documentos de arriba
├── herramientas/ scripts que no dependen de PHP ni de Node
└── .env.example
```

## Modulos

1. **Tutores y mascotas**: ficha del tutor con sus contactos, y de cada mascota
   con especie, raza, fecha de nacimiento, peso y microchip. **Un tutor puede
   tener varias mascotas**, y sus historiales no se mezclan.
2. **Agenda clinica**: por profesional, con duracion por tipo de atención y un
   **bloque de vacunacion** aparte. La base impide que dos citas del mismo
   profesional se solapen.
3. **Ficha clinica**: historial, consultas, controles y prescripciones, **de
   una mascota**. Cada acceso queda registrado, permitido o denegado.
4. **Vacunas**: esquemas configurables por la clinica, con la proxima dosis
   guardada y aviso de atraso.
5. **Mostrador**: venta de productos, con el stock descontado en la misma
   transacción que el comprobante.
6. **Administración**: profesionales, tipos de atención, productos, precios,
   usuarios y esquemas de vacunacion.

## Lo que el sistema NO hace

| No hace | Quien lo hace |
|---|---|
| Validar una prescripcion | El profesional que la firma |
| Verificar un intervalo de vacunacion | La clinica, con alguien que sepa |
| Verificar un registro profesional | Hoy nadie. **No implementado** |
| Validar que un producto exija receta | La clinica y la normativa sanitaria |
| Decidir si un diagnóstico es correcto | El profesional que atiende |

Un sistema que "validara" las decisiones clinicas les daria a los profesionales
una sensacion de aval que no tiene. Este prefiere no hacerlo y decirlo.

## Como se levanta

```bash
cp api/.env.example api/.env
cp .env.example .env
cp web/.env.example web/.env
# complete los valores (docs/04 y docs/05)

docker compose -f deploy/docker-compose.prod.yml up -d --build
docker compose -f deploy/docker-compose.prod.yml run --rm api php artisan key:generate
docker compose -f deploy/docker-compose.prod.yml exec api php artisan migrate --force
docker compose -f deploy/docker-compose.prod.yml exec api php artisan db:seed --force

# ESTE PASO NO SE PUEDE SALTAR:
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan clinica:verificar-aislamiento
```

En desarrollo: `docker compose -f deploy/docker-compose.dev.yml up --build`
(interfaz en <http://localhost:3000>, API en <http://localhost:8000>).

## Verificación

```bash
python herramientas/verificar_caracteres.py     # CJK, cirilicos, emoji
python herramientas/verificar_estructura.py    # sintaxis PHP y referencias
bash -n deploy/api/docker/entrypoint.sh         # sintaxis de shell
bash -n deploy/api/docker/entrypoint-worker.sh
```

Ninguno necesita PHP ni Docker. Que se ejecuto y que **no** esta en
[docs/10](docs/10-PRUEBAS-Y-ESTADO.md).

## Lo que falta para producción

En el [07](docs/07-SEGURIDAD-Y-LEY-19628.md) hay la lista completa. Lo mas
urgente:

1. **Correr las pruebas.** estan escritas, no se ejecutaron.
2. `clinica:verificar-aislamiento` en el servidor, en verde.
3. **Doble factor de autenticación** en Authentik.
4. **Revision juridica**: textos, aviso de privacidad, marco de la prescripcion
   veterinaria (Ley 20.584).
5. **Confirmar los esquemas de vacunacion** con un profesional veterinario.
6. Cifrado de los respaldos, con una restauración probada.
7. Prueba de penetracion por terceros.
