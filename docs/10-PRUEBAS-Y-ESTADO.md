# 10 - Pruebas y estado

Este es el primer documento que hay que leer antes de opinar sobre el código.

## Estado

**Código escrito, sin ejecutar.**

En el entorno donde se escribio esta aplicación **no hay PHP, ni Composer, ni
Docker**. Por lo tanto:

- **No se corrio ni una sola prueba de PHPUnit.** No hay un "verde" que pueda
  citarse.
- **No se corrieron las migraciones** contra una PostgreSQL real.
- **No se construyo ninguna imagen** de Docker.
- **No se instalaron ni ejecutaron las pruebas de la interfaz.**
- **No se hizo una prueba de penetracion.**

Lo que **si** se ejecutaron de verdad esta en la seccion siguiente. Nada mas.
En ningun momento de este repositorio hay una afirmacion de que algo paso si no
esta en esa tabla.

## Lo que si se ejecuto

| Que | Resultado |
|---|---|
| `herramientas/verificar_caracteres.py` (dos pasadas) | Sin CJK, cirilicos, hebreos, arabes, griegos ni emojis. Salida 0 |
| `herramientas/verificar_estructura.py` | 78 clases declaradas, 104 archivos PHP sin llaves desbalanceadas, ninguna referencia rota. Salida 0 |
| `bash -n` sobre `entrypoint.sh` | Sintaxis valida. Salida 0 |
| `bash -n` sobre `entrypoint-worker.sh` | Sintaxis valida. Salida 0 |
| Lectura de XML de `phpunit.xml` | Valido. **Encontro y se corrigio un defecto real**, ver abajo |
| Lectura de JSON de `composer.json`, `package.json`, `tsconfig.json` | Sintaxis valida |
| Verificación de versiones en registros oficiales | Ver tabla de versiones |
| Verificación de etiquetas en Docker Hub | Las 6 etiquetas existen |
| Simulacion en Python del algoritmo de fechas | Los 8 casos coinciden con lo que afirman las pruebas de PHP |

### El simulador del algoritmo de fechas

`CalculadorDeProximaDosis::sumarMeses` es lógica pura. Para no depender de PHP,
se reimplemento en Python y se comprobaron los casos limite contra lo que
afirman las pruebas de PHPUnit:

| Caso | Resultado |
|---|---|
| 15 ene + 3 meses | 15 abr |
| 31 ene + 1 mes | 28 feb (2026, no bisiesto) |
| 31 ene + 1 mes | 29 feb (2028, bisiesto) |
| 30 nov + 1 mes | 30 dic |
| 31 dic + 1 mes | 31 ene del ano siguiente |
| refuerzo de 12 meses sobre intervalo de 3 | manda el refuerzo |
| edad minima de 8 meses | 20 may + 8 m = 20 ene |

**Lo que esto NO prueba:** que el código PHP haga lo mismo. La simulacion
comprueba la *idea* del algoritmo; el que la comprueba es PHP, ejecutando
`CalculadorDeProximaDosisTest`, que no se ha corrido.

### Tres defectos reales encontrados al verificar

Se anotan porque son el argumento de que las verificaciones sirven para algo:

1. **`sumarMeses` estaba mal.** Usaba el mes **original** al fijar el dia, así
   que la segunda llamada a `2026-02-28 + 1 mes` devolvia `2026-02-28` en vez de
   `2026-03-28`. Se detecto al comparar la implementacion con las pruebas, antes
   de correr nada. Corregido usando `daysInMonth`.

2. **Una expectativa de prueba estaba mal.** La prueba decia que 30 de
   noviembre mas un mes cae el 31 de diciembre. Caen el 30: el dia 30 **si**
   existe en diciembre, así que no hay ajuste que hacer. El algoritmo estaba
   bien; la prueba estaba mal. Corregida la prueba.

3. **`phpunit.xml` era XML invalido.** El comentario de cabecera traia
   `php artisan test --testsuite=...`, y XML **prohibe el doble guion dentro de
   un comentario**. PHPUnit no habria podido leer su propia configuración, y
   el fallo habria aparecido como "configuración no encontrada" al correr las
   pruebas. Se detecto al parsear el archivo, y se corrigio reescribiendo el
   comentario sin los guiones.

Los dos primeros habrian pasado inadvertidos. El tercero habria hecho fallar
toda la suite de pruebas.

## Versiones verificadas

