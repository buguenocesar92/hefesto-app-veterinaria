<?php

declare(strict_types=1);

use App\Http\Controllers\Administracion\AuditoriaController;
use App\Http\Controllers\Administracion\EsquemaVacunacionController;
use App\Http\Controllers\Administracion\ProductoController;
use App\Http\Controllers\Administracion\ProfesionalController;
use App\Http\Controllers\Administracion\TipoAtencionController;
use App\Http\Controllers\Administracion\UsuarioController;
use App\Http\Controllers\Agenda\CitaController;
use App\Http\Controllers\Agenda\ConsultaAgendaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Ficha\ConsultaController;
use App\Http\Controllers\Ficha\ControlController;
use App\Http\Controllers\Ficha\HistorialController;
use App\Http\Controllers\Ficha\PrescripcionController;
use App\Http\Controllers\MascotaController;
use App\Http\Controllers\Mostrador\ProductoController as MostradorProductoController;
use App\Http\Controllers\Mostrador\VentaController;
use App\Http\Controllers\TutorController;
use App\Http\Controllers\Vacunas\CalendarioVacunasController;
use App\Http\Controllers\Vacunas\VacunaController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Rutas de la API de VeterinariaHefesto
|-------------------------------------------------------------------------------
|
| Todas cuelgan del grupo `web` de Laravel (ver bootstrap/app.php) y del
| prefijo `/api/v1`, que se aplica aqui y no en el esqueleto: queda a la vista
| que TODA la superficie de la API esta versionada en una sola linea.
|
| Estas rutas las consume el BFF de Nuxt (web/server/api), que reenvia las
| cookies del navegador. Ver docs/01 y docs/09.
|
| EL CONTROL FINO NO ESTA ACA. Las rutas declaran que recurso se toca; quien
| puede tocarlo, y sobre todo quien puede abrir una ficha clinica, se decide en
| App\Policies. Ver docs/03.
|
*/
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {

    /*
    |---------------------------------------------------------------------------
    | Identidad (OIDC contra Authentik)
    |---------------------------------------------------------------------------
    */
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('login', [AuthController::class, 'login'])->name('login');
        Route::get('callback', [AuthController::class, 'callback'])->name('callback');

        Route::middleware('auth')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('usuario', [AuthController::class, 'usuario'])->name('usuario');
        });
    });

    Route::middleware('auth')->group(function (): void {

        /*
        |-----------------------------------------------------------------------
        | Tutores y mascotas: IDENTIFICACION
        |-----------------------------------------------------------------------
        | Recepcion entra aqui. Es lo que necesita para saber a quien agenda y
        | como llamar.
        |
        | No hay ningun dato clinico en estas rutas: el diagnostico esta en las
        | fichas, que van aparte y con su propio middleware.
        */
        Route::apiResource('tutores', TutorController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['tutores' => 'tutor'])
            ->names('tutores');

        /*
         * Mascotas cuelgan del tutor en la URL, porque asi se refleja el
         * dominio: las mascotas de una persona. Y porque la pantalla de alta
         * es la del tutor.
         */
        Route::apiResource('tutores.mascotas', MascotaController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['tutores' => 'tutor', 'mascotas' => 'mascota'])
            ->names('tutores.mascotas');

        /*
        |-----------------------------------------------------------------------
        | Agenda
        |-----------------------------------------------------------------------
        | Recepcion, mostrador no. La agenda se consulta seguido, asi que el
        | listado va por cache (ver App\Services\Agenda\CacheAgenda).
        */
        Route::get('agenda', ConsultaAgendaController::class)->name('agenda');

        Route::apiResource('citas', CitaController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['citas' => 'cita'])
            ->names('citas');

        /*
        |-----------------------------------------------------------------------
        | FICHA CLINICA
        |-----------------------------------------------------------------------
        | Todo lo que cuelga de aqui esta bajo `ficha.auditada`: cada intento
        | queda anotado en `accesos_ficha`, permitido o denegado.
        |
        | SOLO UN PROFESIONAL ABRE ESTO. Ni recepcion, ni mostrador, ni la
        | direccion. Ver la ability `ver-ficha-clinica` en AuthServiceProvider.
        |
        | Y TODAS LAS RUTAS RECIBEN `{mascota}`: el historial se busca SIEMPRE
        | por mascota, nunca por tutor. Un tutor con tres mascotas tiene tres
        | historiales que no se mezclan. Ver Mascota.
        */
        Route::middleware('ficha.auditada')->group(function (): void {

            Route::get('mascotas/{mascota}/historial', [HistorialController::class, 'show'])
                ->name('mascotas.historial');

            Route::apiResource('mascotas.consultas', ConsultaController::class)
                ->only(['index', 'store'])
                ->parameters(['mascotas' => 'mascota', 'consultas' => 'consulta'])
                ->names('mascotas.consultas');

            Route::apiResource('mascotas.controles', ControlController::class)
                ->only(['index', 'store', 'update'])
                ->parameters(['mascotas' => 'mascota', 'controles' => 'control'])
                ->names('mascotas.controles');

            Route::apiResource('mascotas.prescripciones', PrescripcionController::class)
                ->only(['index', 'store'])
                ->parameters(['mascotas' => 'mascota', 'prescripciones' => 'prescripcion'])
                ->names('mascotas.prescripciones');

            // Anular no es editar: es un cambio de estado con consecuencia
            // clinica, y por eso tiene su propia ruta.
            Route::post('mascotas/{mascota}/prescripciones/{prescripcion}/anular', [PrescripcionController::class, 'anular'])
                ->name('mascotas.prescripciones.anular');
        });

        /*
        |-----------------------------------------------------------------------
        | Vacunas
        |-----------------------------------------------------------------------
        | Dos permisos distintos en el mismo grupo, y a proposito:
        |
        |  - `calendario`: que dosis FALTAN. Lo puede ver cualquiera que opere
        |    la clinica, porque "esta atrasada" es una obligacion pendiente, no
        |    un dato de salud.
        |  - registrar y anular: solo profesional. Aplicar una vacuna es una
        |    decision clinica. Ver VacunaPolicy.
        */
        Route::get('mascotas/{mascota}/calendario-vacunas', [CalendarioVacunasController::class, 'show'])
            ->name('mascotas.calendario-vacunas');

        Route::post('mascotas/{mascota}/vacunas', [VacunaController::class, 'store'])
            ->name('mascotas.vacunas.store');

        Route::post('mascotas/{mascota}/vacunas/{vacuna}/anular', [VacunaController::class, 'anular'])
            ->name('mascotas.vacunas.anular');

        /*
        |-----------------------------------------------------------------------
        | Mostrador
        |-----------------------------------------------------------------------
        | Mostrador y recepcion. Sin ficha clinica: por la venta se llega al
        | tutor y al producto, nunca al historial de la mascota.
        */
        Route::get('mostrador/productos', [MostradorProductoController::class, 'index'])
            ->name('mostrador.productos');

        Route::get('mostrador/productos/{producto}', [MostradorProductoController::class, 'show'])
            ->name('mostrador.productos.show');

        Route::get('mostrador/ventas', [VentaController::class, 'index'])
            ->name('mostrador.ventas');

        Route::post('mostrador/ventas', [VentaController::class, 'store'])
            ->name('mostrador.ventas.store');

        /*
        |-----------------------------------------------------------------------
        | Administracion de la clinica
        |-----------------------------------------------------------------------
        | Dos grupos, y no es cosmetico.
        |
        | 1. CATALOGO QUE LA AGENDA NECESITA (profesionales, tipos de atencion).
        |    Sin el, agendar es imposible: hay que saber a quien se agenda y
        |    cuanto dura. El LISTADO abre para quien agenda (`verParaAgenda` en
        |    la Policy); crear y modificar, no.
        |
        |    NO llevan el middleware `admin`: si lo llevaran, recepcion
        |    recibiria 403 y no podria agendar nada.
        |
        | 2. ADMINISTRACION PROPIAMENTE DICHA (productos, esquemas de
        |    vacunacion, usuarios, auditoria). Solo administracion, con el
        |    middleware que corta antes de llegar al controlador.
        |
        | Ver docs/03.
        */
        Route::prefix('admin')->name('admin.')->group(function (): void {

            // 1. Catalogo que la agenda necesita.
            Route::apiResource('profesionales', ProfesionalController::class)
                ->only(['index', 'store', 'show', 'update'])
                ->parameters(['profesionales' => 'profesional'])
                ->names('profesionales');

            Route::apiResource('tipos-atencion', TipoAtencionController::class)
                ->only(['index', 'store', 'show', 'update'])
                ->parameters(['tipos-atencion' => 'tipoAtencion'])
                ->names('tipos-atencion');

            // 2. Administracion propiamente dicha.
            Route::middleware('admin')->group(function (): void {

                Route::apiResource('productos', ProductoController::class)
                    ->only(['index', 'store', 'show', 'update'])
                    ->parameters(['productos' => 'producto'])
                    ->names('productos');

                /*
                 * Esquemas de vacunacion.
                 *
                 * Se administran aqui porque son configuracion de la clinica.
                 * Y porque el que los escribe tiene que saber lo que esta
                 * escribiendo: **el sistema no verifica que los intervalos
                 * sean correctos**. Ver docs/07.
                 */
                Route::apiResource('esquemas-vacunacion', EsquemaVacunacionController::class)
                    ->only(['index', 'store', 'show', 'update'])
                    ->parameters(['esquemas-vacunacion' => 'esquemaVacunacion'])
                    ->names('esquemas-vacunacion');

                Route::apiResource('usuarios', UsuarioController::class)
                    ->only(['index', 'store', 'show', 'update'])
                    ->parameters(['usuarios' => 'usuario'])
                    ->names('usuarios');

                // Bitacora de accesos a ficha clinica.
                Route::get('auditoria', AuditoriaController::class)->name('auditoria');
            });
        });
    });
});
