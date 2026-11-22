# Estado real de `api/`

Este documento reemplaza, para `api/`, la frase «código escrito, sin ejecutar»
de [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md). Todo lo que dice salió de
una ejecución; lo que no se ejecutó está marcado como **no verificado**.

- **Medido el:** 2026-11-21, sobre `fix/imagen-y-tests` en `3ce87a0` (mas el
  arreglo de G1 de este mismo cambio).
- **Con:** PHP 8.4.28 (etapa `pruebas` de `deploy/api/Dockerfile`),
  PostgreSQL 18.6, Laravel 13.42.0, PHPUnit 13.4.7 (versiones de
  `api/composer.lock`).
- **Comando único:** `bash herramientas/probar.sh` (instala, migra en un
  PostgreSQL desechable, corre la suite y la compara con
  `api/tests/fallos-conocidos.txt`). La imagen de produccion se comprueba con
  `bash herramientas/verificar_imagen.sh`.

## Resumen

| Pregunta | Respuesta |
|---|---|
| ¿Instala? | Sí. `composer.json` resolvía tal cual: las versiones declaradas existen. Faltaban dos dependencias de desarrollo. |
| ¿Arranca? | Sí, después de agregar el esqueleto de Laravel y registrar los proveedores. 57 rutas. |
| ¿Migra? | Sí, las 19 migraciones, después de corregir 3. |
| ¿El aislamiento por RLS funciona? | Sí: las 20 pruebas de la suite `Aislamiento` menos una pasan, y `clinica:verificar-aislamiento` sale con 0. |
| ¿Alguien puede iniciar sesión? | **No.** Ver G2 y G6. |
| ¿La suite pasa? | 82 de 92. Los 10 que no pasan tienen 6 causas conocidas (G2 a G7). |

## Qué hubo que corregir para que arrancara

Nada de esto es lógica de negocio: es lo que impedía instalar, migrar o cargar
la suite.

| # | Qué faltaba o estaba roto | Dónde | Síntoma literal |
|---|---|---|---|
| 1 | Esqueleto de Laravel: `artisan`, `public/index.php`, `storage/`, `bootstrap/cache/` | `api/` | `Could not open input file: artisan` |
| 2 | `composer.lock` | `api/` | cada instalación resolvía versiones distintas |
| 3 | `bootstrap/providers.php`: los dos proveedores de `app/Providers` no se registraban | `api/bootstrap/` | ninguna *ability* ni *policy* definida; el manejador de sesión propio nunca se instalaba |
| 4 | `DB::statement` dentro del cierre de `Schema::create` (corre antes de que exista la tabla) | `2026_10_17_000006_crear_tabla_tipos_atencion.php`, `2026_10_17_000013_crear_tabla_productos.php` | `SQLSTATE[42P01]: relation "tipos_atencion" does not exist` |
| 5 | Dos sentencias en un solo `DB::statement` | `2026_10_17_000007_crear_tabla_citas.php` | `SQLSTATE[42601]: cannot insert multiple commands into a prepared statement` |
| 6 | Faltaba `mockery/mockery` (lo usa `artisan migrate` dentro de las pruebas) y `nunomaduro/collision` (lo exige `php artisan test`) | `api/composer.json` | `Class "Mockery" not found`; `Command "test" is not defined` |
| 7 | Las pruebas no limpiaban la base entre sí | `api/tests/TestCase.php` | `duplicate key value violates unique constraint "clinicas_slug_unique"`: cada prueba dejaba sus filas y la siguiente chocaba |
| 8 | La suite `Feature` repetía los archivos de `Aislamiento` y `Permisos` | `api/phpunit.xml` | 6 avisos `Cannot add file … as it was already added to test suite "Feature"` |
| 9 | `verificar_referencias.py` recorría `api/vendor` | `herramientas/` | falso positivo en cuanto se instalan dependencias |
| 10 | `sumarMeses` llamaba a `addMonthsNoOverflow()`, que es API de Carbon, sobre un `DateTimeImmutable` | `api/app/Services/Vacunas/CalculadorDeProximaDosis.php:95` | `Call to undefined method DateTimeImmutable::addMonthsNoOverflow()` |
| 11 | Las tablas de la cola de Laravel (`jobs` y `failed_jobs`) no tenían migración: con `QUEUE_CONNECTION=database` (el valor por defecto de Laravel) el worker no tenía dónde leer los trabajos, y `failed_jobs` falta con cualquier conexión, porque Laravel registra ahí los trabajos fallidos | `api/database/migrations/2026_11_22_000019_crear_tabla_colas.php` | `SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "jobs" does not exist`, cada 3 s en el registro del worker |
| 12 | `AUTHENTIK_SCOPES` sin comillas en el ejemplo de entorno: el valor tiene espacios y phpdotenv rechaza el archivo entero, así que no cargaba ningún comando de artisan (tampoco `key:generate`) | `api/.env.example:118` | `Failed to parse dotenv file. Encountered unexpected whitespace at [openid profile email]` |

