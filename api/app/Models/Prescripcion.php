<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPrescripcion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prescripcion veterinaria.
 *
 * LO QUE ESTE SISTEMA NO HACE
 * --------------------------
 * Registra e imprime. **No valida la prescripcion.** No comprueba si el
 * farmaco existe, ni la dosis, ni la especie, ni si ese producto exige
 * receta retenida. Eso es del profesional que la firma, con su registro
 * profesional vigente.
 *
 * En Chile, la prescripcion veterinaria se rige por la Ley 20.584 y su
 * reglamento. **Ese marco no esta verificado en este entorno** y su
 * cumplimiento es responsabilidad de la clinica y del profesional. Ver docs/07.
 *
 * SOLO UN PROFESIONAL EMITE
 * -------------------------
 * `profesional_id` no es opcional y la Policy no admite a recepcion,
 * mostrador ni administracion. Ver PrescripcionPolicy.
 */
class Prescripcion extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'prescripciones';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'mascota_id',
        'consulta_id',
        'profesional_id',
        'fecha',
        'medicamento',
        'dosis',
        'via',
        'frecuencia',
        'duracion_dias',
        'indicaciones',
        'estado',
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
            'duracion_dias' => 'integer',
            'estado' => EstadoPrescripcion::class,
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

    /**
     * Texto para el comprobante que se lleva el tutor.
     *
     * Sale de los campos guardados, no de un modelo: el comprobante no puede
     * decir una cosa y la base otra.
     */
    public function paraImprimir(): string
    {
        $lineas = [
            'PRESCRIPCION VETERINARIA',
            '',
            'Mascota: ' . $this->mascota?->identificacion(),
            'Tutor: ' . $this->mascota?->tutor?->nombreCompleto(),
            'Fecha: ' . ($this->fecha?->format('d-m-Y') ?? '-'),
            '',
            'Medicamento: ' . $this->medicamento,
            'Dosis: ' . $this->dosis,
        ];

        if ($this->via !== null && $this->via !== '') {
            $lineas[] = 'Via: ' . $this->via;
        }

        if ($this->frecuencia !== null && $this->frecuencia !== '') {
            $lineas[] = 'Frecuencia: ' . $this->frecuencia;
        }

        if ($this->duracion_dias !== null) {
            $lineas[] = 'Duracion: ' . $this->duracion_dias . ' dias';
        }

        if ($this->indicaciones !== null && $this->indicaciones !== '') {
            $lineas[] = '';
            $lineas[] = 'Indicaciones: ' . $this->indicaciones;
        }

        $lineas[] = '';
        $lineas[] = 'Profesional: ' . $this->profesional?->nombre;
        $lineas[] = 'Registro: ' . ($this->profesional?->registro_profesional ?? 'sin registrar');

        return implode("\n", $lineas);
    }
}
