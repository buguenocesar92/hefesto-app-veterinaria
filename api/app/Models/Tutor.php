<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EL TUTOR: la persona que decide.
 *
 * LA DIFERENCIA CON MEDICINA HUMANA, Y POR QUE ESTA TABLA ES LA CENTRAL
 * ------------------------------------------------------------------------
 * En una ficha humana, el paciente es el titular de la ficha. En veterinaria
 * **el paciente casi nunca puede hablar y nunca firma**: quien llega, quien
 * autoriza una radiografia y quien recibe la receta es una persona, el tutor,
 * y su nombre no coincide con el del animal.
 *
 * De ahi salen tres consecuencias de modelado que no existen en una clinica
 * humana:
 *
 *  1. El tutor es el SUJETO de los datos personales. Es a el a quien protege
 *     la Ley 19.628, porque es la persona natural. El animal no es persona
 *     y no tiene derechos de datos. Ver docs/07.
 *
 *  2. Un tutor tiene VARIAS mascotas, y esa es la situacion normal, no la
 *     excepcion. La relacion es de uno a muchos y NO se modela con una
 *     tabla puente: una mascota tiene un solo tutor responsable en este
 *     sistema.
 *
 *  3. **El dato clinico cuelga de la MASCOTA, nunca del tutor.** Un perro
 *     y un gato del mismo tutor tienen historiales distintos. El tutor es
 *     la puerta de entrada, no el contenedor de la historia. Ver Mascota y
 *     la prueba `HistorialNoSeMezclaEntreMascotasDelMismoTutorTest`.
 *
 * LO QUE ES ESTA TABLA Y LO QUE NO
 * --------------------------------
 * Aqui hay IDENTIFICACION y CONTACTO: nombre, RUT, como llamar, donde vive.
 * Es informacion personal de la persona que Firmo como tutor. No hay
 * diagnostico, ni evolucion, ni receta: eso vive en la ficha de la mascota.
 */
class Tutor extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'tutores';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'rut',
        'nombres',
        'apellidos',
        'telefono',
        'correo',
        'direccion',
        'comuna',
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
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Clinica, $this> */
    public function clinica(): BelongsTo
    {
        return $this->belongsTo(Clinica::class, 'tenant_id');
    }

    /**
     * Las mascotas de este tutor.
     *
     * @return HasMany<Mascota, $this>
     */
    public function mascotas(): HasMany
    {
        return $this->hasMany(Mascota::class);
    }

    /**
     * Nombre completo, como aparece en la agenda y en el mostrador.
     */
    public function nombreCompleto(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }

    /**
     * El contacto mas confiable para una llamada de recordatorio.
     *
     * Primero el telefono: en una clinica veterinaria chilena el aviso de
     * vacunas es una llamada, y el correo se pierde. Es una regla de trabajo,
     * no una regla legal, y se puede cambiar.
     */
    public function contactoPreferido(): ?string
    {
        return $this->telefono !== null && trim($this->telefono) !== ''
            ? $this->telefono
            : $this->correo;
    }
}
