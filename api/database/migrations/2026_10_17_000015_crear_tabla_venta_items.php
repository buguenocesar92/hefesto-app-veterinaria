<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `venta_items`: los renglones del comprobante.
 *
 * `precio_unitario` SE COPIA, NO SE LEE DEL PRODUCTO
 * --------------------------------------------------
 * Es la regla que sostiene la contabilidad: si el comprobante guardara "el
 * producto X, que hoy vale N", al cambiar el precio los comprobantes viejos
 * cambiarian de valor y la caja no cerraria.
 *
 * Con el precio copiado, el comprobante de marzo dice lo que cobro en marzo,
 * aunque el producto hoy valga otra cosa. El cambio de precio de ahora en
 * adelante no toca el pasado.
 *
 * `subtotal` tambien se guarda, aunque se pueda calcular. Es la misma razon:
 * es el dato que se imprime y el que se dispute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('venta_id');
            $tabla->uuid('producto_id');

            // El nombre tambien se copia: si el producto se renombra o se da de
            // baja, el comprobante viejo tiene que seguir diciendo como se
            // llamaba cuando se vendio.
            $tabla->string('nombre_producto', 160);

            $tabla->integer('cantidad');
            $tabla->integer('precio_unitario');
            $tabla->integer('subtotal');
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('venta_id')->references('id')->on('ventas')->cascadeOnDelete();
            $tabla->foreign('producto_id')->references('id')->on('productos');

            $tabla->index(['tenant_id', 'venta_id']);
            $tabla->index(['tenant_id', 'producto_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE venta_items
            ADD CONSTRAINT venta_items_cantidad_check CHECK (cantidad > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE venta_items
            ADD CONSTRAINT venta_items_precios_check CHECK (
                precio_unitario >= 0 AND subtotal >= 0
                AND subtotal = precio_unitario * cantidad
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_items');
    }
};
