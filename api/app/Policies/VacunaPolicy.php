<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Mascota;
use App\Models\Usuario;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Autorizacion sobre el CALENDARIO DE VACUNAS de una mascota.
 *
 * EL BORDE ENTRE ESTO Y LA FICHA CLINICA
 * -------------------------------------
 * El historial de vacunas (que se aplico, que lote, que profesional) es ficha
 * clinica: lo abre solo un profesional.
 *
 * PERO el estado del calendario dice cosas que el mostrador y la recepcion
 * necesitan: "esta mascota tiene la vacuna atrasada desde marzo". Y eso no es
 * un diagnostico: es una obligacion pendiente de cumplir.
 *
 * Por eso `verCalendario` es mas abierto que `viewAny` del historial:
 *
 *  - `verCalendario`: cualquiera que opere la clinica. Ve QUE FALTA, no QUE
 *    PASÓ.
 *  - `registrar` y `aplicar`: solo profesional. Aplicar una vacuna es una
 *    decision clinica y una actuacion sobre el animal.
 *  - `anular`: solo profesional. Anular es deshacer un hecho clinico.
 *
 * El detalle de la regla que produjo la fecha se ve al abrir la ficha, no en
 * el calendario. Ver App\Services\Vacunas\CalculadorDeVigencia.
 */
class VacunaPolicy
{
    use HandlesAuthorization;

    /**
     * Ver que dosis faltan y cuales estan atrasadas.
     */
    public function verCalendario(Usuario $usuario, Mascota $mascota): bool
    {
        return $usuario->rol->puedeUsarAgenda()
            || $usuario->rol->puedeUsarMostrador();
    }

    /**
     * Registrar que se aplico una vacuna.
     *
     * Solo profesional. Quien aplica la dosis es el responsable de ella.
     */
    public function registrar(Usuario $usuario, Mascota $mascota): bool
    {
        return $usuario->rol->puedePrescribir();
    }

    /**
     * Anular una aplicacion.
     */
    public function anular(Usuario $usuario, Mascota $mascota): bool
    {
        return $usuario->rol->puedePrescribir();
    }

    /**
     * Configurar los esquemas de vacunacion de la clinica.
     *
     * Administracion. Y con un recordatorio que va mas alla del permiso: los
     * intervalos los configura alguien que sepa, y **el sistema no verifica
     * que sean los correctos**. Ver docs/07.
     */
    public function administrarEsquemas(Usuario $usuario): bool
    {
        return $usuario->rol->administra();
    }
}
