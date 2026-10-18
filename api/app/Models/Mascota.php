<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Especie;
use App\Enums\Sexo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LA MASCOTA: el paciente. De aqui cuelga TODO lo clinico.
 *
 * ESTA ES LA TABLA CENTRAL DEL DOMINIO
 * ------------------------------------
 * La ficha clinica, las consultas, los controles, las prescripciones y las
 * vacunas cuelgan de `mascota_id`. **Ninguna de ellas cuelga del tutor.**
 *
 * POR QUE NO CUELGA DEL TUTOR, Y POR QUE ES EL ERROR MAS SERIO POSIBLE
 * -------------------------------------------------------------------
 * Un tutor puede tener tres mascotas. Si el historial se guarda o se consulta
 * por tutor, las tres historias se mezclan en una sola linea del tiempo: la
 * radiografia del perro aparece pegada al diagnóstico de la gata, el peso de
 * una se compara con la temperatura de la otra, y al.recetar se elige el
 * medicamento que se le dio al otro animal. **Ninguno de esos animales esta
 * enfermo**: lo que se mezclo son los registros.
 *
 * El dano es doble. Es clinico, porque una dosificacion calculada sobre el
 * peso equivocado puede dañar al animal. Y es de privacidad: el diagnostico
 * de una mascota es informacion de salud que identifica al tutor y su
 * domicilio (ver docs/07).
 *
 * El filtro por `mascota_id` esta en todas partes y hay pruebas que lo
 * comprueban: `tests/Feature/Aislamiento/HistorialNoSeMezclaEntreMascotasDelMismoTutorTest.php`.
 */
class Mascota extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'mascotas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'tutor_id',
        'nombre',
        'especie',
        'raza',
        'sexo',
        'fecha_nacimiento',
        'peso_kg',
        'altura_cm',
        'color',
        'microchip',
        'esterilizado',
        'activo',
        'fecha_fallecimiento',
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
            'sexo' => Sexo::class,
            'fecha_nacimiento' => 'date',
            'fecha_fallecimiento' => 'date',
            'peso_kg' => 'decimal:2',
            'altura_cm' => 'decimal:1',
            'esterilizado' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tutor, $this> */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }

    /** @return BelongsTo<Clinica, $this> */
    public function clinica(): BelongsTo
    {
        return $this->belongsTo(Clinica::class, 'tenant_id');
    }

    /**
     * Historial clinico, TODO por esta mascota y nunca por el tutor.
     *
     * @return HasMany<Consulta, $this>
     */
    public function consultas(): HasMany
    {
        return $this->hasMany(Consulta::class);
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

    /** @return HasMany<Vacuna, $this> */
    public function vacunas(): HasMany
    {
        return $this->hasMany(Vacuna::class);
    }

    /** @return HasMany<Cita, $this> */
    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class);
    }

    /**
     * Edad en meses, que es la unidad en que se razonan los esquemas de
     * vacunacion.
     *
     * Se CALCULA y no se guarda: una edad guardada queda mala al dia
     * siguiente, y el error se acumula en silencio hasta que un esquema pide
     * una dosis para un animal de dos meses que en realidad tiene ocho.
     */
    public function edadEnMeses(): ?int
    {
        if ($this->fecha_nacimiento === null) {
            return null;
        }

        $nacimiento = $this->fecha_nacimiento->startOfDay();
        $hoy = now()->startOfDay();

        if ($nacimiento->isFuture()) {
            // Una fecha de nacimiento futura es un error de tipeo, no una edad
            // negativa: se trata como desconocida.
            return null;
        }

        $meses = ($hoy->year - $nacimiento->year) * 12 + ($hoy->month - $nacimiento->month);

        // Si todavia no se cumplio el dia del mes, no compte el mes entero.
        if ($hoy->day < $nacimiento->day) {
            $meses--;
        }

        return max(0, $meses);
    }

    /**
     * Texto que identifica a la mascota en la agenda y en las recetas.
     *
     * Incluye especie y edad porque en una clinica con tres perros del mismo
     * nombre, la edad y la raza son lo que evita administrar un farmaco a la
     * edad equivocada.
     */
    public function identificacion(): string
    {
        $edad = $this->edadEnMeses();

        $detalle = $this->especie->etiqueta();

        if ($this->raza !== null && $this->raza !== '') {
            $detalle .= ' ' . $this->raza;
        }

        if ($edad !== null) {
            $detalle .= $edad >= 24
                ? ', ' . intdiv($edad, 12) . ' anios'
                : ', ' . $edad . ' meses';
        }

        return "{$this->nombre} ({$detalle})";
    }
}
