<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Consulta;
use App\Models\Mascota;
use App\Models\Vacuna;
use Tests\TestCase;

/**
 * QUE EL HISTORIAL DE UNA MASCOTA NO SE MEZCLE CON EL DE OTRA DEL MISMO TUTOR
 * ===========================================================================
 *
 * POR QUE ESTA PRUEBA ES LA MAS IMPORTANTE DEL DOMINIO
 * ----------------------------------------------------
 * La RLS aisla entre CLINICAS. Esta suite mide otra cosa, y mas dificil: que
 * dentro de la misma clinica, dos mascotas del mismo tutor no mezclen sus
 * historias.
 *
 * El riesgo no es el atacante: es el error de buena fe. Alguien abre la ficha
 * de "Firulais" y el sistema, por un JOIN o por un atajo, le muestra tambien
 * la de "Michi". El diagnostico del gato aparece pegado al del perro, el peso
 * de una se compara con la temperatura de la otra, y la prescripcion se
 * calcula sobre el animal equivocado.
 *
 * El dano es doble:
 *
 *  - CLINICO: una dosis calculada sobre el peso de otra mascota puede dañar al
 *    animal.
 *  - DE PRIVACIDAD: el historial de una mascota identifica a una persona y a
 *    su domicilio. Que dos historias se mezclen es una fuga, no un error de
 *    formato. Ver docs/07.
 *
 * POR QUE SE COMPRUEBA EL CONTENIDO Y NO SOLO EL CONTEO
 * -----------------------------------------------------
 * Una consulta que devolviera la fila de la otra mascota con el diagnostico en
 * otro campo pasaria un `assertCount` y seria una fuga igual. Por eso se
 * busca el TEXTO del diagnostico ajeno en la respuesta completa.
 */
