<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MedioPago;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venta de mostrador.
 *
 * POR QUE LA VENTA GUARDA `tutor_id`
 * ---------------------------------
 * Casi siempre el que paga es un tutor con una o mas mascotas. Guardar el
 * tutor permite despues preguntar "que le vendieron a esta persona el mes
 * pasado" sin recorrer todas las ventas.
 *
 * **Y ESTO NO ES LA FICHA CLINICA.** Que una persona haya comprado antiparasitario
 * para su perro es un dato comercial. Si se pudiera leer desde el mostrador,
 * se sabria que tiene un perro con problemas digestivos. Por eso el modulo de
 * mostrador no lee la ficha de la mascota, solo la agenda y el tutor.
 */
class Venta extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'ventas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'tutor_id',
        'usuario_id',
        'fecha',
        'total',
        'medio_pago',
        'anulada',
        'motivo_anulacion',
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
            'total' => 'decimal:0',
            'medio_pago' => MedioPago::class,
            'anulada' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tutor, $this> */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** @return HasMany<VentaItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    /** Numero de comprobante legible, para el cajero. */
    public function numero(): string
    {
        return 'V-' . str_pad((string) substr((string) $this->id, 0, 8), 8, '0', STR_PAD_LEFT);
    }
}
