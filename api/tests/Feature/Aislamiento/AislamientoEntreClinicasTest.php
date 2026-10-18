<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Consulta;
use App\Models\Mascota;
use App\Models\Tutor;
use App\Models\Vacuna;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * AISLAMIENTO ENTRE CLINICAS, A NIVEL DE BASE DE DATOS.
 *
 * QUE COMPRUEBA
 * -------------
 * Que la Row Level Security impide que la clinica A vea datos de la clinica B,
 * incluso por identificador, y que falle CERRADO cuando no hay contexto.
 *
 * POR QUE ESTO NO SE PUEDE PROBAR CON SQLITE
 * -------------------------------------------
 * Porque en SQLite no hay RLS. Ahi estas pruebas pasarian sin comprobar
 * nada, que es peor que no tenerlas. Ver phpunit.xml, que fija
 * `DB_CONNECTION=pgsql`.
 *
 * QUE NO COMPRUEBA
 * ----------------
 * Que el rol de la base no tenga BYPASSRLS. Eso se comprueba en el servidor,
 * con `clinica:verificar-aislamiento`. Ver docs/03.
 */
class AislamientoEntreClinicasTest extends TestCase
{
    public function test_una_clinica_no_ve_las_mascotas_de_otra(): void
    {
        [$usuarioA] = $this->crearClinicaConProfesional('Clinica A', 'clinica-a');
        [$usuarioB] = $this->crearClinicaConProfesional('Clinica B', 'clinica-b');

        $this->crearTutorYMascota($usuarioA, 'Firulais', '11.111.111-1');
        [, $mascotaB] = $this->crearTutorYMascota($usuarioB, 'Otto', '22.222.222-2');

        $vistasPorA = $this->dentroDe(
            $usuarioA,
            static fn (): array => Mascota::query()->get()->all(),
        );

        $this->assertCount(1, $vistasPorA, 'La clinica A deberia ver solo su propia mascota.');
        $this->assertSame('Firulais', $vistasPorA[0]->nombre);

        // Ni por identificador: el ataque de probar UUID, que es lo que se
        // hace cuando el listado ya esta filtrado y se quiere saltar eso.
        $porId = $this->dentroDe(
            $usuarioA,
            static fn (): int => Mascota::query()->whereKey($mascotaB->id)->count(),
        );

        $this->assertSame(0, $porId, 'La clinica A no debe ver una mascota de la B ni por su identificador.');
    }