Consultadas contra el registro oficial de cada proyecto.

| Paquete | Versión | Fuente |
|---|---|---|
| `laravel/framework` | `v13.37.0` | `repo.packagist.org/p2/laravel/framework.json` |
| `laravel/socialite` | `v5.31.0` | `repo.packagist.org/p2/laravel/socialite.json` |
| `firebase/php-jwt` | `v7.2.1` | `repo.packagist.org/p2/firebase/php-jwt.json` |
| `predis/predis` | `v3.7.0` | `repo.packagist.org/p2/predis/predis.json` |
| `fakerphp/faker` | `v1.24.1` | `repo.packagist.org/p2/fakerphp/faker.json` |
| `phpunit/phpunit` | `13.4.3` | `repo.packagist.org/p2/phpunit/phpunit.json` |
| `nuxt` | `4.6.2` | `registry.npmjs.org/nuxt/latest` |
| `vue` | `3.5.44` | `registry.npmjs.org/vue/latest` |
| `vue-router` | `5.4.0` | `registry.npmjs.org/vue-router/latest` |
| `postgres:18.6` | existe | Docker Hub |
| `redis:8.8.3-alpine` | existe | Docker Hub |
| `php:8.4-fpm-alpine3.24` | existe | Docker Hub |
| `node:24-alpine3.24` | existe | Docker Hub |
| `nginx:1.29-alpine` | existe | Docker Hub |
| `caddy:2.11.7-alpine` | existe | Docker Hub |
| **Ley 19.628** | norma citada | **NO VERIFICADA.** El sitio del BCN carga con JavaScript y no se pudo leer |
| **Ley 20.584** (prescripcion veterinaria) | norma citada | **NO VERIFICADA** |
| **Esquemas de vacunacion** | — | **NO VERIFICADOS.** No se consulto ninguna fuente oficial |

Lo que **si** se verifico contra documentacion oficial, en la aplicación
heredada del portal de autoservicio de Hefesto: las rutas de descubrimiento OIDC
de Authentik, y la documentacion de `laravel/socialite` v5.31 sobre la ausencia
de un proveedor OIDC generico.

## Pruebas escritas

**Ninguna se ejecuto.** Estan escritas.

### Suite `Aislamiento`

| Prueba | Que comprueba |
|---|---|
| `HistorialNoSeMezclaEntreMascotasDelMismoTutorTest::la_ficha_de_una_mascota_no_trae_el_historial_de_la_otra_del_mismo_tutor` | **La prueba central del dominio.** Un tutor con dos mascotas: la ficha de una no contiene el texto del diagnóstico de la otra, ni su nombre |
| `::el_historial_de_la_otra_mascota_sigue_existente_y_no_se_perdio` | que el aislamiento no se logro borrando datos |
| `::las_vacunas_de_una_mascota_no_se_mezclan_con_las_de_la_otra` | los lotes de vaccine no se cruzan |
| `::las_prescripciones_de_una_mascota_no_se_mezclan_con_las_de_la_otra` | los medicamentos recetados no se cruzan |
| `AislamientoEntreClinicasTest::una_clinica_no_ve_las_mascotas_de_otra` | y tampoco por identificador |
| `::una_clinica_no_ve_la_historia_clinica_de_otra` | consultas, por texto |
| `::una_clinica_no_ve_las_vacunas_de_otra` | |
| `::una_clinica_no_ve_los_tutores_de_otra` | datos personales de una persona |
| `::sin_contexto_la_consulta_falla_y_no_devuelve_filas` | falla cerrado |
| `::no_se_puede_escribir_una_fila_de_otra_clinica` | `WITH CHECK` |
| `::el_contexto_desaparece_al_terminar_la_transaccion` | `set_config(..., true)` |
| `::fijar_el_contexto_fuera_de_una_transaccion_es_error` | lanza excepcion |
| `::la_funcion_de_contexto_devuelve_la_clinica_fijada` | |
| `RowLevelSecurityTest::todas_las_tablas_de_negocio_tienen_rls_activado_y_forzado` | las 16 tablas |
| `::cada_tabla_protegida_tiene_la_politica_de_aislamiento` | |
| `::la_politica_tiene_using_y_with_check` | |
| `::la_funcion_de_contexto_falla_sin_variable` | |
| `::la_vista_de_clinicas_publicas_no_expone_datos_personales` | **exactamente tres columnas** |
| `::la_vista_de_clinicas_publicas_no_trae_clinicas_inactivas` | |
| `SinTablasSinAislamientoTest::ninguna_tabla_con_tenant_id_queda_sin_aislamiento` | **recorre el catalogo del sistema.** Es la red que agarra el error de agregar una tabla y olvidar la lista |

