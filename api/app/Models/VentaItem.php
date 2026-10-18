<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglon de una venta de mostrador.
 *
 * `precio_unitario` SE COPIA al vender, no se lee del producto.
 *
 * Es la misma regla que en cualquier sistema de ventas, y aqui importa mas
 * porque el precio de los productos veterinarios cambia seguido: si el
 * comprobante guardara "el producto X, que hoy vale N", al cambiar el precio
 * los comprobantes viejos cambiarian de valor y la contabilidad no cerraria.
 *
 * Con el precio copiado, el comprobante de marzo dice lo que cobro en marzo,
 * aunque el producto hoy valga otra cosa.
 */
class VentaItem extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'venta_items';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'venta_id',
        'producto_id',
        'cantidad',
        'precio_unitario',
        'subtotal',
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
            'cantidad' => 'integer',
            'precio_unitario' => 'decimal:0',
            'subtotal' => 'decimal:0',
        ];
    }

    /** @return BelongsTo<Venta, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
