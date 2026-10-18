<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Vigencia de una dosis: si el animal esta al dia con ella.
 *
 * SE CALCULA, NO SE GUARDA
 * ------------------------
 * Esta es una de las piezas centrales del modulo de vacunacion. La vigencia se
 * deriva en cada lectura de `proxima_dose_en`, que es un HECHO guardado el dia
 * que se aplico la dosis.
 *
 * La razon es la misma que en el resto de la aplicacion, y es la misma que en
 * el control de vencimientos de la flota de vehiculos: **una fecha que se
 * recalcula en cada lectura envejece mal**. Si el calculo depende de "hoy",
 * y hoy cambia, tambien cambia el resultado, y el dia que mas importa
 * (una campana de vacunacion, un control de advertencia, una revision de
 * carnet) el sistema puede decir otra cosa que ayer.
 *
 * Lo que se guarda es `proxima_dose_en`: la fecha que se calculo al aplicar y
 * que se leyo de un esquema. Aunque despues la clinica corrija el esquema, esa
 * fecha NO se mueve sola. Es la misma logica de una fecha impresa en un
 * certificado: si despues se corrige el esquema, la fecha ya impressa no
 * cambia. Ver docs/02, seccion "Vacunas".
 */
enum VigenciaVacuna: string
{
    /** Falta aplicar y todavia no vencio. */
    case Vigente = 'vigente';

    /** Falta aplicar y la fecha ya paso: hay atraso. */
    case Atrasada = 'atrasada';

    /** Vencio en fecha reciente, dentro de la ventana de tolerancia. */
    case PorVencer = 'por_vencer';

    /** El esquema no define una proxima dosis (por ejemplo, ultima dosis). */
    case SinProxima = 'sin_proxima';

    /** La aplicacion fue anulada: no cuenta. */
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vigente => 'Vigente',
            self::Atrasada => 'ATRASADA',
            self::PorVencer => 'Por vencer',
            self::SinProxima => 'Sin proxima dosis',
            self::Anulada => 'Anulada',
        };
    }

    /** Si conviene llamar al tutor por esta situacion. */
    public function requiereAviso(): bool
    {
        return $this === self::Atrasada || $this === self::PorVencer;
    }
}
