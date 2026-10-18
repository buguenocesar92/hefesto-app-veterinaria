<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Consulta;
use App\Models\EsquemaVacunacion;
use App\Models\Mascota;
use App\Models\Prescripcion;
use App\Models\Producto;
use App\Models\Profesional;
use App\Models\TipoAtencion;
use App\Models\Tutor;
use App\Models\Usuario;
use App\Models\Vacuna;
use App\Policies\CatalogoPolicy;
use App\Policies\MascotaPolicy;
use App\Policies\VacunaPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registro de Policies y abilities, y arranque de la aplicacion.
 *
 * ===================================================================
 * POR QUE HAY UNA ABILITY SUELTA Y NO SOLO POLICIES
 * ===================================================================
 *
 * Laravel asocia UNA Policy a cada modelo. Y en este dominio hay DOS
 * preguntas distintas sobre la MISMA fila de `mascotas`:
 *
 *  1. ¿Puede esta persona ver los DATOS DE IDENTIFICACION (nombre, especie,
 *     peso, microchip)? -> `MascotaPolicy::view`, mas abierta.
 *
 *  2. ¿Puede esta persona abrir la FICHA CLINICA (diagnosticos, recetas,
 *     historial de vacunas)? -> solo profesional.
 *
 * Si las dos fueran metodos de la misma Policy, uno de los dos estaria mal: no
 * se pueden abrir con el mismo permiso, porque la segunda es informacion de
 * salud y la primera no. Ver docs/03.
 *
 * La solucion que se eligio:
 *
 *  - `MascotaPolicy` queda asociada a `Mascota` y responde por la
 *    IDENTIFICACION.
 *  - La ficha clinica es una ABILITY suelta, `ver-ficha-clinica`, que se
 *    comprueba con `Gate::authorize('ver-ficha-clinica', $mascota)`.
 *
 * Alternativa descartada: meter las dos cosas en `MascotaPolicy` y renombrar
 * los metodos (`verIdentificacion`, `verFichaClinica`). Se descarto porque
 * entonces las rutas de ficha clinica autorizarian contra un modelo que no
 * es el recurso que manipulan, y el `autorize()` de un controladorResource
 * terminaria exigiendo metodos que no existen en la Policy real.
 *
 * LAS DOS CAPAS
 * -------------
 *  - El MIDDLEWARE (`admin`, `ficha.auditada`) decide si se entra a una zona.
 *  - La POLICY y las abilities deciden sobre UN recurso concreto.
 *
 * Sin ability, bastaria revisar el rol; pero entonces no se podria responder
 * "este profesional puede abrir ESTA mascota y no la otra".
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Ficha de IDENTIFICACION de la mascota. Mas abierta: la necesita
        // recepcion para agendar.
        Gate::policy(Mascota::class, MascotaPolicy::class);
        Gate::policy(Tutor::class, MascotaPolicy::class);

        // Ficha CLINICA: la ability suelta de mas abajo.
        Gate::policy(Consulta::class, MascotaPolicy::class);
        Gate::policy(Prescripcion::class, MascotaPolicy::class);

        // Vacunas.
        Gate::policy(Vacuna::class, VacunaPolicy::class);
        Gate::policy(EsquemaVacunacion::class, VacunaPolicy::class);

        // Catalogo de la clinica.
        Gate::policy(Profesional::class, CatalogoPolicy::class);
        Gate::policy(TipoAtencion::class, CatalogoPolicy::class);
        Gate::policy(Producto::class, CatalogoPolicy::class);
        Gate::policy(Usuario::class, CatalogoPolicy::class);

        /*
         * LA FICHA CLINICA
         * ----------------
         * Solo un profesional la abre. Ni recepcion, ni mostrador, ni la
         * direccion de la clinica: la direccion no es un cargo clinico, y
         * abrirle por cargo la historia de todos los animales es exactamente
         * lo que la Ley 19.628 pide acotar.
         *
         * El filtro por MASCOTA lo hace el recurso que se pasa; aqui solo se
         * decide el ROL.
         */
        Gate::define('ver-ficha-clinica', function (Usuario $usuario, Mascota $mascota): bool {
            return $usuario->rol->puedeLeerFichaClinica();
        });

        /*
         * REGISTRAR Y APLICAR EN LA FICHA
         * ------------------------------
         * Mismo criterio que leer: quien puede escribir el historial es quien
         * puede leerlo.
         *
         * Si se permitiera registrar sin poder leer, se podrian escribir
         * diagnosticos en fichas que uno mismo no puede abrir, y eso seria una
         * puerta trasera.
         */
        Gate::define('escribir-ficha-clinica', function (Usuario $usuario, Mascota $mascota): bool {
            return $usuario->rol->puedeLeerFichaClinica();
        });

        /*
         * APLICAR UNA VACUNA
         * ------------------
         * Solo profesional. Quien aplica la dosis es el responsable de ella, y
         * la proxima fecha se calcula ahi. Ver VacunaController.
         */
        Gate::define('aplicar-vacuna', function (Usuario $usuario, Mascota $mascota): bool {
            return $usuario->rol->puedePrescribir();
        });

        /*
         * VER EL CALENDARIO DE VACUNAS
         * ----------------------------
         * Mas abierto que la ficha clinica, a proposito: "la polivalente vence
         * en marzo" es una obligacion pendiente de la clinica, no un dato de
         * salud del animal, y quien atiende el telefono tiene que poder
         * verla para llamar.
         *
         * Lo que NO se abre con esta ability es el historial: para eso esta
         * `ver-ficha-clinica`.
         */
        Gate::define('ver-calendario-vacunas', function (Usuario $usuario, Mascota $mascota): bool {
            return $usuario->rol->puedeUsarAgenda()
                || $usuario->rol->puedeUsarMostrador();
        });

        /*
         * PRESCRIBIR
         * ----------
         * Solo profesional. Lo que tiene registro profesional vigente.
         */
        Gate::define('prescribir', function (Usuario $usuario, Mascota $mascota): bool {
            return $usuario->rol->puedePrescribir();
        });
    }
}
