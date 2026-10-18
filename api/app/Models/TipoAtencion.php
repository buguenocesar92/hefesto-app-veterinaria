<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de atencion: cuanto dura y cuanto vale.
 *
 * `bloque_vacunacion` es la bandera que hace que este sistema sea distinto de
 * una agenda medica comun.
 *
 * POR QUE LA VACUNACION NECESITA SU PROPIO BLOQUE
 * -----------------------------------------------
 * Una consulta puede durar 40 minutos y ocupar un consultorio con un animal
 * que necesita trato. Una vacuna dura lo que dura una inyeccion: es rapida,
 * es repetitiva y **es la que llena la agenda y la que da la plata**. En una
 * clinica veterinaria tipo, la atencion mas frecuente del dia es la vacuna.
 *
 * Mezclarlas en la misma cola produce el problema de siempre: la gente va por
 * una vacuna y espera veinte minutos a que se desocupe el consultorio de al
 * lado. Con el bloque separado, la agenda de vacunacion es rapida y masiva, y
 * se puede abrir un turno de vacunas un domingo sin tocar la agenda de
 * consultas.
 *
 * La duracion sale de aca, no de la cita: el profesional que atiende decide
 * cuanto se le da a un control de seguimiento, no el que agenda. Ver docs/01.
 */
class TipoAtencion extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'tipos_atencion';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'descripcion',
        'duracion_minutos',
        'bloque_vacunacion',
        'precio',
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
            'duracion_minutos' => 'integer',
            'bloque_vacunacion' => 'boolean',
            'precio' => 'decimal:0',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Cita, $this> */
    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class);
    }
}
