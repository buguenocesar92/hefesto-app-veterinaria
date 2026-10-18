<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoVacuna;
use App\Enums\VigenciaVacuna;
use App\Services\Vacunas\CalculadorDeVigencia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aplicacion de una vacuna: un HECHO.
 *
 * LA REGLA DE ORO DE ESTE MODULO
 * ------------------------------
 * `proxima_dose_en` es una **fecha guardada**, no una fecha que se calcule en
 * cada lectura. Se calcula UNA vez, el dia que se registra la aplicacion,
 * leyendo el esquema que habia en ese momento, y se guarda.
 *
 * Despues no se toca. Ni cuando se edita el esquema, ni cuando cambia la
 * normativa, ni cuando pasa el tiempo.
 *
 * POR QUE NO SE RECALCULA EN CADA LECTURA
 * ---------------------------------------
 * Es el mismo problema que el control de vencimientos de la flota de
 * vehiculos, y merece la misma respuesta:
 *
 *  - Si se recalcula contra el esquema actual, cambiar el esquema mueve
 *    hacia atras las fechas de una dosis que ya se aplico. Un certificado ya
 *    impreso queda desmentido por la base.
 *  - Si se recalcula contra "hoy", el resultado cambia solo con que pase el
 *    tiempo, y el dia que mas importa (el control, el aviso de atraso, la
 *    revision de carnet) el sistema puede decir una cosa hoy y otra manana.
 *  - Si se recalcula contra la edad, y la edad es un valor derivado, entonces
 *    la fecha de vencimiento depende de una estimacion.
 *
 * Guardandola, la fecha es un hecho, igual que la fecha impressa en el
 * certificado de vacunacion que se le entrega al tutor. Si despues hay que
 * corregirla, se corrige a mano y queda registro de eso.
 *
 * LO QUE SE DERIVA, Y POR QUE ESA PARTE SI SE CALCULA
 * ---------------------------------------------------
 * Si la dosis esta al dia o atrasada SI se calcula en cada lectura, porque
 * depende de la fecha de hoy, que es justamente el dato que tiene que
 * cambiar. La separacion es:
 *
 *   GUARDADO: cuando cae la proxima dosis (un hecho, ya estaba decidido).
 *   DERIVADO: si esa fecha ya paso (cambia con cada dia).
 *
 * Ver App\Services\Vacunas\CalculadorDeProximaDosis y
 * App\Services\Vacunas\CalculadorDeVigencia.
 */
class Vacuna extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'vacunas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'mascota_id',
        'esquema_id',
        'profesional_id',
        'cita_id',
        'fecha_aplicacion',
        'lote',
        'laboratorio',
        'proxima_dose_en',
        'estado',
        'observaciones',
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
            'fecha_aplicacion' => 'date',
            // Fecha, no marca de tiempo: un certificado de vacunacion tiene
            // dia, y el dia es lo que la gente recuerda.
            'proxima_dose_en' => 'date',
            'estado' => EstadoVacuna::class,
        ];
    }

    /** @return BelongsTo<Mascota, $this> */
    public function mascota(): BelongsTo
    {
        return $this->belongsTo(Mascota::class);
    }

    /** @return BelongsTo<EsquemaVacunacion, $this> */
    public function esquema(): BelongsTo
    {
        return $this->belongsTo(EsquemaVacunacion::class);
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    /** @return BelongsTo<Cita, $this> */
    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class);
    }

    /**
     * Si esta dosis esta al dia, vencida o atrasada.
     *
     * Es una DERIVACION, no un dato guardado: depende de la fecha de hoy.
     */
    public function vigencia(): VigenciaVacuna
    {
        return CalculadorDeVigencia::deVacuna($this);
    }

    /**
     * Cuantos dias lleva de atraso, o cuantos le faltan.
     *
     * Positivo significa vencido; negativo significa que todavia falta. Cero
     * es hoy mismo.
     */
    public function diasDeDiferencia(): ?int
    {
        if ($this->proxima_dose_en === null) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->proxima_dose_en, false);
    }
}
