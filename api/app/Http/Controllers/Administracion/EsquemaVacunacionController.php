<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\EsquemaVacunacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Esquemas de vacunacion: la configuracion de la clinica.
 *
 * ===================================================================
 * EL AVISO QUE ESTA CLASE TIENE QUE MOSTRAR SIEMPRE
 * ===================================================================
 *
 * Los intervalos que se escriben aqui **no son una verdad del sistema**.
 *
 * Un esquema de vacunacion depende de la especie, de la edad, del fabricante
 * del producto y de la normativa sanitaria que aplique en la jurisdiction de
 * la clinica. **Este proyecto no verifico ninguna de esas cuatro cosas contra
 * ninguna fuente oficial.**
 *
 * Por eso:
 *
 *  1. No hay ningun esquema escrito en el codigo. Arrancar con un calendario
 *     que nadie en esa clinica reviso seria tomar la primera decision clinica
 *     del sistema en nombre de quien lo instala.
 *  2. Cada fila trae `verificado` y `fuente`. Si no esta verificada, la
 *     interfaz la muestra marcada como [SIN CONFIRMAR].
 *  3. La API responde siempre con el aviso, para que ningun cliente lo use sin
 *     haberlo visto.
 *
 * LO QUE ESTA CLASE NO HACE
 * -------------------------
 * No valida que un intervalo sea correcto, ni que la dosis corresponda, ni que
 * la especie sea la adecuada. **Eso es de un profesional veterinario con
 * registro vigente.** Ver docs/07.
 */
class EsquemaVacunacionController extends Controller
{
    /** El aviso que viaja con toda respuesta de este controlador. */
    private const AVISO = 'Los intervalos de esta tabla los configura la clinica. '
        . 'Este sistema NO los verifica contra ninguna fuente oficial y no decide si son correctos. '
        . 'Deben confirmarlos con un profesional veterinario y la normativa vigente antes de usarse. '
        . 'Ver docs/07.';

    public function index(Request $peticion): JsonResponse
    {
        $esquemas = EsquemaVacunacion::query()
            ->when($peticion->query('especie'), fn ($q, $v) => $q->where('especie', $v))
            ->when($peticion->query('solo_activos') === '1', fn ($q) => $q->where('activo', true))
            ->orderBy('especie')
            ->orderBy('nombre_vacuna')
            ->orderBy('dosis_numero')
            ->get()
            ->map(static fn (EsquemaVacunacion $e): array => self::fila($e))
            ->all();

        return response()->json([
            'esquemas' => $esquemas,
            'sin_verificar' => count(array_filter($esquemas, static fn (array $e): bool => $e['verificado'] === false)),
            'aviso' => self::AVISO,
        ]);
    }

