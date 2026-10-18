<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ficha;

use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Mascota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consultas de UNA mascota.
 *
 * TODAS LAS RUTAS LLEVAN `{mascota}`
 * ----------------------------------
 * Y todas las consultas filtran por `mascota_id`. Nunca por `tutor_id`.
 *
 * Un tutor puede tener tres mascotas. Si algo de esta clase buscara por tutor,
 * la ficha de Firulais traeria tambien los diagnosticos de Michi, y al recetar
 * se calcularia sobre el animal equivocado. Ver App\Models\Mascota.
 *
 * LO QUE ESTO NO VALIDA
 * ---------------------
 * Que el diagnostico sea correcto, ni que el tratamiento Sirva. El sistema
 * registra lo que el profesional escribio; la opinion clinica es suya.
 *
 * PERO SI VALIDA UNA COSA
 * -----------------------
 * Que quien escribe sea profesional. Lo resuelve la Policy, no el formulario.
 */
class ConsultaController extends Controller
{
    public function index(Mascota $mascota): JsonResponse
    {
        $this->authorize('ver-ficha-clinica', $mascota);

        return response()->json([
            'consultas' => Consulta::query()
                ->where('mascota_id', $mascota->id)
                ->with('profesional:id,nombre')
                ->orderBy('fecha')
                ->get()
                ->map(static fn (Consulta $c): array => [
                    'id' => $c->id,
                    'fecha' => $c->fecha?->toDateTimeString(),
                    'motivo_consulta' => $c->motivo_consulta,
                    'diagnostico' => $c->diagnostico,
                    'profesional' => $c->profesional?->nombre,
                ])->all(),
        ]);
    }

    public function store(Request $peticion, Mascota $mascota): JsonResponse
    {
        $this->authorize('escribir-ficha-clinica', $mascota);

        $datos = $peticion->validate([
            'cita_id' => ['nullable', 'uuid'],
            'fecha' => ['nullable', 'date'],
            'motivo_consulta' => ['nullable', 'string', 'max:240'],
            'examen_fisico' => ['nullable', 'string', 'max:4000'],
            'diagnostico' => ['nullable', 'string', 'max:4000'],
            'tratamiento' => ['nullable', 'string', 'max:4000'],
            'evolucion' => ['nullable', 'string', 'max:4000'],
        ]);

        $consulta = Consulta::query()->create($datos + [
            'tenant_id' => $mascota->tenant_id,
            'mascota_id' => $mascota->id,
            'profesional_id' => $peticion->user()->profesional_id ?? $peticion->user()->id,
            'fecha' => $datos['fecha'] ?? now(),
        ]);

        return response()->json([
            'mensaje' => 'Consulta registrada.',
            'consulta' => ['id' => $consulta->id],
        ], 201);
    }
}
