<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Especie;
use App\Enums\EstadoCita;
use App\Enums\EstadoVacuna;
use App\Enums\RolClinico;
use App\Enums\Sexo;
use App\Enums\VigenciaVacuna;
use PHPUnit\Framework\TestCase;

/**
 * LAS REGLAS QUE ESTAN EN EL CODIGO, COMPROBADAS SIN BASE DE DATOS.
 *
 * Son enums: logica pura, sin Eloquent ni PostgreSQL. Se prueban con PHPUnit a
 * secas y sin levantar nada.
 */
class EnumsTest extends TestCase
{
    public function test_hay_cuatro_roles_y_solo_uno_abre_fichas_clinicas(): void
    {
        $this->assertCount(4, RolClinico::cases());

        $queAbren = array_values(array_filter(
            RolClinico::cases(),
            static fn (RolClinico $rol): bool => $rol->puedeLeerFichaClinica(),
        ));

        $this->assertCount(1, $queAbren);
        $this->assertSame(RolClinico::Profesional, $queAbren[0]);
    }

    public function test_la_administracion_no_prescribe(): void
    {
        // Un rol de direccion que pudiera prescribir seria un problema: la
        // prescripcion veterinaria la firma quien tiene registro profesional.
        foreach (RolClinico::cases() as $rol) {
            if ($rol->puedePrescribir()) {
                $this->assertSame(RolClinico::Profesional, $rol);
            }
        }
    }

    public function test_el_mostrador_no_usa_la_agenda(): void
    {
        $this->assertFalse(RolClinico::Mostrador->puedeUsarAgenda());
        $this->assertTrue(RolClinico::Mostrador->puedeUsarMostrador());

        $this->assertTrue(RolClinico::Recepcion->puedeUsarAgenda());
        $this->assertTrue(RolClinico::Profesional->puedeUsarAgenda());
    }

    public function test_los_estados_finales_de_cita_no_ocupan_agenda(): void
    {
        foreach (EstadoCita::cases() as $estado) {
            if (in_array($estado, [EstadoCita::Atendida, EstadoCita::NoAsistio, EstadoCita::Cancelada], true)) {
                $this->assertFalse(
                    $estado->ocupaAgenda(),
                    "El estado {$estado->value} no deberia ocupar agenda: el horario quedaria tomado para siempre."
                );
                $this->assertTrue($estado->esFinal());
            } else {
                $this->assertTrue($estado->ocupaAgenda(), "El estado {$estado->value} deberia ocupar agenda.");
                $this->assertFalse($estado->esFinal());
            }
        }
    }

    public function test_un_estado_final_no_se_puede_reabrir(): void
    {
        // Reabrir una cita atendida volveria a tapar un horario que ya se le
        // dio a otro animal.
        foreach ([EstadoCita::Atendida, EstadoCita::NoAsistio, EstadoCita::Cancelada] as $final) {
            foreach (EstadoCita::cases() as $destino) {
                $this->assertFalse(
                    $final->puedePasarA($destino),
                    "Una cita {$final->value} no deberia poder pasar a {$destino->value}."
                );
            }
        }
    }

    public function test_una_cita_se_puede_confirmar_y_luego_atender(): void
    {
        $this->assertTrue(EstadoCita::Pendiente->puedePasarA(EstadoCita::Confirmada));
        $this->assertTrue(EstadoCita::Confirmada->puedePasarA(EstadoCita::Atendida));

        // Y desde pendiente se puede saltar directo a atendida: en una clinica
        // real la atencion pasa a atendida sin pasar por confirmada.
        $this->assertTrue(EstadoCita::Pendiente->puedePasarA(EstadoCita::Atendida));
    }

    public function test_el_vacunado_solo_tiene_aplicada_y_anulada(): void
    {
        // "Vigente", "por vencer" y "atrasada" NO son estados guardados: son
        // derivaciones. Si estuvieran aca, quedarian viejos solos.
        $this->assertCount(2, EstadoVacuna::cases());

        $this->assertSame(
            ['aplicada', 'anulada'],
            array_map(static fn (EstadoVacuna $e): string => $e->value, EstadoVacuna::cases()),
        );

        $this->assertTrue(EstadoVacuna::Aplicada->cuenta());
        $this->assertFalse(EstadoVacuna::Anulada->cuenta());
    }

    public function test_las_especies_tienen_etiqueta_en_espanol(): void
    {
        foreach (Especie::cases() as $especie) {
            $this->assertNotSame('', $especie->etiqueta());
        }

        // Los reptiles no tienen calendario rutinario en la mayoria de los
        // casos; el sistema no ofrece formulario para ellos.
        $this->assertFalse(Especie::Reptil->admiteEsquemaVacunacion());
        $this->assertTrue(Especie::Canino->admiteEsquemaVacunacion());
        $this->assertTrue(Especie::Felino->admiteEsquemaVacunacion());
    }

    public function test_existe_el_sexo_no_informado(): void
    {
        // Si el formulario obliga a elegir macho o hembra, alguien inventa el
        // dato y la invencion queda en la ficha para siempre.
        $this->assertSame(Sexo::NoInformado, Sexo::from('no_informado'));
        $this->assertFalse(Sexo::NoInformado->informado());
        $this->assertTrue(Sexo::Macho->informado());
        $this->assertTrue(Sexo::Hembra->informado());
    }

    public function test_solo_dos_vigencias_piden_seguimiento(): void
    {
        $que_avisan = array_values(array_filter(
            VigenciaVacuna::cases(),
            static fn (VigenciaVacuna $v): bool => $v->requiereAviso(),
        ));

        $this->assertCount(2, $que_avisan);
        $this->assertSame(VigenciaVacuna::Atrasada, $que_avisan[0]);
        $this->assertSame(VigenciaVacuna::PorVencer, $que_avisan[1]);
    }

    public function test_todas_las_etiquetas_estan_en_espanol_y_sin_caracteres_raros(): void
    {
        $etiquetas = [];

        foreach (RolClinico::cases() as $c) {
            $etiquetas[] = $c->etiqueta();
        }
        foreach (Especie::cases() as $c) {
            $etiquetas[] = $c->etiqueta();
        }
        foreach (Sexo::cases() as $c) {
            $etiquetas[] = $c->etiqueta();
        }
        foreach (EstadoCita::cases() as $c) {
            $etiquetas[] = $c->etiqueta();
        }
        foreach (EstadoVacuna::cases() as $c) {
            $etiquetas[] = $c->etiqueta();
        }
        foreach (VigenciaVacuna::cases() as $c) {
            $etiquetas[] = $c->etiqueta();
        }

        foreach ($etiquetas as $etiqueta) {
            $this->assertMatchesRegularExpression(
                '/^[\p{Latin}\p{Zs}]+$/u',
                $etiqueta,
                "La etiqueta «{$etiqueta}» tiene caracteres que no corresponden a español."
            );
        }
    }
}
