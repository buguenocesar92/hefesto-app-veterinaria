<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ficha;

use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Control;
use App\Models\Mascota;
use App\Models\Prescripcion;
use App\Models\Vacuna;
use Illuminate\Http\JsonResponse;

/**
 * El historial de UNA mascota.
 *
 * ===================================================================
 * LA RUTA LLEVA `{mascota}`. NO LLEVA `{tutor}`.
 * ===================================================================
 *
 * Este controlador es la razon de que los historiales no se mezclen. Toma una
 * mascota de la ruta y consulta SOLO por `mascota_id`. En ningun momento pide
 * "todo lo de este tutor".
 *
 * Si esta consulta se hiciera por tutor, la pantalla de Firulais traeria
 * tambien la de Michi. Que las dos Mascotas compartan persona es un dato de
 * contacto, no una razón para mezclar historias.
 *
 * LO QUE DEVUELVE
 * ---------------
 * Las consultas, los controles, las prescripciones y el estado del calendario
 * de vacunas, **todo filtrado por `mascota_id`**. Y el tutor, porque para
 * llamar y entregar la receta hay que saber a quien.
 *
 * La respuesta NO incluye las mascotas hermanas del mismo tutor. Mostrarlas
 * seria comodo para la recepcion, pero es informacion sobre animales que no se
 * esta atendiendo. Si hacen falta, se buscan en la ficha del tutor.
 *
 * @see \App\Models\Mascota
 * @see \App\Providers\AuthServiceProvider
 */
class HistorialController extends Controller
{
    public function show(Mascota $mascota): JsonResponse
    {
        // La autorizacion vive en la Policy. Que se rechace aqui o en la ruta
        // da igual: la respuesta es 404 en los dos casos (ver
        // bootstrap/app.php).
        // La ficha CLINICA se autoriza con la ability, no con `view` de
        // MascotaPolicy: `view` es la identificacion, que es mas abierta.
        $this->authorize('ver-ficha-clinica', $mascota);

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
                'microchip' => $mascota->microchip,
                'identificacion' => $mascota->identificacion(),
            ],

            // El tutor viene porque hay que saber a quien se le entrega la
            // receta. Es el dato minimo para eso.
            'tutor' => [
                'id' => $mascota->tutor?->id,
                'nombre' => $mascota->tutor?->nombreCompleto(),
                'telefono' => $mascota->tutor?->telefono,
            ],

            /*
             * Y desde aqui hacia abajo, TODO lleva `where mascota_id`.
             * Esa es la regla, y hay una prueba que la vigila:
             * HistorialNoSeMezclaEntreMascotasDelMismoTutorTest.
             */
            'consultas' => Consulta::query()
                ->where('mascota_id', $mascota->id)
                ->with('profesional:id,nombre')
                ->orderBy('fecha')
                ->get()
                ->map(static fn (Consulta $consulta): array => [
                    'id' => $consulta->id,
                    'fecha' => $consulta->fecha?->toDateTimeString(),
                    'motivo_consulta' => $consulta->motivo_consulta,
                    'examen_fisico' => $consulta->examen_fisico,
                    'diagnostico' => $consulta->diagnostico,
                    'tratamiento' => $consulta->tratamiento,
                    'evolucion' => $consulta->evolucion,
                    'profesional' => $consulta->profesional?->nombre,
                ]),

            'controles' => Control::query()
                ->where('mascota_id', $mascota->id)
                ->orderBy('fecha')
                ->get()
                ->map(static fn (Control $control): array => [
                    'id' => $control->id,
                    'fecha' => $control->fecha?->toDateTimeString(),
                    'descripcion' => $control->descripcion,
                    'proximo_control_en' => $control->proximo_control_en?->toDateString(),
                    'cumplido' => $control->cumplido,
                    'atrasado' => $control->atrasado(),
                ]),

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
                ]),

            'vacunas' => Vacuna::query()
                ->where('mascota_id', $mascota->id)
                ->with('esquema:id,nombre_vacuna,verificado')
                ->orderBy('fecha_aplicacion')
                ->get()
                ->map(static fn (Vacuna $v): array => [
                    'id' => $v->id,
                    'nombre_vacuna' => $v->esquema?->nombre_vacuna,
                    'fecha_aplicacion' => $v->fecha_aplicacion?->toDateString(),
                    'proxima_dose_en' => $v->proxima_dose_en?->toDateString(),
                    'lote' => $v->lote,
                    'laboratorio' => $v->laboratorio,
                    'estado' => $v->estado->value,
                    'vigencia' => $v->vigencia()->value,
                    'dias_de_diferencia' => $v->diasDeDiferencia(),
                ]),
        ]);
    }
}
