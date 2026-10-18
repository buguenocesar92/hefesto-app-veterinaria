<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ficha;

use App\Http\Controllers\Controller;
use App\Models\Control;
use App\Models\Mascota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controles: el seguimiento pendiente de una mascota.
 *
 * `proximo_control_en` se CALCULA al registrar y SE GUARDA. No se recalcula en
 * cada lectura.
 *
 * Por que: si la fecha dependiera de "hoy", cambiaria sola con el paso del
 * tiempo, y el dia que mas importa (el tablero de atrasados) el sistema
 * podria decir una cosa y ayer otra. Es el mismo criterio que en vacunas. Ver
 * docs/02.
 *
 * Todas las rutas llevan `{mascota}` y filtran por `mascota_id`.
 */
class ControlController extends Controller
{
    public function index(Mascota $mascota): JsonResponse
    {
        $this->authorize('ver-ficha-clinica', $mascota);

        return response()->json([
            'controles' => Control::query()
                ->where('mascota_id', $mascota->id)
                ->orderBy('fecha')
                ->get()
                ->map(static fn (Control $c): array => [
                    'id' => $c->id,
                    'fecha' => $c->fecha?->toDateTimeString(),
                    'descripcion' => $c->descripcion,
                    'proximo_control_en' => $c->proximo_control_en?->toDateString(),
                    'cumplido' => $c->cumplido,
                    'atrasado' => $c->atrasado(),
                    'observaciones' => $c->observaciones,
                ])->all(),
        ]);
    }

    public function store(Request $peticion, Mascota $mascota): JsonResponse
    {
        $this->authorize('escribir-ficha-clinica', $mascota);

        $datos = $peticion->validate([
            'consulta_id' => ['nullable', 'uuid'],
            'fecha' => ['required', 'date'],
            'descripcion' => ['required', 'string', 'max:240'],
            'proximo_control_en' => ['nullable', 'date', 'after_or_equal:fecha'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            'descripcion.required' => 'Describa el control que quedo pendiente.',
            'proximo_control_en.after_or_equal' => 'El proximo control no puede ser anterior a la fecha de este control.',
        ]);

        $control = Control::query()->create($datos + [
            'tenant_id' => $mascota->tenant_id,
            'mascota_id' => $mascota->id,
            'profesional_id' => $peticion->user()->profesional_id ?? $peticion->user()->id,
            'cumplido' => false,
        ]);

        return response()->json([
            'mensaje' => 'Control registrado.',
            'control' => ['id' => $control->id],
        ], 201);
    }

    public function update(Request $peticion, Mascota $mascota, Control $control): JsonResponse
    {
        $this->authorize('escribir-ficha-clinica', $mascota);

        abort_unless((string) $control->mascota_id === (string) $mascota->id, 404, 'No encontrado.');

        $datos = $peticion->validate([
            'cumplido' => ['sometimes', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'proximo_control_en' => ['nullable', 'date'],
        ]);

        $control->fill($datos)->save();

        return response()->json([
            'mensaje' => 'Control actualizado.',
            'control' => ['id' => $control->id, 'cumplido' => $control->cumplido],
        ]);
    }
}
