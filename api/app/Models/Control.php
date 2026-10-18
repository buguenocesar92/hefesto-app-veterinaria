<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Control: el seguimiento que quedo pendiente de una consulta.
 *
 * "Vuelve en diez dias a quitarle los puntos": esa frase es un compromiso con
 * la persona y con el animal, y por eso se guarda junto al profesional que la
 * escribio.
 *
 * `proximo_control_en` se CALCULA al registrar la consulta y se guarda. No se
 * recalcula en cada lectura, por el mismo motivo que en vacunas: si depende
 * de "hoy", el dia que se necesita mas el sistema puede decir otra cosa.
 * Ver docs/02.
 */
class Control extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'controles';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'mascota_id',
        'consulta_id',
        'profesional_id',
        'fecha',
        'descripcion',
        'proximo_control_en',
        'cumplido',
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
            'fecha' => 'datetime',
            'proximo_control_en' => 'date',
            'cumplido' => 'boolean',
        ];
    }

    /** @return BelongsTo<Mascota, $this> */
    public function mascota(): BelongsTo
    {
        return $this->belongsTo(Mascota::class);
    }

    /** @return BelongsTo<Consulta, $this> */
    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    /** Si la fecha de control ya paso y sigue sin cumplirse. */
    public function atrasado(): bool
    {
        return ! $this->cumplido
            && $this->proximo_control_en !== null
            && $this->proximo_control_en->isPast();
    }
}
