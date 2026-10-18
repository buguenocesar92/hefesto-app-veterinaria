<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agenda;

use App\Enums\EstadoCita;
use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\Mascota;
use App\Models\TipoAtencion;
use App\Services\Agenda\CacheAgenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Citas: crear, cambiar y cancelar.
 *
 * LA DURACION NO LA DECIDE QUIEN AGENDA
 * -------------------------------------
 * Sale de `tipos_atencion.duracion_minutos`. Si la escribiera la persona que
 * agenda, dos personas que agenden la misma atencion ocuparian distinta
 * cantidad de tiempo, y el bloque de la tarde se desarmaria.
 *
 * EL DOBLE booked LO IMPONE POSTGRESQL
 * -----------------------------------
 * La migration de `citas` crea una constraint `EXCLUDE USING gist` sobre el
 * rango `[inicio, fin)` de cada profesional. Si dos citas se solapan, la base
 * las rechaza.
 *
 * Por que en la base y no en la aplicacion: porque dos personas pueden agendar
 * **a la vez** (dos recepciones, dos pestanas). Si la comprobacion estuviera en
 * PHP, las dos pasarían el `if` y las dos guardarían.
 *
 * TODA ESCRITURA INVALIDA LA CACHE DE LA AGENDA
 * ---------------------------------------------
 * Si no, la pantalla muestra una agenda vieja. Ver App\Services\Agenda\CacheAgenda.
 */
class CitaController extends Controller
{
    public function __construct(private readonly CacheAgenda $cache) {}

    public function index(Request $peticion): JsonResponse
    {
        if (! $peticion->user()->rol->puedeUsarAgenda()) {
            abort(404, 'No encontrado.');
        }

        $citas = Cita::query()
            ->when($peticion->query('mascota_id'), fn ($q, $v) => $q->where('mascota_id', $v))
            ->when($peticion->query('profesional_id'), fn ($q, $v) => $q->where('profesional_id', $v))
            ->whereBetween('inicio', [
                $peticion->query('desde', now()->toDateString()) . ' 00:00:00',
                $peticion->query('hasta', now()->addWeek()->toDateString()) . ' 23:59:59',
            ])
            ->with(['mascota:id,nombre', 'tipoAtencion:id,nombre'])
            ->orderBy('inicio')
            ->get()
            ->map(static fn (Cita $c): array => [
                'id' => $c->id,
                'inicio' => $c->inicio?->toDateTimeString(),
                'estado' => $c->estado->value,
                'mascota' => $c->mascota?->nombre,
                'tipo_atencion' => $c->tipoAtencion?->nombre,
            ])->all();

        return response()->json(['citas' => $citas]);
    }

    public function store(Request $peticion): JsonResponse
    {
        if (! $peticion->user()->rol->puedeUsarAgenda()) {
            abort(404, 'No encontrado.');
        }

        $datos = $peticion->validate([
            'mascota_id' => ['required', 'uuid'],
            'profesional_id' => ['required', 'uuid'],
            'tipo_atencion_id' => ['required', 'uuid'],
            'inicio' => ['required', 'date'],
            'motivo' => ['nullable', 'string', 'max:240'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ], [
            'mascota_id.required' => 'Indique la mascota.',
            'inicio.required' => 'Indique la hora de inicio.',
        ]);

        $mascota = Mascota::query()->whereKey($datos['mascota_id'])->first();

        if ($mascota === null) {
            return $this->error('La mascota no existe en esta clinica.');
        }

        $tipo = TipoAtencion::query()->whereKey($datos['tipo_atencion_id'])->first();

        if ($tipo === null || ! $tipo->activo) {
            return $this->error('El tipo de atencion no existe o esta inactivo.');
        }

        $inicio = Carbon::parse($datos['inicio']);
        $fin = $inicio->copy()->addMinutes($tipo->duracion_minutos);

        if ($fin->diffInMinutes($inicio) > (int) config('veterinaria.duracion_maxima_minutos', 240)) {
            return $this->error('La atencion excede la duracion maxima permitida.');
        }

        $cita = DB::transaction(function () use ($datos, $mascota, $inicio, $fin): Cita {
            return Cita::query()->create([
                'tenant_id' => $mascota->tenant_id,
                'mascota_id' => $mascota->id,
                // Se congela el tutor en la cita: si el animal cambia de
                // tutor despues, las citas ya agendadas siguen diciendo a
                // quien hay que llamar. Ver la migracion de `citas`.
                'tutor_id' => $mascota->tutor_id,
                'profesional_id' => $datos['profesional_id'],
                'tipo_atencion_id' => $datos['tipo_atencion_id'],
                'inicio' => $inicio,
                'fin' => $fin,
                'estado' => EstadoCita::Pendiente,
                'motivo' => $datos['motivo'] ?? null,
                'notas' => $datos['notas'] ?? null,
            ]);
        });

        $this->cache->olvidar((string) $mascota->tenant_id, $inicio->toDateString(), $datos['profesional_id']);

        return response()->json([
            'mensaje' => 'Cita agendada.',
            'cita' => [
                'id' => $cita->id,
                'inicio' => $cita->inicio?->toDateTimeString(),
                'fin' => $cita->fin?->toDateTimeString(),
                'duracion_minutos' => $tipo->duracion_minutos,
                'bloque_vacunacion' => (bool) $tipo->bloque_vacunacion,
            ],
        ], 201);
    }

    public function update(Request $peticion, Cita $cita): JsonResponse
    {
        if (! $peticion->user()->rol->puedeUsarAgenda()) {
            abort(404, 'No encontrado.');
        }

        $datos = $peticion->validate([
            'estado' => ['sometimes', 'string', 'in:' . implode(',', array_column(EstadoCita::cases(), 'value'))],
            'inicio' => ['sometimes', 'date'],
            'motivo' => ['nullable', 'string', 'max:240'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenantId = (string) $cita->tenant_id;
        $fechaOriginal = $cita->inicio?->toDateString();

        // Un estado final no se reabre: reabrir una cita atendida volveria a
        // tapar un horario que ya se le dio a otro animal.
        if (isset($datos['estado'])) {
            $destino = EstadoCita::from($datos['estado']);

            if (! $cita->estado->puedePasarA($destino)) {
                return $this->error(
                    'No se puede pasar de «' . $cita->estado->etiqueta() . '» a «' . $destino->etiqueta() . '».'
                );
            }
        }

        if (isset($datos['inicio'])) {
            $inicio = Carbon::parse($datos['inicio']);
            $tipo = TipoAtencion::query()->find($cita->tipo_atencion_id);

            $cita->inicio = $inicio;
            $cita->fin = $inicio->copy()->addMinutes($tipo?->duracion_minutos ?? 30);
        }

        $cita->fill($datos)->save();

        // Las dos fechas: la que se toco y la que estaba antes.
        $this->cache->olvidar($tenantId, $cita->inicio?->toDateString() ?? '', (string) $cita->profesional_id);

        if ($fechaOriginal !== null && $fechaOriginal !== $cita->inicio?->toDateString()) {
            $this->cache->olvidar($tenantId, $fechaOriginal, (string) $cita->profesional_id);
        }

        return response()->json([
            'mensaje' => 'Cita actualizada.',
            'cita' => [
                'id' => $cita->id,
                'estado' => $cita->estado->value,
                'inicio' => $cita->inicio?->toDateTimeString(),
            ],
        ]);
    }
}