### Suite `Permisos`

| Prueba | Que comprueba |
|---|---|
| `RolesYFichaClinicaTest::solo_el_profesional_abre_la_ficha_clinica` | y ni la direccion la abre |
| `::recepcion_ve_la_identificacion_pero_no_el_historial` | las dos preguntas separadas |
| `::solo_el_profesional_prescribe_y_aplica_vacunas` | |
| `::el_calendario_de_vacunas_es_mas_abierto_que_la_ficha_clinica` | y aun así no abre el historial |
| `::los_cuatro_roles_tienen_las_capacidades_que_corresponden` | las 4 filas de la tabla de roles |
| `::desactivar_un_usuario_se_guarda_y_el_ingreso_lo_rechaza` | **parcial:** solo guarda la marca, ver pendientes |
| `BitacoraInmutableTest::un_registro_no_se_puede_modificar` | `LogicException` |
| `BitacoraInmutableTest::un_registro_no_se_puede_borrar` | `LogicException` |

### Suite `Unit` (lógica pura, sin base de datos)

| Prueba | Que comprueba |
|---|---|
| `CalculadorDeProximaDosisTest` | 9 casos de aritmetica de meses, incluidos bisiesto y fin de mes |
| `CalculadorDeVigenciaTest` | vigente, por vencer, atrasada, sin proxima, el dia exacto, la hora del dia, la ventana configurable |
| `EnumsTest` | los cuatro roles, los estados finales de cita, que "vigente" NO sea un estado guardable, que exista "no informado" |

## Como correrlas

```bash
cd api
composer install
cp .env.example .env
php artisan test --testsuite=Aislamiento
php artisan test --testsuite=Permisos
php artisan test --testsuite=Unit
php artisan test                       # todo
```

**No usar SQLite.** Las pruebas de aislamiento comprueban que PostgreSQL se
niega a devolver filas ajenas. En SQLite pasarian sin comprobar nada, que es
peor que no tenerlas. `phpunit.xml` fija `DB_CONNECTION=pgsql` y
`DB_DATABASE=veterinaria_test`.

En el servidor, antes de recibir animales reales:

```bash
php artisan clinica:verificar-aislamiento
```

## Pendiente, declarado

### Verificaciones técnicas

| Pendiente | Por que |
|---|---|
| **Ejecutar las pruebas** | **no hay PHP ni Docker en este entorno** |
| **Ejecutar las migraciones** | idem |
| **Construir las imagenes** | idem |
| `npm run build` de la interfaz | idem |
| Tipo de la interfaz (`nuxt typecheck`) | idem |
| Pruebas de la interfaz | **no hay ninguna escrita** |
| Verificar que la API arranca con el entrypoint | idem |

### Integraciones sin verificar

| Pendiente | Estado |
|---|---|
| **Authentik**: flujo completo de ingreso | **Implementado pero no ejecutado.** Requiere un Authentik real. El código viene del portal de autoservicio, donde si se ejecuto |
| **Sesión con RLS**: el camino completo del guard | **NO PROBADO.** Es la pieza mas delicada del sistema. Ver [03](03-MULTITENANCY-Y-ROLES.md), seccion 5 |
| **Prescripcion veterinaria**: marco normativo | **NO VERIFICADO.** Ley 20.584 y su reglamento |
| **Esquemas de vacunacion** | **NO VERIFICADOS.** Dependen de especie, edad, fabricante y normativa |
| **Registro profesional** | **NO IMPLEMENTADO.** `registro_profesional` es texto libre |
| **Productos que exigen receta** | **NO IMPLEMENTADO.** `requiere_receta` es un aviso, no una validación |
| **Correo**: avisos de vacunas por vencer | **NO IMPLEMENTADO.** No hay correo saliente |
| **Facturacion** | **NO IMPLEMENTADA.** El total es un dato |
| **CIE-10 o equivalente veterinario** | **NO IMPLEMENTADO.** Los diagnosticos son texto |
| **Auditoria de inventario** | **NO IMPLEMENTADA.** Editar stock a mano no deja rastro |
| **Purgado de la bitacora** | **NO IMPLEMENTADO.** La tarea esta declarada en `routes/console.php` como gancho, sin cuerpo |
| **Alertas por volumen de denegados** | **NO IMPLEMENTADAS.** Los umbrales existen en la configuración; el aviso no |
| **Historial de cambios** | No hay optimistic locking: dos pestanas pueden pisarse una consulta |
| **Paginacion** | La agenda limita a 100 dias sin paginar |
| **Ley 19.628**: estado de las reformas posteriores | **NO VERIFICADO** |

