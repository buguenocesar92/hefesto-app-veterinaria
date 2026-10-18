<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\RolClinico;
use App\Models\Clinica;
use App\Models\Mascota;
use App\Models\Profesional;
use App\Models\Tutor;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base de las pruebas de VeterinariaHefesto.
 *
 * POR QUE TODAS LAS PRUEBAS NECESITAN `dentroDe`
 * ---------------------------------------------
 * Las tablas de negocio tienen Row Level Security FORZADO, para que las
 * politicas apliquen tambien al dueno de la tabla (que es el usuario con el
 * que se conecta Laravel). Consecuencia: si una prueba crea filas sin declarar
 * la clinica a la que pertenecen, la base las rechaza.
 *
 * Eso no es un estorbo para probar, es parte de lo que se prueba. `dentroDe`
 * recorre el mismo camino que la peticion real: una transaccion con el
 * contexto fijado.
 *
 * POSTGRESQL Y NO SQLITE
 * ---------------------
 * En SQLite estas pruebas no comprobarian nada, porque no hay RLS. Ver
 * phpunit.xml.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Ejecuta una operacion dentro del contexto de una clinica.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    protected function dentroDe(Usuario|Clinica $contexto, Closure $operacion): mixed
    {
        $clinicaId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return app(ContextoTenant::class)->ejecutarAislado($clinicaId, $operacion);
    }

    /**
     * Crea una clinica con un profesional y su usuario.
     *
     * @return array{0: Usuario, 1: Profesional}
     */
    protected function crearClinicaConProfesional(
        string $nombre = 'Clinica de prueba',
        string $correlacion = 'clinica-de-prueba',
    ): array {
        $clinica = Clinica::query()->create([
            'slug' => $correlacion,
            'nombre' => $nombre,
            'zona_horaria' => 'America/Santiago',
            'correlacion_identidad' => $correlacion,
            'activo' => true,
        ]);

        $clinicaId = (string) $clinica->id;

        [$usuario, $profesional] = $this->dentroDe($clinica, function () use ($clinicaId): array {
            $profesional = Profesional::query()->create([
                'tenant_id' => $clinicaId,
                'nombre' => 'Dra. Prueba Vet',
                'registro_profesional' => 'REG-PRUEBA',
                'activo' => true,
            ]);

            $usuario = Usuario::query()->create([
                'tenant_id' => $clinicaId,
                'nombre' => 'Dra. Prueba Vet',
                'correo' => 'profesional@prueba.local',
                'rol' => RolClinico::Profesional,
                'profesional_id' => $profesional->id,
                'activo' => true,
            ]);

            return [$usuario, $profesional];
        });

        return [$usuario, $profesional];
    }

    /**
     * Crea un usuario con un rol, sin profesional asociado.
     */
    protected function crearUsuario(Usuario|Clinica $contexto, RolClinico $rol, string $correo): Usuario
    {
        $clinicaId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Usuario => Usuario::query()->create([
            'tenant_id' => $clinicaId,
            'nombre' => 'Persona de prueba',
            'correo' => $correo,
            'rol' => $rol,
            'activo' => true,
        ]));
    }

    /**
     * Crea un tutor con una mascota.
     *
     * @return array{0: Tutor, 1: Mascota}
     */
    protected function crearTutorYMascota(
        Usuario|Clinica $contexto,
        string $nombreMascota = 'Firulais',
        string $rut = '11.111.111-1',
    ): array {
        $clinicaId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, function () use ($clinicaId, $nombreMascota, $rut): array {
            $tutor = Tutor::query()->create([
                'tenant_id' => $clinicaId,
                'rut' => $rut,
                'nombres' => 'Maria',
                'apellidos' => 'Gonzalez',
                'telefono' => '+56912345678',
                'activo' => true,
            ]);

            $mascota = Mascota::query()->create([
                'tenant_id' => $clinicaId,
                'tutor_id' => $tutor->id,
                'nombre' => $nombreMascota,
                'especie' => 'canino',
                'raza' => 'Mestizo',
                'sexo' => 'macho',
                'fecha_nacimiento' => now()->subYears(3)->toDateString(),
                'peso_kg' => 18.5,
                'activo' => true,
            ]);

            return [$tutor, $mascota];
        });
    }
}