    public function show(EsquemaVacunacion $esquemaVacunacion): JsonResponse
    {
        return response()->json([
            'esquema' => self::fila($esquemaVacunacion),
            'aviso' => self::AVISO,
        ]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'especie' => ['required', 'string', 'in:' . implode(',', array_column(\App\Enums\Especie::cases(), 'value'))],
            'nombre_vacuna' => ['required', 'string', 'max:120'],
            'dosis_numero' => ['required', 'integer', 'min:1', 'max:20'],
            'edad_minima_meses' => ['required', 'integer', 'min:1', 'max:240'],
            'intervalo_meses' => ['required', 'integer', 'min:0', 'max:120'],
            'refuerzo_meses' => ['nullable', 'integer', 'min:0', 'max:120'],
            'fuente' => ['nullable', 'string', 'max:240'],
            'verificado' => ['nullable', 'boolean'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'activo' => ['nullable', 'boolean'],
        ], [
            'especie.required' => 'Indique la especie: el esquema de un perro no es el de un gato.',
            'nombre_vacuna.required' => 'Indique el nombre de la vacuna.',
            'dosis_numero.required' => 'Indique que dosis de la serie es esta.',
            'edad_minima_meses.required' => 'Indique a partir de que edad se puede aplicar.',
        ]);

        // Una regla tiene que ser utilizable: sin edad no se sugiere, y sin
        // separacion no hay proxima fecha. La constraint de la base tambien
        // lo comprueba, pero avisar aca es mejor que un error de PostgreSQL.
        if ((int) $datos['intervalo_meses'] === 0 && empty($datos['refuerzo_meses'])) {
            return $this->error(
                'Indique un intervalo o un refuerzo. Sin ninguno de los dos, este esquema no propone proxima dosis.'
            );
        }

        $existente = EsquemaVacunacion::query()
            ->where('especie', $datos['especie'])
            ->where('nombre_vacuna', $datos['nombre_vacuna'])
            ->where('dosis_numero', $datos['dosis_numero'])
            ->exists();

        if ($existente) {
            return $this->error('Ya existe un esquema con esa especie, esa vacuna y ese numero de dosis.');
        }

        $esquema = EsquemaVacunacion::query()->create($datos + [
            'tenant_id' => $peticion->user()->tenant_id,
            // Por omision, NUNCA verificado. Para marcarlo hay que decir que
            // se confirmo, y eso es una decision consciente.
            'verificado' => $datos['verificado'] ?? false,
            'activo' => $datos['activo'] ?? true,
        ]);

        return response()->json([
            'mensaje' => 'Esquema creado.',
            'esquema' => self::fila($esquema),
            'aviso' => self::AVISO,
        ], 201);
    }

    public function update(Request $peticion, EsquemaVacunacion $esquemaVacunacion): JsonResponse
    {
        $datos = $peticion->validate([
            'nombre_vacuna' => ['sometimes', 'required', 'string', 'max:120'],
            'edad_minima_meses' => ['sometimes', 'required', 'integer', 'min:1', 'max:240'],
            'intervalo_meses' => ['sometimes', 'required', 'integer', 'min:0', 'max:120'],
            'refuerzo_meses' => ['nullable', 'integer', 'min:0', 'max:120'],
            'fuente' => ['nullable', 'string', 'max:240'],
            'verificado' => ['nullable', 'boolean'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $esquemaVacunacion->fill($datos)->save();

        /*
         * LO QUE ESTE CAMBIO NO HACE, Y ES LO IMPORTANTE
         * ----------------------------------------------
         * Editar un esquema NO mueve las fechas de las vacunas que ya se
         * aplicaron.
         *
         * `vacunas.proxima_dose_en` se calculo al aplicar y se guardo. Es un
         * hecho, igual que la fecha impressa en el certificado que se le
         * entrego al tutor. Si al editar una regla cambiara el pasado, un
         * certificado ya impreso quedaria desmentido por la base.
         *
         * Ver App\Models\Vacuna y docs/02.
         */
        return response()->json([
            'mensaje' => 'Esquema actualizado.',
            'esquema' => self::fila($esquemaVacunacion),
            'nota' => 'Las fechas de proxima dosis ya registradas NO se modificaron: son hechos guardados al aplicar.',
            'aviso' => self::AVISO,
        ]);
    }

    /**
     * Una fila de la respuesta, con el estado de verificacion a la vista.
     *
     * @return array<string, mixed>
     */
    private static function fila(EsquemaVacunacion $e): array
    {
        return [
            'id' => $e->id,
            'especie' => $e->especie->value,
            'nombre_vacuna' => $e->nombre_vacuna,
            'dosis_numero' => $e->dosis_numero,
            'edad_minima_meses' => $e->edad_minima_meses,
            'intervalo_meses' => $e->intervalo_meses,
            'refuerzo_meses' => $e->refuerzo_meses,
            'meses_hasta_proxima' => $e->mesesHastaProxima(),
            'fuente' => $e->fuente,
            'verificado' => $e->verificado,
            'notas' => $e->notas,
            'activo' => $e->activo,
            'etiqueta' => $e->etiqueta(),
        ];
    }
}
