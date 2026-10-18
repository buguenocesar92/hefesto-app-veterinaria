<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Especie;
use App\Enums\Sexo;
use App\Models\Mascota;
use App\Models\Tutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mascotas: ficha de IDENTIFICACION.
 *
 * POR QUE LA RUTA CUELGA DEL TUTOR
 * -------------------------------
 * `/tutores/{tutor}/mascotas`. Porque asi se refleja el dominio: las mascotas
 * de una persona, y la pantalla de alta es la de esa persona.
 *
 * LO QUE ESTA TABLA TIENE Y LO QUE NO
 * ----------------------------------
 * Nombre, especie, raza, sexo, fecha de nacimiento, peso y microchip. **No hay
 * diagnostico ni tratamiento**: eso vive en la ficha clinica, que va por otra
 * ruta y exige otro permiso.
 *
 * LA CONSULTA DE ESTA MASCOTA NUNCA SE HACE POR TUTOR
 * ---------------------------------------------------
 * Al mostrar una mascota, esta clase carga SOLO esa. Las hermanas del mismo
 * tutor no aparecen: son fichas separadas. Ver docs/02.
 */
class MascotaController extends Controller
{
    public function index(Tutor $tutor): JsonResponse
    {
        return response()->json([
            'tutor' => ['id' => $tutor->id, 'nombre' => $tutor->nombreCompleto()],
            'mascotas' => $tutor->mascotas()
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Mascota $m): array => [
                    'id' => $m->id,
                    'nombre' => $m->nombre,
                    'especie' => $m->especie->value,
                    'raza' => $m->raza,
                    'edad_meses' => $m->edadEnMeses(),
                    'identificacion' => $m->identificacion(),
                ])->all(),
        ]);
    }

    public function show(Tutor $tutor, Mascota $mascota): JsonResponse
    {
        $this->comprobarQueEsDelTutor($tutor, $mascota);

        // Identificacion, no salud: por eso usa `view` y no la ability clinica.
        $this->authorize('view', $mascota);

        return response()->json([
            'mascota' => [
                'id' => $mascota->id,
                'nombre' => $mascota->nombre,
                'especie' => $mascota->especie->value,
                'especie_etiqueta' => $mascota->especie->etiqueta(),
                'raza' => $mascota->raza,
                'sexo' => $mascota->sexo?->value,
                'fecha_nacimiento' => $mascota->fecha_nacimiento?->toDateString(),
                'edad_meses' => $mascota->edadEnMeses(),
                'peso_kg' => $mascota->peso_kg,
                'altura_cm' => $mascota->altura_cm,
                'color' => $mascota->color,
                'microchip' => $mascota->microchip,
                'esterilizado' => $mascota->esterilizado,
                'activo' => $mascota->activo,
                'identificacion' => $mascota->identificacion(),
            ],
            'tutor' => [
                'id' => $tutor->id,
                'nombre' => $tutor->nombreCompleto(),
                'telefono' => $tutor->telefono,
            ],

            /*
             * Un aviso, no una ficha.
             *
             * "Esta mascota tiene algo pendiente" es una obligacion de la
             * clinica, no informacion de salud del animal: la muestra quien
             * agenda, sin abrirle el historial.
             */
            'tiene_vacunas_atrasadas' => $mascota->vacunas()
                ->where('estado', 'aplicada')
                ->whereNotNull('proxima_dose_en')
                ->where('proxima_dose_en', '<', now()->toDateString())
                ->exists(),
        ]);
    }

    public function store(Request $peticion, Tutor $tutor): JsonResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'especie' => ['required', 'string', 'in:' . implode(',', array_column(Especie::cases(), 'value'))],
            'raza' => ['nullable', 'string', 'max:80'],
            // El sexo tiene "no_informado": si el formulario obliga a elegir,
            // alguien inventa el dato y la invencion queda en la ficha para
            // siempre. Ver App\Enums\Sexo.
            'sexo' => ['nullable', 'string', 'in:' . implode(',', array_column(Sexo::cases(), 'value'))],
            'fecha_nacimiento' => ['nullable', 'date', 'before_or_equal:today'],
            'peso_kg' => ['nullable', 'numeric', 'min:0.01', 'max:500'],
            'altura_cm' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'color' => ['nullable', 'string', 'max:60'],
            'microchip' => ['nullable', 'string', 'max:40'],
            'esterilizado' => ['nullable', 'boolean'],
        ], [
            'nombre.required' => 'La mascota necesita un nombre.',
            'especie.required' => 'Indique la especie: el esquema de vacunacion depende de ella.',
            'fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
        ]);

        $microchip = $datos['microchip'] ?? null;

        if ($microchip !== null && $microchip !== '') {
            $repetido = Mascota::query()
                ->where('microchip', $microchip)
                ->exists();

            if ($repetido) {
                // Se comprueba en la capa de aplicacion, no con un indice
                // unico, porque los NULL se consideran distintos entre si en
                // PostgreSQL. Ver la migracion de `mascotas`.
                return $this->error('Ya existe una mascota con ese microchip en esta clinica.');
            }
        }

        $mascota = Mascota::query()->create($datos + [
            'tenant_id' => $tutor->tenant_id,
            'tutor_id' => $tutor->id,
            'activo' => true,
        ]);

        return response()->json([
            'mensaje' => 'Mascota registrada.',
            'mascota' => ['id' => $mascota->id, 'identificacion' => $mascota->identificacion()],
        ], 201);
    }

    public function update(Request $peticion, Tutor $tutor, Mascota $mascota): JsonResponse
    {
        $this->comprobarQueEsDelTutor($tutor, $mascota);

        $this->authorize('actualizar', $mascota);

        $datos = $peticion->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:80'],
            'raza' => ['nullable', 'string', 'max:80'],
            'sexo' => ['nullable', 'string', 'in:' . implode(',', array_column(Sexo::cases(), 'value'))],
            'peso_kg' => ['nullable', 'numeric', 'min:0.01', 'max:500'],
            'altura_cm' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'color' => ['nullable', 'string', 'max:60'],
            'microchip' => ['nullable', 'string', 'max:40'],
            'esterilizado' => ['nullable', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $mascota->fill($datos)->save();

        return response()->json([
            'mensaje' => 'Mascota actualizada.',
            'mascota' => ['id' => $mascota->id, 'identificacion' => $mascota->identificacion()],
        ]);
    }

    /**
     * Que la mascota de la ruta sea de este tutor.
     *
     * Sin esto, `PATCH /tutores/A/mascotas/B` podria modificar una mascota del
     * tutor B citando el tutor A en la ruta. La RLS impide el cruce entre
     * clinicas, pero no entre tutores de la misma clinica.
     */
    private function comprobarQueEsDelTutor(Tutor $tutor, Mascota $mascota): void
    {
        abort_unless(
            (string) $mascota->tutor_id === (string) $tutor->id,
            404,
            'No encontrado.',
        );
    }
}
