<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolClinico;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Persona que trabaja en la clinica.
 *
 * NO tiene contrasena propia: la identidad la emite Authentik por OpenID
 * Connect y Laravel la recibe como cookie de sesion (ver App\Services\Auth).
 * La columna `password` existe solo porque el proveedor por defecto de
 * Laravel la espera; no se escribe nunca y se deja como cadena vacia.
 */
class Usuario extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'usuarios';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'correo',
        'rol',
        'profesional_id',
        'activo',
        'ultimo_ingreso_en',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rol' => RolClinico::class,
            'activo' => 'boolean',
            'ultimo_ingreso_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinica, $this> */
    public function clinica(): BelongsTo
    {
        return $this->belongsTo(Clinica::class, 'tenant_id');
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    /** @return HasMany<Consulta, $this> */
    public function atenciones(): HasMany
    {
        return $this->hasMany(Consulta::class, 'profesional_id');
    }

    /**
     * Si la persona puede abrir una ficha clinica.
     *
     * La respuesta NO mira si hay relacion de cuidado con esa mascota en
     * particular: la decide el rol. El filtro por mascota lo hace la Policy.
     * Ver la ability `ver-ficha-clinica` en App\Providers\AuthServiceProvider.
     */
    public function puedeLeerFichaClinica(): bool
    {
        return $this->rol->puedeLeerFichaClinica();
    }

    /** @return array<string, bool> */
    public function capacidades(): array
    {
        return [
            'agenda' => $this->rol->puedeUsarAgenda(),
            'mostrador' => $this->rol->puedeUsarMostrador(),
            'ficha_clinica' => $this->rol->puedeLeerFichaClinica(),
            'prescribir' => $this->rol->puedePrescribir(),
            'administracion' => $this->rol->administra(),
        ];
    }
}
