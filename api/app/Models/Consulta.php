<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Consulta: una atencion clinica de una mascota, con su diagnostico.
 *
 * ESTA ES LA FICHA CLINICA, Y ES LO MAS SENSIBLE DE LA APLICACION
 * ---------------------------------------------------------------
 * Aqui hay informacion de salud de un animal. Es informacion de salud de una
 * persona en la practica: casi siempre identifica a un tutor y su domicilio
 * ("el perro de la familia de la esquina, el que se robo el cordero de
 * Martin"). Por eso el acceso pasa por Policy, queda anotado en
 * `accesos_ficha` incluso cuando se deniega, y no se muestra nunca a quien no
 * es profesional. Ver docs/07.
 *
 * Cuelga de `mascota_id`, NUNCA de `tutor_id`. Ver Mascota.
 */
class Consulta extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'consultas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'mascota_id',
        'cita_id',
        'profesional_id',
        'fecha',
        'motivo_consulta',
        'examen_fisico',
        'diagnostico',
        'tratamiento',
        'evolucion',
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
        ];
    }

    /** @return BelongsTo<Mascota, $this> */
    public function mascota(): BelongsTo
    {
        return $this->belongsTo(Mascota::class);
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

    /** @return HasMany<Control, $this> */
    public function controles(): HasMany
    {
        return $this->hasMany(Control::class);
    }

    /** @return HasMany<Prescripcion, $this> */
    public function prescripciones(): HasMany
    {
        return $this->hasMany(Prescripcion::class);
    }
}