## Resultado de la suite

| | Pruebas | Pasan | No pasan |
|---|---:|---:|---:|
| Suite heredada | 82 | 72 | 10 |
| Casos frontera de la suma de meses (`SumarMesesCasosFronteraTest`) | 10 | 10 | 0 |
| **Total** | **92** | **82** | **10** |

Ninguna prueba se omite. Las 10 que no pasan están, una por línea, en
`api/tests/fallos-conocidos.txt`.

## Fallos conocidos por causa

No se arreglaron: se cuentan. Cada grupo es **una** corrección. (G1 sí se
arregló: ver «Qué hubo que corregir para que arrancara», fila 10.)

| Grupo | Pruebas | Tipo | Causa | Dónde |
|---|---:|---|---|---|
| G2 | 5 | defecto de código | `Usuario` extiende `Model`: no es `Authenticatable` ni tiene `can()` | `api/app/Models/Usuario.php:22` |
| G3 | 1 | defecto de la prueba | usa un enum como clave de arreglo (`TypeError`) | `api/tests/Feature/Permisos/RolesYFichaClinicaTest.php:156` |
| G4 | 1 | defecto de la prueba | `refresh()` fuera del contexto de clínica; la RLS lo rechaza | `api/tests/Feature/Permisos/RolesYFichaClinicaTest.php:192` |
| G5 | 1 | defecto de la prueba | espera las columnas en orden alfabético y la consulta las ordena por posición | `api/tests/Feature/Aislamiento/RowLevelSecurityTest.php:158` |
| G6 | 1 | defecto de código | el manejador de sesión `database` se registra con dos parámetros y Laravel pasa uno (`ArgumentCountError`) | `api/app/Providers/SessionServiceProvider.php:37` |
| G7 | 1 | defecto de código | sin sesión y sin `Accept: application/json`, `auth` busca la ruta `login`, que no existe: responde 500 | `api/routes/web.php:51` |

Pruebas de cada grupo:

- **G2:** `test_solo_el_profesional_abre_la_ficha_clinica`,
  `test_recepcion_ve_la_identificacion_pero_no_el_historial`,
  `test_solo_el_profesional_prescribe_y_aplica_vacunas`,
  `test_el_calendario_de_vacunas_es_mas_abierto_que_la_ficha_clinica`,
  `test_el_modelo_usuario_sirve_para_iniciar_sesion`.
- **G3:** `test_los_cuatro_roles_tienen_las_capacidades_que_corresponden`.
- **G4:** `test_desactivar_un_usuario_se_guarda_y_el_ingreso_lo_rechaza`.
- **G5:** `test_la_vista_de_clinicas_publicas_no_expone_datos_personales`.
- **G6:** `test_el_manejador_de_sesion_database_se_puede_construir`.
- **G7:** `test_una_ruta_protegida_sin_sesion_no_responde_500_a_un_navegador`.

Consecuencias que no son una prueba:

- **Nadie puede entrar.** `AuthController.php:100` hace
  `Auth::guard('web')->login($usuario)` y el guard rechaza el modelo (G2); y con
  `SESSION_DRIVER=database`, que es el valor de `api/.env.example`, la sesión
  no se construye (G6).
- **La semilla sí corre** (comprobado el 2026-11-21): antes moría en
  `database/seeders/ClinicaDeEjemploSeeder.php:322` por G1, que ya está
  corregido.

## Lo que la suite no cubre

- **Ninguna prueba original hace una petición HTTP.** Las 57 rutas, los 19
  controladores y los tres middleware propios no se ejecutan en ninguna de las
  67 pruebas. Lo único que hoy ejercita el enrutador es `ArranqueTest`
  (`/api/v1/salud` responde 200; una ruta protegida sin sesión responde 401 en
  JSON).
- **`APP_TIMEZONE` no tiene efecto.** No hay `config/app.php`; Laravel usa el
  suyo, con zona horaria `UTC`. `api/.env.example` declara
  `America/Santiago`. Comprobado con
  `APP_TIMEZONE=America/Santiago php artisan about`.
