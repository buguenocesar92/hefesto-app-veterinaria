<?php

declare(strict_types=1);

namespace App\Services\Vacunas;

use App\Models\EsquemaVacunacion;
use DateTimeImmutable;

/**
 * Calcula la fecha de la proxima dosis. UNA VEZ, al aplicar.
 *
 * POR QUE ESTA CLASE ES PURA
 * --------------------------
 * No consulta la base ni usa `now()` por dentro: recibe la fecha de
 * aplicacion como argumento. Eso la hace verificable con una prueba que fija
 * la fecha, que es la unica forma de comprobar que un caso limite esta bien
 * sin depender de que el dia del teste coincida con el dia del codigo.
 *
 * LA REGLA QUE IMPORTA
 * --------------------
 * El resultado se guarda en `vacunas.proxima_dose_en` y **no se vuelve a
 * calcular**. Ver la nota larga de App\Models\Vacuna.
 *
 * QUE NO HACE ESTE CALCULO
 * -----------------------
 * No sabe si la dosis corresponde, ni si el intervalo es el correcto, ni si
 * la mascota es de la especie adecuada. Solo aplica la regla que la clinica
 * configuro en `esquemas_vacunacion`, que puede no estar verificada.
 */
class CalculadorDeProximaDosis
{
    /**
     * Fecha de la proxima dosis, o null si la regla no define una.
     *
     * Un esquema sin `intervalo_meses` ni `refuerzo_meses` no dice cuando
     * vuelve a tocar. Se devuelve null en vez de adivinar: es preferible
     * "sin proxima dosis" a una fecha inventada, porque una fecha inventada
     * termina apareciendo en un certificado.
     */
    public static function calcular(
        EsquemaVacunacion $esquema,
        DateTimeImmutable $fechaAplicacion,
    ): ?DateTimeImmutable {
        $meses = $esquema->mesesHastaProxima();

        if ($meses <= 0) {
            return null;
        }

        return self::sumarMeses($fechaAplicacion, $meses);
    }

    /**
     * Suma meses a una fecha, con el detalle del fin de mes.
     *
     * POR QUE NO BASTA CON `->addMonthsNoOverflow()`
     * ----------------------------------------------
     * Por muchisimas combinaciones de dia y mes, la suma "n intuitiva"
     * (31 de enero mas un mes) no existe como fecha. Hay que decidir que
     * hacer, y la respuesta que se usa en la practica es la que usan los
     * calendarios: al dia que no existe, se recurre al ultimo dia del mes de
     * destino.
     *
     *  - 31 de enero + 1 mes = 28 (o 29) de febrero, NO 2 ni 3 de marzo.
     *  - 30 de noviembre + 1 mes = 31 de diciembre.
     *  - 29 de febrero + 12 meses = 28 de febrero del ano siguiente.
     *
     * Se usa `addMonthsNoOverflow` justamente por eso. Y antes se fija el dia
     * a 1 antes de sumar, para que el ajuste no arrastre el error a meses
     * siguientes.
     *
     * UNA ADVERTENCIA QUE IMPORTA CLINICAMENTE
     * ---------------------------------------
     * Si un dia 31 de enero se aplica una dosis cuya proxima cae el 28 de
     * febrero, el intervalo real fue de 28 dias, no de un mes. Cuando el
     * proxima dosis se aplique el 28 de febrero, el intervalo siguiente se
     * calcula desde esa fecha y el desfase se corrige solo. Si la clinica
     * necesita el dia exacto, debe cargar una regla distinta, no ajustar el
     * sistema a mano.
     */
    public static function sumarMeses(DateTimeImmutable $fecha, int $meses): DateTimeImmutable
    {
        if ($meses === 0) {
            return $fecha;
        }

        // El dia que se quiere conservar, aparte del mes y el anio.
        $dia = (int) $fecha->format('j');

        // Se ancla el dia 1 para que la aritmetica de meses no dependa del dia
        // que todavia no se sabe si va a existir en el mes de destino.
        $sumado = $fecha
            ->setDate((int) $fecha->format('Y'), (int) $fecha->format('n'), 1)
            ->addMonthsNoOverflow($meses);

        // Si el dia no existe en el mes de destino, se recurre al ultimo dia de
        // ese mes. Este es el `min` que hace la diferencia entre "31 de enero
        // mas un mes" = 28 de febrero y no = 2 o 3 de marzo.
        $diaUtil = min($dia, $sumado->daysInMonth);

        return $sumado->setDate(
            (int) $sumado->format('Y'),
            (int) $sumado->format('n'),
            $diaUtil,
        );
    }

    /**
     * Fecha estimada a la que se puede aplicar la primera dosis de un
     * esquema, segun la edad minima de la regla.
     *
     * Se usa para sugerir cuando agendar, NO para decidir. La edad se
     * aproxima por meses enteros a proposito: es lo que la regla guarda, y
     * una fecha mas exacta seria mas precisa que el dato de entrada.
     */
    public static function edadMinimaComoFecha(
        EsquemaVacunacion $esquema,
        DateTimeImmutable $fechaNacimiento,
    ): ?DateTimeImmutable {
        if ($esquema->edad_minima_meses <= 0) {
            return null;
        }

        return self::sumarMeses($fechaNacimiento, (int) $esquema->edad_minima_meses);
    }
}
