<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\TipoAtencion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tipos de atencion: el catalogo de la agenda.
 *
 * `bloque_vacunacion` ES LA BANDERA QUE DEFINE ESTA APLICACION
 * -----------------------------------------------------------
 * En una clinica veterinaria, la atencion mas frecuente del dia no es una
 * consulta: es una vacuna. Es rapida, se hace en cadena y es la que da la
 * plata.
 *
 * Mezclarlas produce el problema de siempre: la gente va por una vacuna y
 * espera veinte minutos a que se desocupe el consultorio de al lado. Con el
 * bloque separado, la agenda de vacunas es rapida y masiva, y se puede abrir
 * un turno de vacunas un domingo sin tocar la agenda de consultas.
 *
 * `duracion_minutos` LA USA LA AGENDA PARA CALCULAR EL FIN DE LA CITA. No la
 * escribe quien agenda, sino el catalogo: asi dos personas que agenden la
 * misma cosa ocupan siempre el mismo rato. Ver Agenda\CitaController.
 */
class TipoAtencionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'tipos_atencion' => TipoAtencion::query()
                ->where('activo', true)
                ->orderBy('bloque_vacunacion')
                ->orderBy('nombre')
                ->get()
                ->map(static fn (TipoAtencion $t): array => [
                    'id' => $t->id,
                    'nombre' => $t->nombre,
                    'descripcion' => $t->descripcion,
                    'duracion_minutos' => $t->duracion_minutos,
                    'bloque_vacunacion' => $t->bloque_vacunacion,
                    'precio' => $t->precio !== null ? (int) $t->precio : null,
                ])->all(),
        ]);
    }

    public function show(TipoAtencion $tipoAtencion): JsonResponse
    {
        return response()->json(['tipo_atencion' => [
            'id' => $tipoAtencion->id,
            'nombre' => $tipoAtencion->nombre,
            'descripcion' => $tipoAtencion->descripcion,
            'duracion_minutos' => $tipoAtencion->duracion_minutos,
            'bloque_vacunacion' => $tipoAtencion->bloque_vacunacion,
            'precio' => $tipoAtencion->precio !== null ? (int) $tipoAtencion->precio : null,
            'activo' => $tipoAtencion->activo,
        ]]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('crear', TipoAtencion::class);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:80', 'unique:tipos_atencion,nombre'],
            'descripcion' => ['nullable', 'string', 'max:240'],
            'duracion_minutos' => ['required', 'integer', 'min:5', 'max:480'],
            'bloque_vacunacion' => ['nullable', 'boolean'],
            'precio' => ['nullable', 'integer', 'min:0'],
        ], [
            'nombre.required' => 'Indique el nombre del tipo de atencion.',
            'duracion_minutos.required' => 'Indique cuanto dura: la agenda lo usa para calcular el fin de la cita.',
            'duracion_minutos.min' => 'Una atencion no puede durar menos de 5 minutos.',
            'duracion_minutos.max' => 'Una atencion no puede durar mas de 8 horas.',
        ]);

        $tipo = TipoAtencion::query()->create($datos + [
            'tenant_id' => $peticion->user()->tenant_id,
            'activo' => true,
        ]);

        return response()->json([
            'mensaje' => 'Tipo de atencion creado.',
            'tipo_atencion' => ['id' => $tipo->id],
        ], 201);
    }

    public function update(Request $peticion, TipoAtencion $tipoAtencion): JsonResponse
    {
        $this->authorize('actualizar', $tipoAtencion);

        $datos = $peticion->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:240'],
            'duracion_minutos' => ['sometimes', 'required', 'integer', 'min:5', 'max:480'],
            'bloque_vacunacion' => ['nullable', 'boolean'],
            'precio' => ['nullable', 'integer', 'min:0'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $tipoAtencion->fill($datos)->save();

        return response()->json([
            'mensaje' => 'Tipo de atencion actualizado.',
            'nota' => 'Las citas ya agendadas conservan la duracion con la que fueron creadas.',
        ]);
    }
}
