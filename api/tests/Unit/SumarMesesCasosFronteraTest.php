<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Vacunas\CalculadorDeProximaDosis;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Casos frontera de la suma de meses, ademas de los de
 * CalculadorDeProximaDosisTest: bisiestos de ida y vuelta, fines de mes
 * largos y saltos de varios anios. La regla es siempre la misma: se conserva
 * el dia, y si no existe en el mes de destino, se usa el ultimo dia de ese
 * mes.
 */
class SumarMesesCasosFronteraTest extends TestCase
{
    /**
     * @return array<string, array{string, int, string}>
     */
    public static function casos(): array
    {
        return [
            '29 de febrero + 12 meses cae el 28 del anio siguiente' => ['2028-02-29', 12, '2029-02-28'],
            '29 de febrero + 48 meses vuelve a un 29 de febrero' => ['2028-02-29', 48, '2032-02-29'],
            '31 de marzo + 1 mes cae el 30 de abril' => ['2026-03-31', 1, '2026-04-30'],
            '31 de enero + 13 meses cae en febrero bisiesto' => ['2027-01-31', 13, '2028-02-29'],
            '31 de agosto + 6 meses cae el 28 de febrero' => ['2026-08-31', 6, '2027-02-28'],
            '31 de mayo + 120 meses conserva el 31' => ['2026-05-31', 120, '2036-05-31'],
            '15 de noviembre + 3 meses cruza el anio' => ['2026-11-15', 3, '2027-02-15'],
            '28 de febrero + 1 mes conserva el 28' => ['2026-02-28', 1, '2026-03-28'],
        ];
    }

    #[DataProvider('casos')]
    public function test_suma_meses_con_ajuste_de_fin_de_mes(string $desde, int $meses, string $esperado): void
    {
        $resultado = CalculadorDeProximaDosis::sumarMeses(new DateTimeImmutable($desde), $meses);

        $this->assertSame($esperado, $resultado->format('Y-m-d'));
    }

    public function test_conserva_la_hora_y_la_zona_horaria(): void
    {
        $zona = new DateTimeZone('America/Santiago');
        $resultado = CalculadorDeProximaDosis::sumarMeses(
            new DateTimeImmutable('2026-01-31 10:30:00', $zona),
            1,
        );

        $this->assertSame('2026-02-28 10:30:00', $resultado->format('Y-m-d H:i:s'));
        $this->assertSame('America/Santiago', $resultado->getTimezone()->getName());
    }

    public function test_no_modifica_la_fecha_original(): void
    {
        $original = new DateTimeImmutable('2026-01-31');

        CalculadorDeProximaDosis::sumarMeses($original, 1);

        $this->assertSame('2026-01-31', $original->format('Y-m-d'));
    }
}