class HistorialNoSeMezclaEntreMascotasDelMismoTutorTest extends TestCase
{
    public function test_la_ficha_de_una_mascota_no_trae_el_historial_de_la_otra_del_mismo_tutor(): void
    {
        [$usuario] = $this->crearClinicaConProfesional();

        [$tutor, $perro] = $this->crearTutorYMascota($usuario, 'Firulais', '11.111.111-1');
        $gato = $this->crearSegundaMascotaDelTutor($usuario, $tutor->id, 'Michi', 'felino');

        // EXIGENCIA PREVIA: sin esto, la prueba no mide lo que dice medir.
        $this->assertSame(
            (string) $perro->tutor_id,
            (string) $gato->tutor_id,
            'La prueba no sirve de nada si las dos mascotas no son del mismo tutor.'
        );

        $this->registrarConsulta($usuario, $perro->id, 'DIAGNOSTICO-SOLO-DEL-PERRO', now()->subDay());
        $this->registrarConsulta($usuario, $gato->id, 'DIAGNOSTICO-SOLO-DEL-GATO', now()->subDays(3));

        $historialDelPerro = $this->dentroDe(
            $usuario,
            static fn (): array => Consulta::query()
                ->where('mascota_id', $perro->id)
                ->orderBy('fecha')
                ->get()
                ->all(),
        );

        $this->assertCount(1, $historialDelPerro, 'El perro deberia tener exactamente una consulta.');

        $cuerpo = (string) json_encode($historialDelPerro, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString(
            'DIAGNOSTICO-SOLO-DEL-PERRO',
            $cuerpo,
            'La ficha del perro deberia traer su propio diagnostico.'
        );

        $this->assertStringNotContainsString(
            'DIAGNOSTICO-SOLO-DEL-GATO',
            $cuerpo,
            'La ficha del perro NO debe traer el diagnostico del gato del mismo tutor.'
        );

        $this->assertStringNotContainsString(
            'Michi',
            $cuerpo,
            'La ficha del perro no debe mencionar al gato, ni siquiera de paso.'
        );
    }

    public function test_el_historial_de_la_otra_mascota_sigue_existente_y_no_se_perdio(): void
    {
        [$usuario] = $this->crearClinicaConProfesional();

        [$tutor, $perro] = $this->crearTutorYMascota($usuario, 'Firulais', '11.111.111-1');
        $gato = $this->crearSegundaMascotaDelTutor($usuario, $tutor->id, 'Michi', 'felino');

        $this->registrarConsulta($usuario, $perro->id, 'DIAGNOSTICO-SOLO-DEL-PERRO', now()->subDay());
        $this->registrarConsulta($usuario, $gato->id, 'DIAGNOSTICO-SOLO-DEL-GATO', now()->subDays(3));

        $historialDelGato = $this->dentroDe(
            $usuario,
            static fn (): array => Consulta::query()
                ->where('mascota_id', $gato->id)
                ->orderBy('fecha')
                ->get()
                ->all(),
        );

        $this->assertCount(1, $historialDelGato);

        $cuerpo = (string) json_encode($historialDelGato, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('DIAGNOSTICO-SOLO-DEL-GATO', $cuerpo);
        $this->assertStringNotContainsString('DIAGNOSTICO-SOLO-DEL-PERRO', $cuerpo);
    }

    public function test_las_vacunas_de_una_mascota_no_se_mezclan_con_las_de_la_otra(): void
    {
        [$usuario] = $this->crearClinicaConProfesional();

        [$tutor, $perro] = $this->crearTutorYMascota($usuario, 'Firulais', '11.111.111-1');
        $gato = $this->crearSegundaMascotaDelTutor($usuario, $tutor->id, 'Michi', 'felino');

        $this->registrarVacuna($usuario, $perro->id, 'LOTE-SOLO-DEL-PERRO', now()->addMonth());
        $this->registrarVacuna($usuario, $gato->id, 'LOTE-SOLO-DEL-GATO', now()->subMonths(2));

        $vacunasDelPerro = $this->dentroDe(
            $usuario,
            static fn (): array => Vacuna::query()
                ->where('mascota_id', $perro->id)
                ->get()
                ->all(),
        );

        $this->assertCount(1, $vacunasDelPerro);

        $cuerpo = (string) json_encode($vacunasDelPerro, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('LOTE-SOLO-DEL-PERRO', $cuerpo);
        $this->assertStringNotContainsString(
            'LOTE-SOLO-DEL-GATO',
            $cuerpo,
            'El calendario del perro no debe traer el lote de la vacuna del gato.'
        );
    }

    public function test_las_prescripciones_de_una_mascota_no_se_mezclan_con_las_de_la_otra(): void
    {
        [$usuario] = $this->crearClinicaConProfesional();

        [$tutor, $perro] = $this->crearTutorYMascota($usuario, 'Firulais', '11.111.111-1');
        $gato = $this->crearSegundaMascotaDelTutor($usuario, $tutor->id, 'Michi', 'felino');

        $this->dentroDe($usuario, function () use ($perro, $gato, $usuario): void {
            \App\Models\Prescripcion::query()->create([
                'tenant_id' => $usuario->tenant_id,
                'mascota_id' => $perro->id,
                'profesional_id' => $usuario->profesional_id,
                'fecha' => now(),
                'medicamento' => 'MEDICAMENTO-SOLO-DEL-PERRO',
                'dosis' => '1 comprimido',
                'estado' => 'emitida',
            ]);

            \App\Models\Prescripcion::query()->create([
                'tenant_id' => $usuario->tenant_id,
                'mascota_id' => $gato->id,
                'profesional_id' => $usuario->profesional_id,
                'fecha' => now(),
                'medicamento' => 'MEDICAMENTO-SOLO-DEL-GATO',
                'dosis' => '0,5 ml',
                'estado' => 'emitida',
            ]);
        });

        $prescripcionesDelPerro = $this->dentroDe(
            $usuario,
            static fn (): array => \App\Models\Prescripcion::query()
                ->where('mascota_id', $perro->id)
                ->get()
                ->all(),
        );

        $cuerpo = (string) json_encode($prescripcionesDelPerro, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('MEDICAMENTO-SOLO-DEL-PERRO', $cuerpo);
        $this->assertStringNotContainsString(
            'MEDICAMENTO-SOLO-DEL-GATO',
            $cuerpo,
            'Las recetas del perro no deben traer el medicamento recetado al gato.'
        );
    }

    /**
     * Una segunda mascota del mismo tutor, con otra especie a proposito.
     */
    private function crearSegundaMascotaDelTutor(
        \App\Models\Usuario $usuario,
        string $tutorId,
        string $nombre,
        string $especie,
    ): Mascota {
        return $this->dentroDe($usuario, static fn (): Mascota => Mascota::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'tutor_id' => $tutorId,
            'nombre' => $nombre,
            'especie' => $especie,
            'raza' => 'Europeo',
            'sexo' => 'hembra',
            'fecha_nacimiento' => now()->subYears(2)->toDateString(),
            'peso_kg' => 4.2,
            'activo' => true,
        ]));
    }

    private function registrarConsulta(
        \App\Models\Usuario $usuario,
        string $mascotaId,
        string $diagnostico,
        \Illuminate\Support\Carbon $fecha,
    ): void {
        $this->dentroDe($usuario, static function () use ($mascotaId, $diagnostico, $fecha, $usuario): void {
            Consulta::query()->create([
                'tenant_id' => $usuario->tenant_id,
                'mascota_id' => $mascotaId,
                'profesional_id' => $usuario->profesional_id,
                'fecha' => $fecha,
                'motivo_consulta' => 'Motivo de prueba',
                'diagnostico' => $diagnostico,
            ]);
        });
    }

    private function registrarVacuna(
        \App\Models\Usuario $usuario,
        string $mascotaId,
        string $lote,
        \Illuminate\Support\Carbon $proximaDosis,
    ): void {
        $this->dentroDe($usuario, static function () use ($mascotaId, $lote, $proximaDosis, $usuario): void {
            Vacuna::query()->create([
                'tenant_id' => $usuario->tenant_id,
                'mascota_id' => $mascotaId,
                'profesional_id' => $usuario->profesional_id,
                'fecha_aplicacion' => now()->subMonths(2)->toDateString(),
                'proxima_dose_en' => $proximaDosis->toDateString(),
                'lote' => $lote,
                'estado' => 'aplicada',
            ]);
        });
    }
}
