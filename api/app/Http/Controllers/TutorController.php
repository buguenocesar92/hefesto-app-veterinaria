<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Mascota;
use App\Models\Tutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tutores: la persona que decide.
 *
 * QUE VE Y QUE NO VE ESTE CONTROLADOR
 * ----------------------------------
 * Identificacion y contacto. Es lo que recepcion necesita para agendar y para
 * llamar.
 *
 * NO hay ningun dato clinico aqui. El historial de una mascota se abre por la
 * ficha de ESA mascota, en otra ruta, y con otro permiso. Este controlador no
 * lee `consultas` ni sabe que existe.
 *
 * Y algo que conviene notar: la respuesta de `index` NO incluye las fichas
 * clinicas de las mascotas del tutor, solo la cantidad. Mostrarlas seria
 * comodo, pero es informacion sobre animales que no se esta atendiendo.
 *
 * Ver App\Models\Tutor y docs/03.
 */
class TutorController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $busqueda = trim((string) $peticion->query('q', ''));

        $consulta = Tutor::query()
            ->when($busqueda !== '', function ($q) use ($busqueda): void {
                $q->where(function ($interna) use ($busqueda): void {
                    $interna->where('nombres', 'ilike', '%' . $busqueda . '%')
                        ->orWhere('apellidos', 'ilike', '%' . $busqueda . '%')
                        ->orWhere('rut', 'ilike', '%' . $busqueda . '%')
                        ->orWhere('telefono', 'ilike', '%' . $busqueda . '%');
                });
            })
            ->orderBy('apellidos')
            ->limit(100)
            ->get();

        return response()->json([
            'tutores' => $consulta->map(static fn (Tutor $t): array => [
                'id' => $t->id,
                'rut' => $t->rut,
                'nombre' => $t->nombreCompleto(),
                'telefono' => $t->telefono,
                'comuna' => $t->comuna,
                'activo' => $t->activo,
                // Solo el CONTEO. La ficha clinica de cada animal no va aqui.
                'mascotas_cantidad' => $t->mascotas()->count(),
            ])->all(),
        ]);
    }

    public function show(Tutor $tutor): JsonResponse
    {
        return response()->json([
            'tutor' => [
                'id' => $tutor->id,
                'rut' => $tutor->rut,
                'nombres' => $tutor->nombres,
                'apellidos' => $tutor->apellidos,
                'nombre' => $tutor->nombreCompleto(),
                'telefono' => $tutor->telefono,
                'correo' => $tutor->correo,
                'direccion' => $tutor->direccion,
                'comuna' => $tutor->comuna,
                'activo' => $tutor->activo,
            ],

            /*
             * Las mascotas del tutor, con IDENTIFICACION solamente.
             *
             * Ni diagnostico, ni evolucion, ni receta, ni estado de vacunas. De
             * eso se encarga la ficha de cada mascota, que exige otro permiso.
             *
             * Y por que NO se spleen "tambien las otras": porque un tutor con
             * tres animales tiene tres historias separadas, y el listado de
             * sus mascotas es el lugar donde mas facil se mezclarian.
             */
            'mascotas' => $tutor->mascotas()
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Mascota $m): array => [
                    'id' => $m->id,
                    'nombre' => $m->nombre,
                    'especie' => $m->especie->value,
                    'especie_etiqueta' => $m->especie->etiqueta(),
                    'raza' => $m->raza,
                    'sexo' => $m->sexo?->value,
                    'fecha_nacimiento' => $m->fecha_nacimiento?->toDateString(),
                    'edad_meses' => $m->edadEnMeses(),
                    'peso_kg' => $m->peso_kg,
                    'identificacion' => $m->identificacion(),
                    'activo' => $m->activo,
                ])->all(),
        ]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'rut' => ['required', 'string', 'max:20', 'unique:tutores,rut'],
            'nombres' => ['required', 'string', 'max:80'],
            'apellidos' => ['required', 'string', 'max:80'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:160'],
            'direccion' => ['nullable', 'string', 'max:180'],
            'comuna' => ['nullable', 'string', 'max:80'],
        ], [
            'rut.required' => 'El RUT del tutor es obligatorio: es la persona responsable del animal.',
            'rut.unique' => 'Ya existe un tutor con ese RUT en esta clinica.',
            'nombres.required' => 'Indique los nombres del tutor.',
            'apellidos.required' => 'Indique los apellidos del tutor.',
        ]);

        $tutor = Tutor::query()->create($datos + [
            'tenant_id' => $peticion->user()->tenant_id,
            'activo' => true,
        ]);

        return response()->json([
            'mensaje' => 'Tutor registrado.',
            'tutor' => ['id' => $tutor->id, 'nombre' => $tutor->nombreCompleto()],
        ], 201);
    }

    public function update(Request $peticion, Tutor $tutor): JsonResponse
    {
        $datos = $peticion->validate([
            'nombres' => ['sometimes', 'required', 'string', 'max:80'],
            'apellidos' => ['sometimes', 'required', 'string', 'max:80'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:160'],
            'direccion' => ['nullable', 'string', 'max:180'],
            'comuna' => ['nullable', 'string', 'max:80'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $tutor->fill($datos)->save();

        return response()->json([
            'mensaje' => 'Tutor actualizado.',
            'tutor' => ['id' => $tutor->id, 'nombre' => $tutor->nombreCompleto()],
        ]);
    }
}
