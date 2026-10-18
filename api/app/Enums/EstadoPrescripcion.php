<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una prescripcion veterinaria.
 *
 * ADVERTENCIA QUE ESTE SISTEMA NO REEMPLAZA
 * -----------------------------------------
 * Una prescripcion veterinaria es una indicacion para una persona que va a
 * medicar a un animal en su casa. El sistema la registra y la imprime; NO la
 * valida, NO la revisa y NO decide si es la correcta.
 *
 * En Chile la prescripcion veterinaria se rige por el Ley 20.584 y su
 * reglamento, con requisitos que dependen del tipo de producto y de la
 * especie. **Ese marco normativo no esta verificado en este entorno** y su
 * cumplimiento es responsabilidad de un profesional con registro valido.
 * Ver docs/07.
 */
enum EstadoPrescripcion: string
{
    case Emitida = 'emitida';
    case Dispensada = 'dispensada';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Emitida => 'Emitida',
            self::Dispensada => 'Dispensada',
            self::Anulada => 'Anulada',
        };
    }

    /** Si se puede pasar a otro estado. Una anulada no vuelve. */
    public function puedePasarA(self $destino): bool
    {
        return match ($this) {
            self::Emitida => in_array($destino, [self::Dispensada, self::Anulada], true),
            self::Dispensada => in_array($destino, [self::Anulada], true),
            self::Anulada => false,
        };
    }
}