    public function test_una_clinica_no_ve_la_historia_clinica_de_otra(): void
    {
        [$usuarioA] = $this->crearClinicaConProfesional('Clinica A', 'clinica-a');
        [$usuarioB] = $this->crearClinicaConProfesional('Clinica B', 'clinica-b');

        [, $mascotaB] = $this->crearTutorYMascota($usuarioB, 'Otto', '22.222.222-2');

        $this->dentroDe($usuarioB, function () use ($mascotaB, $usuarioB): void {
            Consulta::query()->create([
                'tenant_id' => $usuarioB->tenant_id,
                'mascota_id' => $mascotaB->id,
                'profesional_id' => $usuarioB->profesional_id,
                'fecha' => now(),
                'diagnostico' => 'DIAGNOSTICO-DE-LA-CLINICA-B',
            ]);
        });

        $vistasPorA = $this->dentroDe(
            $usuarioA,
            static fn (): array => Consulta::query()->get()->all(),
        );

        $this->assertCount(0, $vistasPorA, 'La clinica A no debe ver consultas de la B.');
        $this->assertStringNotContainsString(
            'DIAGNOSTICO-DE-LA-CLINICA-B',
            (string) json_encode($vistasPorA, JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_una_clinica_no_ve_las_vacunas_de_otra(): void
    {
        [$usuarioA] = $this->crearClinicaConProfesional('Clinica A', 'clinica-a');
        [$usuarioB] = $this->crearClinicaConProfesional('Clinica B', 'clinica-b');

        [, $mascotaB] = $this->crearTutorYMascota($usuarioB, 'Otto', '22.222.222-2');

        $this->dentroDe($usuarioB, function () use ($mascotaB, $usuarioB): void {
            Vacuna::query()->create([
                'tenant_id' => $usuarioB->tenant_id,
                'mascota_id' => $mascotaB->id,
                'profesional_id' => $usuarioB->profesional_id,
                'fecha_aplicacion' => now()->toDateString(),
                'proxima_dose_en' => now()->addYear()->toDateString(),
                'lote' => 'LOTE-PRIVADO-DE-LA-B',
                'estado' => 'aplicada',
            ]);
        });

        $vistasPorA = $this->dentroDe(
            $usuarioA,
            static fn (): array => Vacuna::query()->get()->all(),
        );

        $this->assertCount(0, $vistasPorA);
    }

    public function test_una_clinica_no_ve_los_tutores_de_otra(): void
    {
        [$usuarioA] = $this->crearClinicaConProfesional('Clinica A', 'clinica-a');
        [$usuarioB] = $this->crearClinicaConProfesional('Clinica B', 'clinica-b');

        [$tutorB] = $this->crearTutorYMascota($usuarioB, 'Otto', '22.222.222-2');

        $vistosPorA = $this->dentroDe(
            $usuarioA,
            static fn (): array => Tutor::query()->get()->all(),
        );

        $this->assertCount(0, $vistosPorA);

        $porId = $this->dentroDe(
            $usuarioA,
            static fn (): int => Tutor::query()->whereKey($tutorB->id)->count(),
        );

        $this->assertSame(0, $porId);
    }

    public function test_sin_contexto_la_consulta_falla_y_no_devuelve_filas(): void
    {
        [$usuario] = $this->crearClinicaConProfesional();
        $this->crearTutorYMascota($usuario);

        $lanzo = false;

        try {
            // FUERA del contexto: no hay `app.clinica_id` en la conexion.
            Mascota::query()->count();
        } catch (Throwable) {
            $lanzo = true;
        }

        $this->assertTrue(
            $lanzo,
            'Sin contexto, la consulta debe LANZAR EXCEPCION, no devolver cero filas en silencio.'
        );
    }

    public function test_no_se_puede_escribir_una_fila_de_otra_clinica(): void
    {
        [$usuarioA] = $this->crearClinicaConProfesional('Clinica A', 'clinica-a');
        [$usuarioB] = $this->crearClinicaConProfesional('Clinica B', 'clinica-b');

        [$tutorB] = $this->crearTutorYMascota($usuarioB, 'Otto', '22.222.222-2');

        // Con el contexto de A, se intenta declarar que la fila es de B.
        // `WITH CHECK` tiene que rechazarlo.
        $lanzo = false;

        try {
            $this->dentroDe($usuarioA, function () use ($usuarioA, $tutorB): void {
                Mascota::query()->create([
                    'tenant_id' => $usuarioB->tenant_id,
                    'tutor_id' => $tutorB->id,
                    'nombre' => 'Intrusa',
                    'especie' => 'canino',
                    'activo' => true,
                ]);
            });
        } catch (Throwable) {
            $lanzo = true;
        }

        $this->assertTrue(
            $lanzo,
            'La politica debe impedir escribir una fila declarando el tenant de otra clinica.'
        );
    }

    public function test_el_contexto_desaparece_al_terminar_la_transaccion(): void
    {
        [$usuario] = $this->crearClinicaConProfesional();
        $contexto = app(\App\Services\Tenancy\ContextoTenant::class);

        $dentro = $contexto->ejecutarAislado(
            (string) $usuario->tenant_id,
            static fn (): ?string => $contexto->clinicaIdEnConexion(),
        );

        $this->assertSame((string) $usuario->tenant_id, $dentro);

        // FUERA de la transaccion, el valor tiene que haber desaparecido: con
        // `set_config(..., true)` PostgreSQL lo descarta al confirmar.
        $this->assertNull(
            $contexto->clinicaIdEnConexion(),
            'El contexto debe vivir solo dentro de la transaccion; si sobrevive, el worker de colas puede leer datos de otra clinica.'
        );
    }

    public function test_fijar_el_contexto_fuera_de_una_transaccion_es_error(): void
    {
        $contexto = app(\App\Services\Tenancy\ContextoTenant::class);

        $this->expectException(\RuntimeException::class);

        $contexto->establecer('00000000-0000-0000-0000-000000000000');
    }

    public function test_la_funcion_de_contexto_devuelve_la_clinica_fijada(): void
    {
        [$usuario] = $this->crearClinicaConProfesional();

        $valor = $this->dentroDe(
            $usuario,
            static fn (): ?string => DB::selectOne('SELECT public.app_clinica_id()::text AS id')->id,
        );

        $this->assertSame((string) $usuario->tenant_id, $valor);
    }
}