- **Solo existen 5 archivos en `api/config/`.** El resto de la configuración
  es la que trae el framework por omisión.
- **Los volúmenes nombrados de `storage/` y `bootstrap/cache/` heredan el
  dueño y el contenido de la imagen.** Es lo que hace Compose la primera vez
  que los monta. La imagen nunca llegó a construir antes de este cambio, así
  que no hay volúmenes viejos de esta API que haya que migrar: si los hubiera,
  habría que revisar el dueño de `storage/` y `bootstrap/cache/`.
- **La imagen de producción sí está verificada** (2026-11-22):
  `bash herramientas/verificar_imagen.sh` construye la etapa final, comprueba
  que no lleva dependencias de desarrollo, ni Composer, ni `tests/`, ni `.env`,
  y la **arranca** contra un PostgreSQL desechable con la configuración solo
  por variables de entorno: migra, responde 200 en `/api/v1/salud` a través de
  nginx y php-fpm, y el worker de colas también arranca.
- **El `docker-compose.prod.yml` no se pudo validar en este entorno.**
  `docker compose -f deploy/docker-compose.prod.yml config --quiet` sale con
  **1** (2026-11-22) por falta de la configuración local, no por un defecto
  del archivo de Compose. Salida literal:

  ```text
  error while interpolating services.caddy.environment.ACME_EMAIL: required variable ACME_EMAIL is missing a value: falta ACME_EMAIL en .env
  error while interpolating services.caddy.environment.DOMINIO: required variable DOMINIO is missing a value: falta DOMINIO en .env
  error while interpolating services.postgres.environment.APP_DB_PASSWORD: required variable DB_PASSWORD is missing a value: falta DB_PASSWORD en api/.env
  error while interpolating services.postgres.environment.POSTGRES_PASSWORD: required variable DB_ADMIN_PASSWORD is missing a value: falta DB_ADMIN_PASSWORD en el .env.prod
  ```

  Son las cuatro variables que el archivo de Compose interpola con `${...}`
  (`DOMINIO`, `ACME_EMAIL`, `DB_PASSWORD`, `DB_ADMIN_PASSWORD`) más el
  `env_file` de `api/.env`. **Ojo:** Compose busca el `.env` de interpolación
  en el **directorio del proyecto de Compose** —el del archivo de Compose, que
  es `deploy/`—, no en la raíz del repositorio: con el `.env` de la raíz
  solamente, la validación sigue fallando. El `docker-compose.prod.yml` **no
  se tocó** (queda fuera del alcance del plan): dejar el procedimiento de
  instalación y el archivo de Compose de acuerdo es una pasada aparte.
- **No verificado:** los `compose` completos (el arranque verificado es el del
  contenedor, no el del `docker compose up`), el flujo OIDC contra Authentik,
  `web/` y los respaldos.

## Riesgos de seguridad vistos al arrancar

El repositorio es **público**.

- No se encontraron secretos en el historial (`gitleaks git`).
- `deploy/docker-compose.dev.yml:48` y `:62` publican PostgreSQL y Redis en
  todas las interfaces del equipo, y Redis no pide clave.
- `deploy/api/Dockerfile` **estaba roto y ya no lo está** (2026-11-22). Antes:
  una sola etapa, `COPY --from=composer:2.8` sin *digest*, `composer
  dump-autoload --dev` en lo que iba a ser la imagen de producción, sin `nginx`
  ni `supervisor` instalados (aunque copiaba su configuración y arrancaba
  `/usr/bin/supervisord`), y sin `.dockerignore`: entraban el `api/vendor/` y
  el `api/tests/` del equipo y el `api/.env`. El build fallaba con
  `Class "NunoMaduro\Collision\Adapters\Laravel\CollisionServiceProvider" not found`.
  Ahora la imagen es de cuatro etapas, el `vendor/` es de producción, hay
  `.dockerignore`, y la imagen final arranca. Verificado con
  `bash herramientas/verificar_imagen.sh`.
- El RUT del tutor se guarda sin validar el dígito verificador
  (`api/app/Http/Controllers/TutorController.php:111`).
- Los documentos citan la Ley 19.628; la norma vigente de referencia es la
  Ley 21.719.

## Cómo reproducir

```bash
bash herramientas/probar.sh                           # todo
bash herramientas/probar.sh migrar                    # migraciones y aislamiento
bash herramientas/probar.sh artisan db:seed --force   # un comando de artisan
python3 -m unittest discover -s herramientas -p 'test_*.py'
```

Requiere Docker y Python 3. No requiere PHP ni Composer en el equipo.
