<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La clinica: el TENANT de esta aplicacion.
 *
 * Un animal que se atiende en una clinica de Santiago no se atiende en la de
 * Valparaiso. Por eso cada tabla de negocio lleva `tenant_id` y PostgreSQL
 * aplica Row Level Security sobre esa columna. La justificacion completa esta
 * en apps/portal-autoservicio/docs/03-MULTITENANCY.md y se repite, adaptada a
 * este dominio, en docs/03-MULTITENANCY-Y-ROLES.md.
 *
 * `clinicas` NO lleva RLS a proposito: se consulta durante el ingreso, cuando
 * todavia no hay sesion y no hay contexto que poner. Ver docs/03, seccion
 * "El punto ciego".
 */
class Clinica extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'clinicas';

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'nombre',
        'zona_horaria',
        'correlacion_identidad',
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

    /** @return HasMany<Mascota, $this> */
    public function mascotas(): HasMany
    {
        return $this->hasMany(Mascota::class, 'tenant_id', 'id');
    }

    /** @return HasMany<Tutor, $this> */
    public function tutores(): HasMany
    {
        return $this->hasMany(Tutor::class, 'tenant_id', 'id');
    }
}
