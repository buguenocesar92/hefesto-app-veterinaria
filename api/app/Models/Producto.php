<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto del mostrador.
 *
 * `stock` es el dato que hace que este modulo exista. **Vender descuenta
 * stock**, y el descuento ocurre dentro de la misma transaccion que crea la
 * venta: si el comprobante se guardara antes y el stock se descontara despues,
 * una falla en el medio deja una venta sin descuento, que es el peor error
 * posible en un mostrador porque nadie lo nota hasta el cierre de caja.
 *
 * `stock_minimo` es la alarma de reposicion. No bloquea la venta: un animal
 * necesita su alimento aunque se haya acabado. Solo avisa.
 */
class Producto extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'productos';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'sku',
        'nombre',
        'descripcion',
        'categoria',
        'precio',
        'stock',
        'stock_minimo',
        'unidad',
        'requiere_receta',
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
            'precio' => 'decimal:0',
            'stock' => 'integer',
            'stock_minimo' => 'integer',
            'requiere_receta' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<VentaItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    /** Si el stock quedo en o bajo el minimo. */
    public function porReponer(): bool
    {
        return $this->stock_minimo !== null && $this->stock <= $this->stock_minimo;
    }

    /** Si hay unidades para vender. */
    public function disponible(): bool
    {
        return $this->activo && $this->stock > 0;
    }
}
