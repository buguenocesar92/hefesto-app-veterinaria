<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Profesional veterinario de la clinica.
 *
 * Es distinto de `Usuario`: el profesional es la persona con registro
 * profesional y es la que aparece en la agenda, y un mismo profesional puede
 * tener mas de una cuenta de usuario a lo largo del tiempo. La tabla de
 * profesionales es su identificacion; la de usuarios, su acceso.
 */
class Profesional extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'profesionales';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'rut',
        'registro_profesional',
        'especialidad',
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

    /** @return HasMany<Usuario, $this> */
    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    /** @return HasMany<Cita, $this> */
    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class);
    }
}
