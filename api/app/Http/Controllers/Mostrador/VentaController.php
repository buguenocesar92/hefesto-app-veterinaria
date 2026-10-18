<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mostrador;

use App\Http\Controllers\Controller;
use App\Enums\MedioPago;
use App\Models\Producto;
use App\Models\Tutor;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Venta de mostrador.
 *
 * ===================================================================
 * EL STOCK SE DESCUENTA DENTRO DE LA MISMA TRANSACCION QUE LA VENTA
 * ===================================================================
 *
 * Esta clase existe, en su mayor parte, para que eso sea cierto.
 *
 * Si el comprobante se guardara primero y el stock se descontara despues, una
 * falla en el medio dejaria una venta sin descuento. Es el peor error posible
 * en un mostrador, porque **nadie lo nota hasta el cierre de caja**: el
 * cliente se llevo el alimento, el comprobante dice que se vendio, y el stock
 * sigue marcando lo mismo de antes.
 *
 * Por eso TODO ocurre en una transaccion: se comprueba el stock, se descuenta,
 * se crea la venta y sus renglones, y si algo falla no queda nada a medias.
 *
 * EL PRECIO SE COPIA
 * ------------------
 * `precio_unitario` y `nombre_producto` se copian al renglone. Si guardaran
 * "el producto X, que hoy vale N", al cambiar el precio los comprobantes
 * viejos cambiarian de valor y la caja no cerraria. Con la copia, el
 * comprobante de marzo dice lo que cobro en marzo.
 *
 * LO QUE NO SE TOCA
 * -----------------
 * La ficha de la mascota. Que una persona compre antiparasitario para su perro
 * es un dato COMERCIAL; que el perro tenga gusanos es un dato de SALUD. Este
 * controlador nunca lee `consultas`. Ver docs/03.
 */
class VentaController extends Controller
{
    public function index(): JsonResponse
    {
        $ventas = Venta::query()
            ->with('tutor:id,nombres,apellidos')
            ->latest('fecha')
            ->limit(50)
            ->get()
            ->map(static fn (Venta $venta): array => [
                'id' => $venta->id,
                'numero' => $venta->numero(),
                'fecha' => $venta->fecha?->toDateTimeString(),
                'tutor' => $venta->tutor?->nombreCompleto(),
                'total' => (int) $venta->total,
                'medio_pago' => $venta->medio_pago->value,
                'anulada' => $venta->anulada,
            ]);

        return response()->json(['ventas' => $ventas]);
    }

    /**
     * Registra una venta.
     */
    public function store(\Illuminate\Http\Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'tutor_id' => ['nullable', 'uuid'],
            'medio_pago' => ['required', 'string', 'in:' . implode(',', array_column(MedioPago::cases(), 'value'))],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.producto_id' => ['required', 'uuid'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $usuario = $peticion->user();

        if ($datos['tutor_id'] !== null) {
            $existe = Tutor::query()->whereKey($datos['tutor_id'])->exists();

            if (! $existe) {
                return $this->error('El tutor no existe en esta clinica.');
            }
        }

        try {
            $venta = DB::transaction(function () use ($datos, $usuario): Venta {
                $total = 0;
                $filas = [];

                /*
                 * PRIMERO SE COMPRUEBA Y SE DESCUENTA, despues se guarda la
                 * venta. Todo en la misma transaccion.
                 */
                foreach ($datos['items'] as $item) {
                    /*
                     * `lockForUpdate` toma un bloqueo de fila: si dos personas
                     * venden el ultimo paquete al mismo tiempo, la segunda
                     * espera a que la primera termine y recien ahi ve el stock
                     * real. Sin esto, las dos leen el mismo stock y las dos
                     * venden.
                     */
                    $producto = Producto::query()
                        ->whereKey($item['producto_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($producto === null) {
                        throw new \RuntimeException('Uno de los productos no existe en esta clinica.');
                    }

                    if (! $producto->activo) {
                        throw new \RuntimeException('El producto «' . $producto->nombre . '» esta inactivo.');
                    }

                    if ($producto->stock < $item['cantidad']) {
                        throw new \RuntimeException(
                            'No hay stock suficiente de «' . $producto->nombre . '». '
                            . 'Disponible: ' . $producto->stock . '.'
                        );
                    }

                    $subtotal = $producto->precio * $item['cantidad'];

                    $producto->stock -= $item['cantidad'];
                    $producto->save();

                    $total += $subtotal;

                    $filas[] = [
                        'producto_id' => $producto->id,
                        'nombre_producto' => $producto->nombre,
                        'cantidad' => $item['cantidad'],
                        'precio_unitario' => $producto->precio,
                        'subtotal' => $subtotal,
                    ];
                }

                $venta = Venta::query()->create([
                    'tenant_id' => $usuario->tenant_id,
                    'tutor_id' => $datos['tutor_id'],
                    'usuario_id' => $usuario->id,
                    'fecha' => now(),
                    'total' => $total,
                    'medio_pago' => $datos['medio_pago'],
                    'anulada' => false,
                ]);

                foreach ($filas as $fila) {
                    VentaItem::query()->create($fila + [
                        'tenant_id' => $usuario->tenant_id,
                        'venta_id' => $venta->id,
                    ]);
                }

                return $venta;
            });
        } catch (\RuntimeException $excepcion) {
            // El mensaje es para quien esta en el mostrador: dice que paso, en
            // espanol y sin detalles internos.
            return $this->error($excepcion->getMessage());
        }

        $venta->load('items');

        return response()->json([
            'mensaje' => 'Venta registrada.',
            'venta' => [
                'id' => $venta->id,
                'numero' => $venta->numero(),
                'total' => (int) $venta->total,
                'items' => $venta->items->map(static fn (VentaItem $i): array => [
                    'nombre' => $i->nombre_producto,
                    'cantidad' => $i->cantidad,
                    'subtotal' => (int) $i->subtotal,
                ])->all(),
            ],
        ], 201);
    }
}
