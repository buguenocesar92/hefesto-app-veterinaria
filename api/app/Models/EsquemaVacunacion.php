<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Especie;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Esquema de vacunacion: una regla de la clinica, por especie y por dosis.
 *
 * ===================================================================
 * LO MAS IMPORTANTE DE ESTA TABLA: LOS INTERVALOS NO SON VERDAD
 * ===================================================================
 *
 * Esta tabla guarda **reglas que configura la clinica**, no calendarios
 * oficiales. Se escriben como filas, se editan, y el administrador de la
 * clinica es quien las mantiene.
 *
 * Por que NO vienen escritos en el codigo:
 *
 *  1. Dependen de la ESPECIE. Un perro, un gato, un caballo y una tarántula
 *     no comparten calendario. La clave foranea `especie` no es decorativa.
 *  2. Dependen de la EDAD. Una dosis se aplica a partir de cierta edad y con
 *     cierta separacion de la anterior; por eso `edad_minima_meses` e
 *     `intervalo_meses` son parte de la regla.
 *  3. Dependen del FABRICANTE. El esquema del laboratorio A no es
 *     necesariamente el del laboratorio B, y en varios productos la
 *     diferencia no es un detalle de paperwork.
 *  4. Y dependen de la NORMATIVA SANITARIA que aplique en la jurisdiction de
 *     la clinica, que cambia con el tiempo.
 *
 * **Este proyecto NO verifica ninguna de esas cuatro cosas.** No se consulto
 * ninguna fuente oficial al escribirlo y no hay ninguna afirmacion de que un
 * intervalo de esta tabla sea el correcto. Los datos de ejemplo que trae la
 * semilla (ver `database/seeders/`) vienen marcados con `verificado = false`
 * y con la fuente escrita, precisamente para que nadie los tome por ciertos.
 *
 * QUE HACE EL SISTEMA, Y QUE NO
 * ---------------------------
 * HACE: propose la proxima dosis leyendo una regla que la clinica configuro,
 * guarda la fecha calculada al aplicar, y avisa cuando esa fecha ya paso.
 *
 * NO HACE: validar que el esquema sea el correcto, decidir que dosis
 * corresponde, ni autorizar la aplicacion. **Esa decision es de un
 * profesional veterinario con registro valido.** Ver docs/07.
 *
 * LA COLUMNA `verificado`
 * -----------------------
 * Marca si la clinica confirmo la regla con un profesional y de donde la
 * saco. Si es `false`, la interfaz muestra la fila como "sin confirmar" y el
 * aviso de atraso dice que la regla no esta confirmada. No bloquea nada: es
 * una advertencia, no una imposicion.
 *
 * @see App\Services\Vacunas\CalculadorDeProximaDosis
 */
class EsquemaVacunacion extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'esquemas_vacunacion';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'especie',
        'nombre_vacuna',
        'dosis_numero',
        'edad_minima_meses',
        'intervalo_meses',
        'refuerzo_meses',
        'fuente',
        'verificado',
        'notas',
        'activo',
    ];

    /** @var list<string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'especie' => Especie::class,
            'dosis_numero' => 'integer',
            'edad_minima_meses' => 'integer',
            'intervalo_meses' => 'integer',
            'refuerzo_meses' => 'integer',
            'verificado' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Vacuna, $this> */
    public function vacunas(): HasMany
    {
        return $this->hasMany(Vacuna::class);
    }

    /**
     * Si la regla sirve para una mascota concreta, por especie y por edad.
     *
     * La edad se compara contra `edad_minima_meses`, que es la edad a partir
     * de la cual la dosis se puede aplicar. No decide si se DEBE aplicar: eso
     * es del profesional.
     */
    public function aplicaA(Mascota $mascota, ?int $edadEnMeses = null): bool
    {
        if ($this->especie !== $mascota->especie) {
            return false;
        }

        $edad = $edadEnMeses ?? $mascota->edadEnMeses();

        if ($edad === null) {
            return false;
        }

        return $edad >= $this->edad_minima_meses;
    }

    /**
     * Los meses que separan esta dosis de la anterior.
     *
     * Si la regla define refuerzo, manda el refuerzo: es el caso de la dosis
     * anual, que no sigue el intervalo de la serie inicial.
     */
    public function mesesHastaProxima(): int
    {
        return $this->refuerzo_meses !== null && $this->refuerzo_meses > 0
            ? $this->refuerzo_meses
            : (int) $this->intervalo_meses;
    }

    /**
     * Como se ve esta regla en la interfaz, incluyendo el aviso de que no
     * esta confirmada.
     */
    public function etiqueta(): string
    {
        $texto = sprintf(
            '%s, dosis %d (desde %d meses, cada %d meses)',
            $this->nombre_vacuna,
            $this->dosis_numero,
            $this->edad_minima_meses,
            $this->mesesHastaProxima(),
        );

        if (! $this->verificado) {
            $texto .= ' [SIN CONFIRMAR]';
        }

        return $texto;
    }
}
