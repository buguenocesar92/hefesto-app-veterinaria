<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Mascota;
use App\Models\Usuario;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Autorizacion sobre las MASCOTAS: su ficha de identificacion.
 *
 * QUE DIFERENCIA DE LA FICHA CLINICA
 * ---------------------------------
 * Esta tabla tiene nombre, especie, raza, peso y microchip. **No tiene
 * diagnostico ni tratamiento.** Es el dato que necesita la recepcion para
 * saber a quien agenda y el mostrador para despachar un alimento.
 *
 * Por eso la puede ver mas gente que la ficha clinica. Y por eso el
 * mostrador tambien, aunque en el caso del mostrador el dato de la mascota
 * llega por la venta, no por abrir su ficha.
 *
 * EL PESO ES EL BORDE
 * -------------------
 * `peso_kg` esta en la tabla de identificacion y es un dato clínico. Un
 * animal que esta bajo de peso cronicamente se ve en esa columna.
 *
 * Decision tomada: la tabla va entera para quien puede ver la mascota. Es el
 * mismo criterio que la ficha de un paciente: quien tiene el animal delante
 * necesita el peso para dispensar. Lo que NO se hace es abrir el historial.
 * Esta eleccion esta anotada como pendiente de confirmar con una clinica real
 * en docs/10.
 */
class MascotaPolicy
{
    use HandlesAuthorization;

    /**
     * Ver la ficha de identificacion.
     */
    public function view(Usuario $usuario, Mascota $mascota): bool
    {
        // Los tres roles que operan la clinica. El mostrador entra por la
        // venta, no por explorar el padron de mascotas, pero el permiso existe
        // para el caso de que se le pida buscar un producto por mascota.
        return $usuario->rol->puedeUsarAgenda()
            || $usuario->rol->puedeUsarMostrador();
    }

    /**
     * Crear o modificar la ficha de identificacion.
     *
     * El mostrador NO: dar de alta una mascota es trabajo de recepcion o del
     * profesional, y es el momento en que se pide el RUT del tutor, que es
     * dato personal.
     */
    public function actualizar(Usuario $usuario, Mascota $mascota): bool
    {
        return $usuario->rol->puedeUsarAgenda();
    }
}
