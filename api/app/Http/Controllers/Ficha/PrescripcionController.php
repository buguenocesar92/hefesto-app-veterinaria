<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ficha;

use App\Enums\EstadoPrescripcion;
use App\Http\Controllers\Controller;
use App\Models\Mascota;
use App\Models\Prescripcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prescripciones veterinarias.
 *
 * LO QUE ESTA CLASE NO HACE
 * -------------------------
 * **No valida la prescripcion.** No comprueba si el farmaco existe, ni la
 * dosis, ni si corresponde a la especie, ni si ese producto exige receta
 * retenida.
 *
 * Eso es del profesional que la firma, con su registro profesional vigente.
 * En Chile la prescripcion veterinaria se rige por la Ley 20.584 y su
 * reglamento, y **ese marco no esta verificado en este entorno**. Ver docs/07.
 *
 * Un sistema que "validara" la decision clinica solo le daria al profesional
 * la ilusion de un aval que el sistema no tiene.
 *
 * QUIEN EMITE
 * -----------
 * Solo un profesional. Lo decide la Policy, no el formulario. Ver
 * la ability `prescribir` de App\Providers\AuthServiceProvider.
 *
 * ANULAR NO ES EDITAR
 * -------------------
 * Anular tiene su propia ruta. Es un cambio de estado con consecuencia
 * clinica, y una prescripcion anulada no vuelve: ver
 * EstadoPrescripcion::puedePasarA.
 */
class PrescripcionController extends Controller
{
    public function index(Mascota $mascota): JsonResponse
    {
        $this->authorize('ver-ficha-clinica', $mascota);

        return response()->json([
            'prescripciones' => Prescripcion::query()
                ->where('mascota_id', $mascota->id)
                ->orderBy('fecha')
                ->get()
                ->map(static fn (Prescripcion $p): array => [
                    'id' => $p->id,
                    'fecha' => $p->fecha?->toDateTimeString(),
                    'medicamento' => $p->medicamento,
                    'dosis' => $p->dosis,
                    'via' => $p->via,
                    'frecuencia' => $p->frecuencia,
                    'duracion_dias' => $p->duracion_dias,
                    'indicaciones' => $p->indicaciones,
                    'estado' => $p->estado->value,
                    'para_imprimir' => $p->paraImprimir(),
                ])->all(),
        ]);
    }

    public function store(Request $peticion, Mascota $mascota): JsonResponse
    {
        $this->authorize('escribir-ficha-clinica', $mascota);

        $datos = $peticion->validate([
            'consulta_id' => ['nullable', 'uuid'],
            'medicamento' => ['required', 'string', 'max:160'],
            'dosis' => ['required', 'string', 'max:160'],
            'via' => ['nullable', 'string', 'max:60'],
            'frecuencia' => ['nullable', 'string', 'max:160'],
            'duracion_dias' => ['nullable', 'integer', 'min:1', 'max:365'],
            'indicaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            'medicamento.required' => 'Indique el medicamento.',
            'dosis.required' => 'Indique la dosis: es el dato mas importante de la receta.',
        ]);

        if ($peticion->user()->rol->puedePrescribir() === false) {
            return $this->error('Solo un profesional puede emitir prescripciones.', 404);
        }

        $prescripcion = Prescripcion::query()->create($datos + [
            'tenant_id' => $mascota->tenant_id,
            'mascota_id' => $mascota->id,
            'profesional_id' => $peticion->user()->profesional_id ?? $peticion->user()->id,
            'fecha' => now(),
            'estado' => EstadoPrescripcion::Emitida,
        ]);

        return response()->json([
            'mensaje' => 'Prescripcion emitida.',
            'prescripcion' => [
                'id' => $prescripcion->id,
                'para_imprimir' => $prescripcion->paraImprimir(),
            ],
        ], 201);
    }

    public function anular(Mascota $mascota, Prescripcion $prescripcion): JsonResponse
    {
        $this->authorize('prescribir', $mascota);

        abort_unless(
            (string) $prescripcion->mascota_id === (string) $mascota->id,
            404,
            'No encontrado.',
        );

        if (! $prescripcion->estado->puedePasarA(EstadoPrescripcion::Anulada)) {
            return $this->error('La prescripcion ya esta anulada.');
        }

        $prescripcion->estado = EstadoPrescripcion::Anulada;
        $prescripcion->save();

        return response()->json([
            'mensaje' => 'Prescripcion anulada.',
            'id' => $prescripcion->id,
        ]);
    }
}
