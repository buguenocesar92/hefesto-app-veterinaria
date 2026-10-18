<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `productos`: el catalogo del mostrador.
 *
 * ES EL SEGUNDO INGRESO, Y NO ES MENOR
 * ------------------------------------
 * En una clinica veterinaria, la venta de alimento, antiparasitarios,
 * collarines y nutrientes se tiene en cuenta como parte de la atencion: el animal
 * sale de la consulta con la dosis correcta en la mano. Este modulo no es un
 * extra.
 *
 * `stock` SE DESCUENTA AL VENDER, DENTRO DE LA MISMA TRANSACCION QUE LA VENTA
 * ----------------------------------------------------------------------
 * Si el comprobante se guardara primero y el stock se descontara despues, una
 * falla en el medio deja una venta sin descuento. Es el peor error posible en
 * un mostrador: no se nota hasta el cierre de caja.
 *
 * `stock_minimo` es la alarma de reposicion. NO BLOQUEA LA VENTA: un animal
 * necesita su alimento aunque se haya acabado. Un bloqueo de stock en un
 * mostrador es la forma rapida de perder al cliente.
 *
 * `requiere_receta` marca los productos que no se despachan sin receta
 * (antibioticos y otros). Es un aviso para la persona que cobra, no una
 * validacion normativa: **el sistema no verifica ningun registro sanitario de
 * productos**. Ver docs/07 y docs/10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('sku', 40);
            $tabla->string('nombre', 160);
            $tabla->text('descripcion')->nullable();
            $tabla->string('categoria', 80)->nullable();
            $tabla->integer('precio');

            // Stock fisico disponible. Lo descuenta la venta.
            $tabla->integer('stock')->default(0);

            // Umbral de la alarma de reposicion.
            $tabla->integer('stock_minimo')->nullable();

            $tabla->string('unidad', 24)->default('unidad');
            $tabla->boolean('requiere_receta')->default(false);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();

            $tabla->unique(['tenant_id', 'sku']);
            $tabla->index(['tenant_id', 'activo']);
            $tabla->index(['tenant_id', 'categoria']);

            // El stock no puede ser negativo: es la unica defensa de datos
            // contra el error de tipeo en cantidad.
            DB::statement('ALTER TABLE productos ADD CONSTRAINT productos_stock_check CHECK (stock >= 0)');
            DB::statement('ALTER TABLE productos ADD CONSTRAINT productos_precio_check CHECK (precio >= 0)');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
