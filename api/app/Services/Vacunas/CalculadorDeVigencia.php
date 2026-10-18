<?php

declare(strict_types=1);

namespace App\Services\Vacunas;

use App\Enums\EstadoVacuna;
use App\Enums\VigenciaVacuna;
use App\Models\Vacuna;
use Illuminate\Support\Carbon;

/**
 * Dice si una dosis esta al dia o atrasada. SE CALCULA EN CADA LECTURA.
 *
 * LA SEPARACION CON `proxima_dose_en`
 * ----------------------------------
 * En este modulo hay dos fechas y no es lo mismo una que otra:
 *
 *  - `proxima_dose_en` GUARDADA: cuando cae la proxima dosis. Es un hecho,
 *    ya estaba decidido el dia que se aplico.
 *  - la VIGENCIA DERIVADA: si esa fecha ya paso. Depende de la fecha de hoy,
 *    que es justamente lo que tiene que cambiar.
 *
 * Guardar tambien el estado seria el error clasico: se escribe
 * `estado = 'vigente'` el dia de la aplicacion, nadie lo actualiza nunca, y
 * dos anos despues el sistema sigue diciendo que la mascota esta al dia.
 * Con el estado derivado, no hay nada que quedar viejo.
 *
 * LA VENTANA DE TOLERANCIA
 * -----------------------
 * `porVencer` existe por la practica, no por la norma: una dosis que vence
 * manana no se considera atrasada. El umbral sale de la configuracion y lo
 * ajusta cada clinica. Ver config/veterinaria.php.
 */
class CalculadorDeVigencia
{
    /**
     * Vigencia de una vacuna concreta.
     */
    public static function deVacuna(Vacuna $vacuna, ?Carbon $hoy = null): VigenciaVacuna
    {
        if ($vacuna->estado !== EstadoVacuna::Aplicada) {
            return VigenciaVacuna::Anulada;
        }

        return self::deProximaDosis($vacuna->proxima_dose_en, $hoy);
    }

    /**
     * Vigencia que se deduce de una proxima dosis.
     *
     * Es el nucleo real de la regla, separado de Eloquent para poder
     * probarlo sin base de datos.
     *
     * @param  \Illuminate\Support\Carbon|null  $hoy  se pasa por parametro
     *         para que las pruebas fijen la fecha y no dependan del dia en que
     *         se corren.
     */
    public static function deProximaDosis(?Carbon $proximaDosis, ?Carbon $hoy = null): VigenciaVacuna
    {
        if ($proximaDosis === null) {
            // El esquema no define proxima dosis: puede ser la ultima del
            // calendario. No es un atraso, es una ausencia.
            return VigenciaVacuna::SinProxima;
        }

        $referencia = ($hoy ?? now())->startOfDay();
        $proxima = $proximaDosis->copy()->startOfDay();

        if ($proxima->lt($referencia)) {
            return VigenciaVacuna::Atrasada;
        }

        $ventana = (int) config('veterinaria.vacunas.ventana_por_vencer_dias', 30);

        if ($proxima->lte($referencia->copy()->addDays($ventana))) {
            return VigenciaVacuna::PorVencer;
        }

        return VigenciaVacuna::Vigente;
    }
}
