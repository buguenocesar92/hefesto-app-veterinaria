<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\VigenciaVacuna;
use App\Services\Vacunas\CalculadorDeVigencia;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LA VIGENCIA DE UNA DOSIS, SIN BASE DE DATOS.
 *
 * QUE SEPARA ESTA PRUEBA DE LA ANTERIOR
 * -------------------------------------
 * `CalculadorDeProximaDosis` produce la FECHA (un hecho guardado).
 * `CalculadorDeVigencia` dice si esa fecha ya PASO (una derivacion que
 * depende de hoy).
 *
 * La prueba fija "hoy" a proposito. Una prueba que usara `now()` comprobaria
 * la fecha en que se corre, no el calculo, y el dia que mas importa (el
 * exacto de la fecha de vencimiento) no lo comprobaria nunca.
 *
 * Por eso `deProximaDosis()` recibe `$hoy` por parametro.
 */
class CalculadorDeVigenciaTest extends TestCase
{
    private function hoy(): Carbon
    {
        return Carbon::parse('2026-06-15');
    }

    public function test_una_dosis_lejana_esta_vigente(): void
    {
        $vigencia = CalculadorDeVigencia::deProximaDosis(
            Carbon::parse('2026-12-01'),
            $this->hoy(),
        );

        $this->assertSame(VigenciaVacuna::Vigente, $vigencia);
        $this->assertFalse($vigencia->requiereAviso());
    }

    public function test_una_dosis_dentro_de_la_ventana_esta_por_vencer(): void
    {
        // Ventana por omision: 30 dias.
        $vigencia = CalculadorDeVigencia::deProximaDosis(
            Carbon::parse('2026-07-01'),
            $this->hoy(),
        );

        $this->assertSame(VigenciaVacuna::PorVencer, $vigencia);
        $this->assertTrue($vigencia->requiereAviso(), 'Una dosis por vencer debe pedir seguimiento.');
    }

    public function test_una_dosis_vencida_esta_atrasada(): void
    {
        $vigencia = CalculadorDeVigencia::deProximaDosis(
            Carbon::parse('2026-03-01'),
            $this->hoy(),
        );

        $this->assertSame(VigenciaVacuna::Atrasada, $vigencia);
        $this->assertTrue($vigencia->requiereAviso());
    }

    public function test_el_dia_mismo_de_la_vencimiento_no_esta_atrasada(): void
    {
        $vigencia = CalculadorDeVigencia::deProximaDosis(
            Carbon::parse('2026-06-15'),
            $this->hoy(),
        );

        // Vence HOY: todavia no esta vencida, esta por vencer. Marcarlo como
        // atrasada haria aparecer en el tablero a quien esta al dia.
        $this->assertSame(VigenciaVacuna::PorVencer, $vigencia);
    }

    public function test_sin_proxima_dosis_no_es_un_vencimiento_fallido(): void
    {
        $vigencia = CalculadorDeVigencia::deProximaDosis(null, $this->hoy());

        // La ultima dosis de un calendario no tiene proxima. Eso es una
        // AUSENCIA de informacion, no un atraso.
        $this->assertSame(VigenciaVacuna::SinProxima, $vigencia);
        $this->assertFalse($vigencia->requiereAviso());
    }

    public function test_la_comparacion_ignora_la_hora_del_dia(): void
    {
        $vigencia = CalculadorDeVigencia::deProximaDosis(
            Carbon::parse('2026-06-15 00:00:00'),
            Carbon::parse('2026-06-15 23:59:59'),
        );

        // Si se comparara con marca de tiempo, las 23:59 del dia de la
        // vencimiento contarian como vencido y el animal apareceria atrasado
        // unas horas antes de tiempo.
        $this->assertSame(VigenciaVacuna::PorVencer, $vigencia);
    }

    public function test_la_ventana_por_vencer_es_configurable(): void
    {
        config(['veterinaria.vacunas.ventana_por_vencer_dias' => 7]);

        $vigencia = CalculadorDeVigencia::deProximaDosis(
            Carbon::parse('2026-06-20'),
            $this->hoy(),
        );

        // Con ventana de 7 dias, el 20 de junio ya esta "por vencer".
        $this->assertSame(VigenciaVacuna::PorVencer, $vigencia);
    }

    public function test_cada_estado_dice_su_etiqueta_en_espanol(): void
    {
        // La interfaz muestra estas etiquetas a una persona que atiende
        // animales. Si una sale en ingles o con caracteres raros, se ve roto
        // en pantalla.
        foreach (VigenciaVacuna::cases() as $estado) {
            $etiqueta = $estado->etiqueta();

            $this->assertNotSame('', $etiqueta);

            $this->assertMatchesRegularExpression(
                '/^[\p{Latin}\p{Pattern_Syntax}\p{Zs}]+$/u',
                $etiqueta,
                "La etiqueta de {$estado->value} tiene caracteres inesperados: {$etiqueta}"
            );
        }
    }
}
