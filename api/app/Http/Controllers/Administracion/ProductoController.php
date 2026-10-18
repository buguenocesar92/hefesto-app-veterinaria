<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administracion del catalogo de productos.
 *
 * Es un CRUD comun, con dos cosas que no son comunes:
 *
 *  - `stock_minimo` es la alarma de reposicion, y NO BLOQUEA LA VENTA. Un
 *    animal necesita su alimento aunque se haya acabado; bloquear el mostrador
 *    es la forma rapida de perder al cliente.
 *  - El precio se copia a los comprobantes, asi que cambiar un precio NO
 *    cambia los comprobantes viejos. Ver VentaItem.
 *
 * Editar stock a mano queda registrado como cambio de la persona, no como
 * venta. El sistema no lleva un historial de movimientos de inventario:
 * **eso NO esta implementado.** Ver docs/10.
 */
class ProductoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'productos' => Producto::query()
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Producto $p): array => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'nombre' => $p->nombre,
                    'categoria' => $p->categoria,
                    'precio' => (int) $p->precio,
                    'stock' => $p->stock,
                    'stock_minimo' => $p->stock_minimo,
                    'por_reponer' => $p->porReponer(),
                    'requiere_receta' => $p->requiere_receta,
                    'activo' => $p->activo,
                ])->all(),
        ]);
    }

    public function show(Producto $producto): JsonResponse
    {
        return response()->json(['producto' => [
            'id' => $producto->id,
            'sku' => $producto->sku,
            'nombre' => $producto->nombre,
            'descripcion' => $producto->descripcion,
            'categoria' => $producto->categoria,
            'precio' => (int) $producto->precio,
            'stock' => $producto->stock,
            'stock_minimo' => $producto->stock_minimo,
            'unidad' => $producto->unidad,
            'requiere_receta' => $producto->requiere_receta,
            'activo' => $producto->activo,
        ]]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'sku' => ['required', 'string', 'max:40', 'unique:productos,sku'],
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'categoria' => ['nullable', 'string', 'max:80'],
            'precio' => ['required', 'integer', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'stock_minimo' => ['nullable', 'integer', 'min:0'],
            'unidad' => ['nullable', 'string', 'max:24'],
            'requiere_receta' => ['nullable', 'boolean'],
        ], [
            'sku.required' => 'Indique el codigo del producto.',
            'sku.unique' => 'Ya existe un producto con ese codigo.',
            'precio.min' => 'El precio no puede ser negativo.',
            'stock.min' => 'El stock no puede ser negativo.',
        ]);

        $producto = Producto::query()->create($datos + [
            'tenant_id' => $peticion->user()->tenant_id,
            'activo' => true,
        ]);

        return response()->json([
            'mensaje' => 'Producto creado.',
            'producto' => ['id' => $producto->id],
        ], 201);
    }

    public function update(Request $peticion, Producto $producto): JsonResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'categoria' => ['nullable', 'string', 'max:80'],
            'precio' => ['sometimes', 'required', 'integer', 'min:0'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'stock_minimo' => ['nullable', 'integer', 'min:0'],
            'requiere_receta' => ['nullable', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $producto->fill($datos)->save();

        return response()->json([
            'mensaje' => 'Producto actualizado.',
            'producto' => ['id' => $producto->id, 'precio' => (int) $producto->precio, 'stock' => $producto->stock],
            'nota' => 'Los comprobantes de ventas anteriores conservan el precio con el que se vendieron.',
        ]);
    }
}
