<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Consulta;
use App\Models\Mascota;
use App\Models\Prescripcion;
use App\Models\Producto;
use App\Models\Profesional;
use App\Models\TipoAtencion;
use App\Models\Usuario;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Permisos del catalogo de la clinica: profesionales y tipos de atencion.
 *
 * POR QUE ESTOS NO LLEVAN EL MIDDLEWARE `admin`
 * ---------------------------------------------
 * Porque sin catalogo no se puede agendar. Si el listado exigiera rol de
 * administracion, la recepcion no podria hacer su trabajo.
 *
 * La separacion es:
 *
 *  - LISTAR: abierto a quien opera la clinica. Necesita saber a quien agenda,
 *    cuanto dura cada atencion y si es del bloque de vacunacion.
 *  - CREAR y MODIFICAR: solo administracion. Cambiar un precio o la duracion
 *    de una atencion afecta a todos.
 *
 * Ver routes/web.php y docs/03.
 */
class CatalogoPolicy
{
    use HandlesAuthorization;

    /**
     * Ver el catalogo para poder agendar.
     */
    public function verParaAgenda(Usuario $usuario): bool
    {
        return $usuario->rol->puedeUsarAgenda();
    }

    /**
     * Crear entradas de catalogo.
     */
    public function crear(Usuario $usuario, string $recurso): bool
    {
        return $usuario->rol->administra();
    }

    /**
     * Modificar entradas de catalogo.
     */
    public function actualizar(Usuario $usuario, object $recurso): bool
    {
        return $usuario->rol->administra();
    }

    /**
     * Ver una entrada en detalle.
     *
     * Los usuarios son la excepcion: ver el listado de usuarios con sus roles
     * no es lo mismo que ver uno en detalle.
     */
    public function verDetalle(Usuario $usuario, object $recurso): bool
    {
        if ($recurso instanceof Usuario) {
            return $usuario->rol->administra();
        }

        return $usuario->rol->puedeUsarAgenda();
    }
}
