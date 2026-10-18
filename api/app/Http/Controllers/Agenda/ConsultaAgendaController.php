<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agenda;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\Profesional;
use App\Services\Agenda\CacheAgenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La agenda del dia.
 *
 * POR QUE VA POR CACHE
 * -------------------
 * Es la pantalla que mas se mira y la que mas veces se abre al dia. Armarla
 * cuesta varios `join` con `mascotas` y `tutores`, y su contenido cambia
 * pocas veces: la lista de un profesional para una fecha dada.
 *
 * Por eso va por Redis con clave por clinica, profesional y fecha, y se
 * invalida cuando se toca una cita. Ver App\Services\Agenda\CacheAgenda.
 *
 * LA CACHE NUNCA CAMBIA EL AISLAMIENTO
 * -----------------------------------
 * La clave incluye el `tenant_id`. Es lo unico que sostiene el aislamiento de
 * la cache: si dos clinicas compartieran clave, una veria la agenda de la
 * otra, y la RLS no podria evitarlo porque la consulta ya ocurrio.
 *
 * POR QUE EL BLOQUE DE VACUNACION SE MUESTRA APARTE
 * -------------------------------------------------
 * Porque la agenda mezcla dos ritmos de trabajo: una consulta de 40 minutos y
 * una vacuna de 10. Verlas en la misma lista hace que la gente que va por una
 * vacuna espere como si fuera consulta, que es la queja clasica de una clinica
 * veterinaria. Ver docs/02.
 */
class ConsultaAgendaController extends Controller
{
    public function __invoke(Request $peticion, CacheAgenda $cache): JsonResponse
    {
        if (! $peticion->user()->rol->puedeUsarAgenda()) {
            abort(404, 'No encontrado.');
        }

        $fecha = $peticion->query('fecha', now()->toDateString());
        $profesionalId = $peticion->query('profesional_id');
        $bloque = $peticion->query('bloque', 'todos');

        $agenda = $cache->recordar(
            (string) $peticion->user()->tenant_id,
            $fecha,
            $profesionalId !== null ? (string) $profesionalId : null,
            static fn (): array => self::construir($fecha, $profesionalId !== null ? (string) $profesionalId : null),
        );

        $citas = $agenda;

        if ($bloque === 'vacunacion') {
            $citas = array_values(array_filter(
                $citas,
                static fn (array $cita): bool => ($cita['bloque_vacunacion'] ?? false) === true,
            ));
        } elseif ($bloque === 'consultas') {
            $citas = array_values(array_filter(
                $citas,
                static fn (array $cita): bool => ($cita['bloque_vacunacion'] ?? false) === false,
            ));
        }

        return response()->json([
            'fecha' => $fecha,
            'profesionales' => Profesional::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Profesional $p): array => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'especialidad' => $p->especialidad,
                ])->all(),
            'citas' => $citas,
        ]);
    }

    /**
     * Arma la agenda de una fecha. Esto es lo que se cachea.
     *
     * @return list<array<string, mixed>>
     */
    private static function construir(string $fecha, ?string $profesionalId): array
    {
        return Cita::query()
            ->whereDate('inicio', $fecha)
            ->when($profesionalId !== null, fn ($q) => $q->where('profesional_id', $profesionalId))
            // Los estados finales no ocupan agenda, asi que no se muestran.
            // Ver App\Enums\EstadoCita::ocupaAgenda.
            ->whereIn('estado', ['pendiente', 'confirmada', 'en_curso'])
            ->with([
                'mascota:id,nombre,especie',
                'tutor:id,nombres,apellidos,telefono',
                'profesional:id,nombre',
                'tipoAtencion:id,nombre,duracion_minutos,bloque_vacunacion',
            ])
            ->orderBy('inicio')
            ->get()
            ->map(static fn (Cita $cita): array => [
                'id' => $cita->id,
                'inicio' => $cita->inicio?->toDateTimeString(),
                'fin' => $cita->fin?->toDateTimeString(),
                'estado' => $cita->estado->value,
                'bloque_vacunacion' => (bool) $cita->tipoAtencion?->bloque_vacunacion,
                'tipo_atencion' => $cita->tipoAtencion?->nombre,
                'mascota' => $cita->mascota?->nombre,
                'especie' => $cita->mascota?->especie->etiqueta(),
                'tutor' => $cita->tutor?->nombreCompleto(),
                'telefono' => $cita->tutor?->telefono,
                'profesional' => $cita->profesional?->nombre,
                'motivo' => $cita->motivo,
            ])
            ->all();
    }
}
