<?php

declare(strict_types=1);

use App\Enums\MedioPago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `ventas`: el comprobante del mostrador.
 *
 * UNA VENTA ES UN HECHO FINANCIERO, NO UNA FICHA
 * ----------------------------------------------
 * Guardar el `tutor_id` permite preguntar "que le vendieron a esta persona el
 * mes pasado". Eso es dato comercial.
 *
 * Pero hay que ser claros con el limite: **que una persona compre
 * antiparasitario para su perro es un dato comercial; que el perro tenga
 *gusanos es un dato de salud.** Si el mostrador pudiera leer la ficha de la
 * mascota, sabria lo segundo. Por eso el modulo de mostrador no toca
 * `consultas`: solo agenda, tutor y productos. Ver docs/03.
 *
 * `anulada` EN LUGAR DE BORRAR
 * ----------------------------
 * Una venta anulada existio y hay que poder probarlo. Ademas, anular tiene que
 * **devolver el stock**, dentro de la misma transaccion. Ver
 * App\Services\Mostrador\RegistradorDeVenta.
 *
 * NO HAY INTEGRACION DE PAGO
 * --------------------------
 * `medio_pago` es lo que la persona **declara** que uso. El sistema no habla
 * con ningun terminal ni con una pasarela. Ver docs/09.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('tutor_id')->nullable();
            $tabla->uuid('usuario_id');
            $tabla->timestampTz('fecha');
            $tabla->integer('total');
            $tabla->string('medio_pago', 16);
            $tabla->boolean('anulada')->default(false);
            $tabla->string('motivo_anulacion', 240)->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('tutor_id')->references('id')->on('tutores')->nullOnDelete();
            $tabla->foreign('usuario_id')->references('id')->on('usuarios');

            // El cierre de caja del dia consulta por fecha.
            $tabla->index(['tenant_id', 'fecha']);
            $tabla->index(['tenant_id', 'anulada']);
            $tabla->index(['tenant_id', 'tutor_id', 'fecha']);
        });

        $medios = array_map(
            static fn (MedioPago $medio): string => "'" . $medio->value . "'",
            MedioPago::cases(),
        );

        DB::statement(
            'ALTER TABLE ventas ADD CONSTRAINT ventas_medio_pago_check CHECK (medio_pago IN (' . implode(', ', $medios) . '))'
        );

        DB::statement('ALTER TABLE ventas ADD CONSTRAINT ventas_total_check CHECK (total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
