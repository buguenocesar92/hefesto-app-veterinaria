<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una cita en la agenda.
 *
 * POR QUE HAY UN ESTADO "CANCELADA" Y NO SOLO "BORRAR"
 * ----------------------------------------------------
 * Una cita que se cancela existio: el animal estaba agendado, la agenda se
 * ocupo y alguien reservo ese horario. Borrar el registro deja la agenda con
 * un hueco sin explicacion y, mas importante, **destruye la evidencia de que
 * ese animal paso por la clinica**. Se marca el estado y se conserva la fila.
 *
 * ESTADOS FINALES Y OCUPACION DE AGENDA
 * -------------------------------------
 * `ocupaAgenda()` es lo que decide si un horario queda libre. Los estados
 * finales no ocupan: una cita atendida no vuelve a tapar el horario, y una
 * cancelada lo libera para que se pueda ofrecer a otro animal.
 */
enum EstadoCita: string
{
    case Pendiente = 'pendiente';
    case Confirmada = 'confirmada';
    case EnCurso = 'en_curso';
    case Atendida = 'atendida';
    case NoAsistio = 'no_asistio';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Confirmada => 'Confirmada',
            self::EnCurso => 'En curso',
            self::Atendida => 'Atendida',
            self::NoAsistio => 'No asistio',
            self::Cancelada => 'Cancelada',
        };
    }

    /** Si el horario sigue tomado. */
    public function ocupaAgenda(): bool
    {
        return match ($this) {
            self::Pendiente, self::Confirmada, self::EnCurso => true,
            self::Atendida, self::NoAsistio, self::Cancelada => false,
        };
    }

    /** Si la cita ya no va a cambiar. */
    public function esFinal(): bool
    {
        return ! $this->ocupaAgenda();
    }

    /**
     * A que estado se puede pasar desde este.
     *
     * No es cosmetico: impide, por ejemplo, reabrir una cita que ya se marco
     * como atendida y volver a tapar un horario que se dio a otro animal.
     */
    public function puedePasarA(self $destino): bool
    {
        return match ($this) {
            self::Pendiente => in_array($destino, [self::Confirmada, self::EnCurso, self::Atendida, self::NoAsistio, self::Cancelada], true),
            self::Confirmada => in_array($destino, [self::EnCurso, self::Atendida, self::NoAsistio, self::Cancelada], true),
            self::EnCurso => in_array($destino, [self::Atendida, self::NoAsistio, self::Cancelada], true),
            self::Atendida, self::NoAsistio, self::Cancelada => false,
        };
    }
}
