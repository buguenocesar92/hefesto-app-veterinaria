<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vacunas;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarVacunaRequest;
use App\Models\EsquemaVacunacion;
use App\Models\Mascota;
use App\Models\Vacuna;
use App\Services\Vacunas\CalculadorDeProximaDosis;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Registrar y anular aplicaciones de vacuna.
 *
 * ===================================================================
 * DONDE SE CALCULA LA PROXIMA DOSIS: UNA VEZ, AL GUARDAR
 * ===================================================================
 *
 * Este es el unico lugar del sistema donde se calcula `proxima_dose_en`. Se
 * calcula con el servicio real, se guarda, y **no se vuelve a tocar**.
 *
 * Por que no se recalcula despues:
 *
 *  - Si se recalculara contra el esquema actual, editar una regla moveria hacia
 *    atras las fechas de una dosis que ya se aplico, y dejaria desmentido un
 *    certificado ya impreso.
 *  - Si se recalculara contra "hoy", el resultado cambiaria solo con que pase
 *    el tiempo, y el dia que mas importa el sistema podria decir una cosa y
 *    ayer otra.
 *
 * Ver App\Models\Vacuna y App\Services\Vacunas\CalculadorDeProximaDosis.
 *
 * LO QUE ESTE CONTROLADOR NO HACE
 * ------------------------------
 * **No valida el esquema.** No comprueba que la dosis corresponda, ni que el
 * intervalo sea el correcto, ni que la especie sea la correcta. Eso es del
 * profesional que decide aplicar. Lo que hace es GUARDAR lo que el profesional
 * decidio. Ver docs/07.
 *
 * LO QUE SI HACE, Y ES UTIL
 * -------------------------
 * Avisa, sin bloquear: si el esquema que se eligio no esta `verificado`, la
 * respuesta lo dice. El profesional puede aplicar igual (es quien sabe), pero
 * queda escrito que la regla no fue confirmada.
 */
class VacunaController extends Controller
{
    /**
     * Registra que se aplico una vacuna.
     */
    public function store(RegistrarVacunaRequest $peticion, Mascota $mascota): JsonResponse
    {
        $this->authorize('aplicar-vacuna', $mascota);

        $datos = $peticion->validated();
        $esquema = null;

        if ($datos['esquema_id'] !== null) {
            $esquema = EsquemaVacunacion::query()
                ->whereKey($datos['esquema_id'])
                ->first();

            if ($esquema === null) {
                return $this->error('El esquema de vacunacion no existe en esta clinica.');
            }

            /*
             * AVISO, NO BLOQUEO.
             *
             * La especie tiene que calzar. Si no calza, es casi seguro un
             * error al elegir el esquema, asi que se corta.
             *
             * La EDAD no se corta: hay refinamiento de protocolo, camadas
             * hibridas y motivos legitimos para aplicar una dosis antes de la
             * edad que dice la regla. El profesional decide; la interfaz le
             * muestra la diferencia.
             */
            if ($esquema->especie !== $mascota->especie) {
                return $this->error(
                    'El esquema pertenece a otra especie. Revise la seleccion.'
                );
            }
        }

        // El servicio recibe `DateTimeImmutable` a proposito: obliga a que la
        // fecha entre YA CONVERTIDA y ya resuelta, y no un string que cada
        // quien interpretaria a su manera.
        $fechaComoObjeto = \DateTimeImmutable::createFromFormat(
            'Y-m-d',
            $datos['fecha_aplicacion']->toDateString(),
        );

        $proximaDosis = $esquema !== null
            ? CalculadorDeProximaDosis::calcular($esquema, $fechaComoObjeto)
            : null;

        $vacuna = DB::transaction(function () use ($datos, $peticion, $mascota, $esquema, $proximaDosis): Vacuna {
            return Vacuna::query()->create([
                'tenant_id' => $mascota->tenant_id,
                'mascota_id' => $mascota->id,
                'esquema_id' => $esquema?->id,
                'profesional_id' => $peticion->user()->profesional_id ?? $peticion->user()->id,
                'cita_id' => $datos['cita_id'] ?? null,
                'fecha_aplicacion' => $datos['fecha_aplicacion'],
                // EL DATO GUARDADO. No se recalcula nunca despues.
                'proxima_dose_en' => $proximaDosis?->format('Y-m-d'),
                'lote' => $datos['lote'] ?? null,
                'laboratorio' => $datos['laboratorio'] ?? null,
                'estado' => 'aplicada',
                'observaciones' => $datos['observaciones'] ?? null,
            ]);
        });

        return response()->json([
            'mensaje' => 'Vacuna registrada.',
            'vacuna' => [
                'id' => $vacuna->id,
                'fecha_aplicacion' => $vacuna->fecha_aplicacion?->toDateString(),
                'proxima_dose_en' => $vacuna->proxima_dose_en?->toDateString(),
                'vigencia' => $vacuna->vigencia()->value,
                'lote' => $vacuna->lote,
            ],

            // Avisos, no bloqueos.
            'avisos' => array_values(array_filter([
                $esquema !== null && ! $esquema->verificado
                    ? 'El esquema aplicado NO esta verificado por la clinica. '
                        . 'Confirme el intervalo con la normativa vigente antes de fiarse de esta fecha.'
                    : null,
                $esquema === null
                    ? 'Se registro una vacuna sin esquema. La proxima dosis queda sin calcular.'
                    : null,
            ])),
        ], 201);
    }

    /**
     * Anula una aplicacion.
     *
     * No se borra: una dosis aplicada y anulada es un hecho que hay que poder
     * probar. El estado queda en `anulada` y deja de contar.
     */
    public function anular(Mascota $mascota, Vacuna $vacuna): JsonResponse
    {
        $this->authorize('aplicar-vacuna', $mascota);

        // La RLS ya no deja leer una vacuna de otra mascota, pero se comprueba
        // igual: si el identificador no corresponde a la mascota de la ruta,
        // es una peticion inconsistente y se responde 404.
        abort_unless(
            (string) $vacuna->mascota_id === (string) $mascota->id,
            404,
            'No encontrado.',
        );

        if (! $vacuna->estado->cuenta()) {
            return $this->error('La vacuna ya estaba anulada.');
        }

        $vacuna->estado = 'anulada';
        $vacuna->save();

        return response()->json([
            'mensaje' => 'Vacuna anulada.',
            'id' => $vacuna->id,
        ]);
    }
}
