<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `controles`: el seguimiento que quedo pendiente.
 *
 * "Vuelve en diez dias a quitarle los puntos" es un compromiso, y como tal se
 * guarda.
 *
 * `proximo_control_en` SE CALCULA AL REGISTRAR Y SE GUARDA.
 * No se recalcula en cada lectura, por el mismo motivo que en vacunas: una
 * fecha que depende de "hoy" cambia sola con el paso del tiempo, y el dia
 * que mas importa el sistema puede decir una cosa y ayer otra.
 *
 * Cuelga de `mascota_id`, nunca de `tutor_id`. Ver consultas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controles', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('mascota_id');
            $tabla->uuid('consulta_id')->nullable();
            $tabla->uuid('profesional_id')->nullable();
            $tabla->timestampTz('fecha');
            $tabla->string('descripcion', 240);
            $tabla->date('proximo_control_en')->nullable();
            $tabla->boolean('cumplido')->default(false);
            $tabla->text('observaciones')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('mascota_id')->references('id')->on('mascotas')->cascadeOnDelete();
            $tabla->foreign('consulta_id')->references('id')->on('consultas')->nullOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->nullOnDelete();

            // El tablero de controles atrasados: pendientes, por vencer.
            $tabla->index(['tenant_id', 'cumplido', 'proximo_control_en']);
            $tabla->index(['tenant_id', 'mascota_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controles');
    }
};
