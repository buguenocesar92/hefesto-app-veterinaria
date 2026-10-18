<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Profesional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profesionales de la clinica.
 *
 * POR QUE EL LISTADO ABRE PARA RECEPION
 * ------------------------------------
 * Sin el catalogo de profesionales no se puede agendar. Si este listado
 * exigiera rol de administracion, la recepcion no podria hacer su trabajo.
 *
 * La ruta NO lleva el middleware `admin`: lleva el listado abierto y el resto
 * con autorizacion. Ver routes/web.php.
 *
 * `registro_profesional` ES TEXTO, NO UN DATO VERIFICADO
 * -----------------------------------------------------
 * El sistema **no consulta ningun registro oficial**. Guarda lo que la
 * clinica escribe. Validarlo contra una fuente del estado es una integracion
 * que no esta implementada. Ver docs/10.
 */
class ProfesionalController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'profesionales' => Profesional::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Profesional $p): array => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'especialidad' => $p->especialidad,
                    'registro_profesional' => $p->registro_profesional,
                ])->all(),
        ]);
    }

    public function show(Profesional $profesional): JsonResponse
    {
        return response()->json(['profesional' => [
            'id' => $profesional->id,
            'nombre' => $profesional->nombre,
            'rut' => $profesional->rut,
            'especialidad' => $profesional->especialidad,
            'registro_profesional' => $profesional->registro_profesional,
            'activo' => $profesional->activo,
            'aviso' => 'El registro profesional no fue verificado contra ninguna fuente oficial.',
        ]]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('crear', Profesional::class);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'rut' => ['nullable', 'string', 'max:20'],
            'registro_profesional' => ['nullable', 'string', 'max:40'],
            'especialidad' => ['nullable', 'string', 'max:80'],
        ], [
            'nombre.required' => 'Indique el nombre del profesional.',
        ]);

        $profesional = Profesional::query()->create($datos + [
            'tenant_id' => $peticion->user()->tenant_id,
            'activo' => true,
        ]);

        return response()->json([
            'mensaje' => 'Profesional creado.',
            'profesional' => ['id' => $profesional->id],
        ], 201);
    }

    public function update(Request $peticion, Profesional $profesional): JsonResponse
    {
        $this->authorize('actualizar', $profesional);

        $datos = $peticion->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'especialidad' => ['nullable', 'string', 'max:80'],
            'registro_profesional' => ['nullable', 'string', 'max:40'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $profesional->fill($datos)->save();

        return response()->json(['mensaje' => 'Profesional actualizado.']);
    }
}
