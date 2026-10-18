<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoCita;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cita en la agenda: un animal, un profesional, un horario.
 *
 * POR QUE LA CITA GUARDA `tutor_id` Y ADEMAS `mascota_id`
 * -------------------------------------------------------
 * La cita es un hecho del dia: el perro de la Sra. Ruiz, a las diez. Si se
 * guardara solo `mascota_id`, la agenda tendria que hacer un JOIN para llegar
 * al nombre que se muestra en pantalla y para poder llamar.
 *
 * Guardar el tutor tambien es una decision, no un descuido: cuando el animal
 * se da de baja o cambia de tutor, las citas ya agendadas siguen teniendo que
 * decir a quien llamar. Sin `tutor_id` congelado, una cita de la semana
 * siguiente queda huerfana.
 *
 * LO QUE NO SE GUARDA ACA
 * -----------------------
 * Ningun dato clinico. El motivo de la consulta es de la persona que recibe
 * ("trae a la mascota por un problema en el oido"), no un diagnostico.
 */
class Cita extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'citas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'mascota_id',
        'tutor_id',
        'profesional_id',
        'tipo_atencion_id',
        'inicio',
        'fin',
        'estado',
        'motivo',
        'notas',
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
            'inicio' => 'datetime',
            'fin' => 'datetime',
            'estado' => EstadoCita::class,
        ];
    }

    /** @return BelongsTo<Mascota, $this> */
    public function mascota(): BelongsTo
    {
        return $this->belongsTo(Mascota::class);
    }

    /** @return BelongsTo<Tutor, $this> */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    /** @return BelongsTo<TipoAtencion, $this> */
    public function tipoAtencion(): BelongsTo
    {
        return $this->belongsTo(TipoAtencion::class);
    }

    /** Si es atencion de vacunacion (bloque rapido y masivo). */
    public function esVacunacion(): bool
    {
        return (bool) $this->tipoAtencion?->bloque_vacunacion;
    }
}
