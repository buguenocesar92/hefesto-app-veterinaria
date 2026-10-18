<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vacunas;

use App\Http\Controllers\Controller;
use App\Enums\Especie;
use App\Enums\EstadoVacuna;
use App\Models\EsquemaVacunacion;
use App\Models\Mascota;
use App\Models\Vacuna;
use App\Services\Vacunas\CalculadorDeProximaDosis;
use App\Services\Vacunas\CalculadorDeVigencia;
use Illuminate\Http\JsonResponse;

/**
 * El calendario de vacunas de UNA mascota: que se aplico y que falta.
 *
 * POR QUE ESTE CONTROLADOR NO ES FICHA CLINICA
 * -------------------------------------------
 * Distingue DOS COSAS que aqui se mezclan en la misma pantalla:
 *
 *  - lo que ya PASÓ: que vacunas se aplicaron, que lote. Eso es ficha
 *    clinica, y lo abre solo un profesional.
 *  - lo que FALTA: "la polivalente vence en marzo". Eso es una obligacion
 *    pendiente de cumplir, y lo puede ver quien atiende el mostrador o el
 *    telefono, porque tiene que llamar.
 *
 * "Esta atrasada" NO es informacion de salud del animal: es un deber de la
 * clinica. Por eso este permiso es mas abierto que el de la ficha.
 *
 * Lo que no se hace: abrir el diagnostico por aqui. Ver VacunaPolicy.
 *
 * EL AVISO DE ESQUEMA SIN VERIFICAR
 * ---------------------------------
 * Cada fila trae `verificado`. Si es `false`, la respuesta lo dice. **No es
 * un bloqueo**: quien decide si la dosis corresponde es el profesional, y
 * puede aplicar una regla sin confirmar. Lo que hace el sistema es dejar
 * escrito que la regla no fue revisada. Ver docs/07.
 */
class CalendarioVacunasController extends Controller
{
    public function show(Mascota $mascota): JsonResponse
    {
        $this->authorize('ver-calendario-vacunas', $mascota);

        $edadMeses = $mascota->edadEnMeses();

        // Lo que ya se aplico, de ESTA mascota.
        $aplicadas = Vacuna::query()
            ->where('mascota_id', $mascota->id)
            ->where('estado', EstadoVacuna::Aplicada->value)
            ->with('esquema:id,nombre_vacuna,dosis_numero,verificado,fuente')
            ->orderBy('fecha_aplicacion')
            ->get();

        /*
         * QUE FALTA.
         *
         * Son los esquemas activos de la ESPECIE de esta mascota, para los que
         * la mascota ya tiene la edad minima, y que NO aparecen entre las
                 * las APLICACIONES.
         *
         * Se filtra por ESPECIE y no por "todo lo de la clinica": el esquema de
         * un gato no le sirve a un perro, y ofrecerlo seria acompanar al
         * profesional a decidir algo que el sistema ya sabe que no aplica.
         */
        $aplicadosIds = $aplicadas->pluck('esquema_id')->filter()->all();

        $pendientes = EsquemaVacunacion::query()
            ->where('especie', $mascota->especie->value)
            ->where('activo', true)
            ->when($aplicadosIds !== [], fn ($q) => $q->whereNotIn('id', $aplicadosIds))
            ->when($edadMeses !== null, fn ($q) => $q->where('edad_minima_meses', '<=', $edadMeses))
            ->orderBy('nombre_vacuna')
            ->orderBy('dosis_numero')
            ->get();

        return response()->json([
            'mascota' => [
                'id' => $mascota->id,
                'nombre' => $mascota->nombre,
                'especie' => $mascota->especie->value,
                'especie_etiqueta' => $mascota->especie->etiqueta(),
                'edad_meses' => $edadMeses,
                'identificacion' => $mascota->identificacion(),
            ],

            'aplicadas' => $aplicadas->map(static fn (Vacuna $v): array => [
                'id' => $v->id,
                'nombre_vacuna' => $v->esquema?->nombre_vacuna,
                'dosis_numero' => $v->esquema?->dosis_numero,
                'fecha_aplicacion' => $v->fecha_aplicacion?->toDateString(),
                'proxima_dose_en' => $v->proxima_dose_en?->toDateString(),
                'lote' => $v->lote,
                'laboratorio' => $v->laboratorio,
                'esquema_verificado' => $v->esquema?->verificado ?? false,
                'vigencia' => $v->vigencia()->value,
                'dias_de_diferencia' => $v->diasDeDiferencia(),
                'requiere_aviso' => $v->vigencia()->requiereAviso(),
            ])->all(),

            'pendientes' => $pendientes->map(static fn (EsquemaVacunacion $e): array => [
                'id' => $e->id,
                'nombre_vacuna' => $e->nombre_vacuna,
                'dosis_numero' => $e->dosis_numero,
                'edad_minima_meses' => $e->edad_minima_meses,
                'intervalo_meses' => $e->intervalo_meses,
                'refuerzo_meses' => $e->refuerzo_meses,
                // El aviso. Ver la nota de la cabecera.
                'verificado' => $e->verificado,
                'fuente' => $e->fuente,
                'etiqueta' => $e->etiqueta(),
            ])->all(),

            /*
             * UN AVISO GLOBAL, cuando hay reglas sin verificar en juego.
             *
             * No bloquea nada. Solo dice, una vez, lo que hay que tener
             * presente: estas reglas las puso alguien y nadie las confirmo.
             */
            'avisos' => $this->avisos($aplicadas, $pendientes),
        ]);
    }

    /**
     * Los avisos, sin bloquear nada.
     */
    private function avisos($aplicadas, $pendientes): array
    {
        $avisos = [];

        $sinVerificar = $pendientes->where('verificado', false)->count();

        if ($sinVerificar > 0) {
            $avisos[] = sprintf(
                'Hay %d esquema(s) de vacunacion sin verificar en esta clinica. '
                . 'Los intervalos los configuro alguien y no fueron confirmados contra ninguna fuente oficial. '
                . 'Confirmelos antes de fiarse de las fechas. Ver docs/07.',
                $sinVerificar,
            );
        }

        $atrasadas = $aplicadas->filter(
            static fn (Vacuna $v): bool => CalculadorDeVigencia::deVacuna($v)->requiereAviso(),
        );

        if ($atrasadas->isNotEmpty()) {
            $avisos[] = sprintf(
                'Esta mascota tiene %d dosis vencida(s) o por vencer. Revise con el tutor.',
                $atrasadas->count(),
            );
        }

        return $avisos;
    }
}