### Decisiones pendientes de confirmar con una clinica real

Estas no son decisiones técnicas: son de producto, y conviene resolverlas con
alguien que trabaje en una clinica antes de construir sobre ellas.

1. **¿La recepción necesita ver el peso de la mascota?** Hoy ve toda la ficha de
   identificacion, y el peso es un dato clinico. Se decidio incluirlo porque
   quien tiene el animal delante necesita el peso para dispensar, pero puede
   que no sea lo que la clinica quiere.
2. **¿El mostrador necesita ver el padron de mascotas completo?** Hoy puede.
   Si con buscar por tutor bastara, se podria acotar.
3. **¿El calendario de vacunas deberia abrirse al mostrador?** Hoy se abre: la
   justificacion es que "vence en marzo" es una obligacion de la clinica, no un
   dato de salud. Es discutible.
4. **¿Cuanto tiempo se conserva la bitacora?** Hoy 3650 dias, que es un dato
   por omision **sin verificar**.
5. **¿Que pasa con las recetas cuando el animal muere?** Hoy quedan. No hay
   regla, y probablemente haga falta una.
6. **¿Un animal puede tener dos tutores?** Hoy no. Un tutor responsable.
   En la práctica hay Separaciones y animals que quedan a medias entre dos
   personas.

## Un defecto conocido, asumido

**El indice único de vacunas no protege cuando `esquema_id` es NULL.**

```sql
UNIQUE (mascota_id, esquema_id, fecha_aplicacion)
```

En PostgreSQL los NULL se consideran distintos entre si, así que dos
aplicaciones de una misma vacuna **sin esquema cargado** y en la misma fecha
podrian quedar duplicadas.

Se acepta a propósito: es preferible el aviso del registro a un indice que exija
inventar un esquema para poder guardar un hecho que ocurrio. Esta documentado en
la migración y **no hay una prueba que lo cubra**, porque todavia no hay servicio
de registro que lo detecte. Ver el pendiente de arriba.

## Como se sabe si esto sirve

Antes de ponerlo delante de un animal:

1. `php artisan test` **completo en verde**, con PostgreSQL de verdad.
2. `php artisan clinica:verificar-aislamiento` en el servidor, en verde.
3. Los siete pasos del final del [06](06-PRIMER-PACIENTE.md), con los cuatro
   roles.
4. **Revision juridica** del marco de la prescripcion veterinaria y de los
   textos.
5. **Confirmacion de los esquemas de vacunacion** por un profesional
   veterinario.
6. **Prueba de penetracion** por terceros.
7. Un **ensayo de restauración** de respaldo.
8. **Doble factor** en Authentik.
9. Revision del tiempo de retencion de la bitacora.

Sin los nueve, no. Ver el [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Los dos roles de PostgreSQL: escritos, no ejecutados

La separación entre el rol de arranque (`veterinaria_admin`) y el rol de la
aplicación (`veterinaria`) está escrita en el `compose`, en
`deploy/postgres/inicializar-rol.sh` y en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md). **No se ha ejecutado
ninguna vez.**

En el equipo donde se escribió este cambio no había PostgreSQL ni Docker. Queda
pendiente:

1. Levantar el `compose` con el volumen de PostgreSQL vacío y confirmar que el
   rol `veterinaria` se crea sin error.
2. Confirmar con `SELECT rolname, rolsuper, rolbypassrls FROM pg_roles` que el
   rol de la conexión no es superusuario ni salta la RLS.
3. Correr `php artisan clinica:verificar-aislamiento` y confirmar que sale con
   código 0.
4. Probar el escenario que motiva todo el cambio: una clínica veterinaria
   intentando ver los tratamientos de un paciente de otra.

Lo único verificado es estático: `bash -n` pasa sobre el script y el `compose`
es YAML válido, ambas cosas comprobadas por `tools/validar_apps.py`. El
razonamiento se apoya en la documentación oficial de PostgreSQL 18, no en una
ejecución.
