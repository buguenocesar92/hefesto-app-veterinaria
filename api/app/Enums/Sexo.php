<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sexo de la mascota.
 *
 * Es el dato que mas se omite en la practica, y con razon: casi nadie
 * pregunta. La respuesta es `NoInformado`.
 *
 * QUE PASA SI SE DEJA FUERA EL "NO INFORMADO"
 * ------------------------------------------
 * Si el formulario obliga a elegir y no esta el "no informado", la persona
 * elige algo que no sabe. Un perro al que nunca le dijeron el sexo queda
 * registrado como "macho" o "hembra" segun lo que el dedo haya marcado, y esa
 * afirmacion inventada se queda en la ficha para siempre.
 *
 * `NoInformado` existe para que el dato sea honesto. Ver docs/02.
 */
enum Sexo: string
{
    case Macho = 'macho';
    case Hembra = 'hembra';
    case NoInformado = 'no_informado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Macho => 'Macho',
            self::Hembra => 'Hembra',
            self::NoInformado => 'No informado',
        };
    }

    /** Si el dato se sabe de verdad, o es una ausencia declarada. */
    public function informado(): bool
    {
        return $this !== self::NoInformado;
    }
}
