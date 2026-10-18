<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mostrador;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalogo del mostrador: que hay, cuanto vale y cuanto queda.
 *
 * POR QUE NO LEE NINGUNA FICHA
 * ---------------------------
 * Esta clase toca `productos` y nada mas. No lee `consultas`, ni `vacunas`,
 * ni el historial de la mascota.
 *
 * La razon es concreta: quien cobra necesita el precio y el stock. Si pudiera
 * abrir fichas, sabria que animal esta bajo de peso, o que perro tiene una
 * enfermedad cronica. Eso es informacion de salud, y no hace falta para
 * cobrar. Ver docs/03.
 *
 * `requiere_receta` ES UN AVISO, NO UN BLOQUEO
 * --------------------------------------------
 * Marca los productos que no deberian despacharse sin receta. El sistema **no
 * verifica ningun registro sanitario de productos**: no hay conexion con
 * ningun registro, y la validacion es de la persona y de la clinica. Ver
 * docs/07.
 */
class ProductoController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $busqueda = trim((string) $peticion->query('q', ''));

        $productos = Producto::query()
            ->when($busqueda !== '', function ($q) use ($busqueda): void {
                $q->where(function ($interna) use ($busqueda): void {
                    $interna->where('nombre', 'ilike', '%' . $busqueda . '%')
                        ->orWhere('sku', 'ilike', '%' . $busqueda . '%');
                });
            })
            ->orderBy('nombre')
            ->limit(200)
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
                'disponible' => $p->disponible(),
                'requiere_receta' => $p->requiere_receta,
                'unidad' => $p->unidad,
            ])->all();

        return response()->json([
            'productos' => $productos,
            'por_reponer' => count(array_filter($productos, static fn (array $p): bool => $p['por_reponer'])),
        ]);
    }

    public function show(Producto $producto): JsonResponse
    {
        return response()->json([
            'producto' => [
                'id' => $producto->id,
                'sku' => $producto->sku,
                'nombre' => $producto->nombre,
                'descripcion' => $producto->descripcion,
                'categoria' => $producto->categoria,
                'precio' => (int) $producto->precio,
                'stock' => $producto->stock,
                'stock_minimo' => $producto->stock_minimo,
                'por_reponer' => $producto->porReponer(),
                'requiere_receta' => $producto->requiere_receta,
                'activo' => $producto->activo,
            ],
        ]);
    }
}
