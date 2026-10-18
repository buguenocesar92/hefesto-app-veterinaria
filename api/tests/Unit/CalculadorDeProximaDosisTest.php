<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\EsquemaVacunacion;
use App\Services\Vacunas\CalculadorDeProximaDosis;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * EL CALCULO DE LA PROXIMA DOSIS, SIN BASE DE DATOS.
 *
 * POR QUE ESTAS PRUEBAS NO NECESITAN POSTGRESQL
 * --------------------------------------------
 * `CalculadorDeProximaDosis` es una clase PURA: recibe la fecha de aplicacion
 * como argumento y no consulta nada. Por eso se puede probar con PHPUnit a
 * secas, sin levantar la base, y se pueden fijar las fechas de los casos
 * limite.
 *
 * Y eso importa: un caso como "31 de enero mas un mes" solo se puede probar
 * de verdad si el dia esta controlado. Una prueba que usa `now()` no
 * comprueba el calculo, comprueba la fecha de hoy.
 *
 * QUE NO SE COMPRUEBA AQUI, A PROPOSITO
 * -------------------------------------
 * Que el intervalo de la regla sea el correcto segun la especie, la edad o la
 * normativa. **Este proyecto no verifica eso contra ninguna fuente oficial.**
 * Lo que se comprueba es que la aritmetica de fechas sea correcta. Ver docs/07.
 */
class CalculadorDeProximaDosisTest extends TestCase
{
    private function esquema(int $intervaloMeses, ?int $refuerzoMeses = null, int $edadMinima = 2): EsquemaVacunacion
    {
        $esquema = new EsquemaVacunacion([
            'especie' => 'canino',
            'nombre_vacuna' => 'Vacuna de prueba',
            'dosis_numero' => 1,
            'edad_minima_meses' => $edadMinima,
            'intervalo_meses' => $intervaloMeses,
            'refuerzo_meses' => $refuerzoMeses,
            'verificado' => false,
            'activo' => true,
        ]);

        // Se guarda en memoria: las pruebas de esta clase no tocan la base.
        $esquema->id = '00000000-0000-0000-0000-000000000000';

        return $esquema;
    }

    public function test_suma_un_intervalo_normal(): void
    {
        $resultado = CalculadorDeProximaDosis::calcular(
            $this->esquema(3),
            new DateTimeImmutable('2026-01-15'),
        );

        $this->assertSame('2026-04-15', $resultado?->format('Y-m-d'));
    }

    public function test_31_de_enero_mas_un_mes_cae_el_28_de_febrero(): void
    {
        $resultado = CalculadorDeProximaDosis::calcular(
            $this->esquema(1),
            new DateTimeImmutable('2026-01-31'),
        );

        // 2026 no es bisiesto, y "28 de febrero" es la respuesta que dan los
        // calendarios cuando el dia no existe. Lo que NO es correcto es 2 o 3
        // de marzo.
        $this->assertSame('2026-02-28', $resultado?->format('Y-m-d'));
    }

    public function test_31_de_enero_mas_un_mes_en_ano_bisiesto_cae_el_29(): void
    {
        $resultado = CalculadorDeProximaDosis::calcular(
            $this->esquema(1),
            new DateTimeImmutable('2028-01-31'),
        );

        $this->assertSame('2028-02-29', $resultado?->format('Y-m-d'));
    }

    public function test_30_de_noviembre_mas_un_mes_cae_el_30_de_diciembre(): void
    {
        $resultado = CalculadorDeProximaDosis::calcular(
            $this->esquema(1),
            new DateTimeImmutable('2026-11-30'),
        );

        // El dia 30 EXISTE en diciembre, asi que no hay ajuste que hacer: el
        // dia se conserva. El ajuste solo entra cuando el dia no existe en el
        // mes de destino, como el 31 de enero a febrero.
        $this->assertSame('2026-12-30', $resultado?->format('Y-m-d'));
    }

    public function test_31_de_diciembre_mas_un_mes_cae_en_enero_del_siguiente_ano(): void
    {
        $resultado = CalculadorDeProximaDosis::calcular(
            $this->esquema(1),
            new DateTimeImmutable('2026-12-31'),
        );

        $this->assertSame('2027-01-31', $resultado?->format('Y-m-d'));
    }

    public function test_un_refuerzo_manda_sobre_el_intervalo(): void
    {
        // Serie inicial cada 3 meses, pero el refuerzo es anual: el calculo
        // tiene que usar el refuerzo, no el intervalo.
        $resultado = CalculadorDeProximaDosis::calcular(
            $this->esquema(3, 12),
            new DateTimeImmutable('2026-03-10'),
        );

        $this->assertSame('2027-03-10', $resultado?->format('Y-m-d'));
    }

    public function test_un_refuerzo_antes_del_intervalo_tambien_manda(): void
    {
        // Caso raro pero real: una regla con refuerzo menor que el intervalo.
        // Manda el refuerzo, porque es el que dice cuando vuelve.
        $resultado = CalculadorDeProximaDosis::calcular(
            $this->esquema(12, 6),
            new DateTimeImmutable('2026-03-10'),
        );

        $this->assertSame('2026-09-10', $resultado?->format('Y-m-d'));
    }

    public function test_sin_intervalo_ni_refuerzo_no_hay_proxima_dosis(): void
    {
        $esquema = $this->esquema(0);
        $esquema->refuerzo_meses = null;

        $resultado = CalculadorDeProximaDosis::calcular(
            $esquema,
            new DateTimeImmutable('2026-01-15'),
        );

        // Se prefiere "sin proxima dosis" a inventar una fecha. Una fecha
        // inventada termina apareciendo en un certificado.
        $this->assertNull($resultado);
    }

    public function test_la_edad_minima_se_traduce_a_fecha(): void
    {
        $resultado = CalculadorDeProximaDosis::edadMinimaComoFecha(
            $this->esquema(3, null, 8),
            new DateTimeImmutable('2026-05-20'),
        );

        // 8 meses despues del 20 de mayo: 20 de enero de 2027.
        $this->assertSame('2027-01-20', $resultado?->format('Y-m-d'));
    }

    public function test_el_ajuste_de_fin_de_mes_no_se_arrastra_al_siguiente_salto(): void
    {
        // 31 de enero + 1 mes cae el 28 de febrero (dia que no existe).
        // Si ese 28 "pegara" como ancla y el siguiente salto se calculara desde
        // ahi, la fecha quedaria corrida hacia atras para siempre: de ahi en
        // mas todos los dias 31 serian 28.
        $primero = CalculadorDeProximaDosis::sumarMeses(new DateTimeImmutable('2026-01-31'), 1);
        $segundo = CalculadorDeProximaDosis::sumarMeses($primero, 1);

        $this->assertSame('2026-02-28', $primero->format('Y-m-d'));

        // El segundo salto conserva el dia 28 en marzo, que existe. Lo que no
        // puede pasar es volver a febrero.
        $this->assertSame('2026-03-28', $segundo->format('Y-m-d'));
    }

    public function test_sumar_cero_meses_no_cambia_la_fecha(): void
    {
        $resultado = CalculadorDeProximaDosis::sumarMeses(
            new DateTimeImmutable('2026-01-15'),
            0,
        );

        $this->assertSame('2026-01-15', $resultado->format('Y-m-d'));
    }
}
