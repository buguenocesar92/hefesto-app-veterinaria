<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `consultas`: la ficha clinica de una MASCOTA.
 *
 * NOTA LA AUSENCIA DE `tutor_id`
 * ------------------------------
 * Esta tabla cuelga de `mascota_id` y **no tiene `tutor_id` a proposito**.
 * Un tutor puede tener tres mascotas; si la historia se guardara o se
 * buscara por tutor, las tres se mezclarian en una sola linea del tiempo.
 *
 * Se verifica con
 * `tests/Feature/Aislamiento/HistorialNoSeMezclaEntreMascotasDelMismoTutorTest.php`.
 *
 * LA CONSULTA ES EL CONTENEDOR DE LA VISITA
 * -----------------------------------------
 * Diagnostico, tratamiento, evolucion y receta cuelgan de la consulta a la
 * que pertenecen, no de la mascota suelta. Es lo que permite decir "el
 * diagnostico de la ultima visita" sin ambiguityo.
 *
 * `cita_id` es opcional: puede haber consulta sin cita (una urgencia que
 * llega sin turno) y cita sin consulta (se agendo y no vino).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('mascota_id');
            $tabla->uuid('cita_id')->nullable();
            $tabla->uuid('profesional_id');
            $tabla->timestampTz('fecha');
            $tabla->string('motivo_consulta', 240)->nullable();
            $tabla->text('examen_fisico')->nullable();
            $tabla->text('diagnostico')->nullable();
            $tabla->text('tratamiento')->nullable();
            $tabla->text('evolucion')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('mascota_id')->references('id')->on('mascotas')->cascadeOnDelete();
            $tabla->foreign('cita_id')->references('id')->on('citas')->nullOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales');

            // La ficha se lee siempre como historial de UNA mascota, en orden
            // cronologico. Este indice es el que hace rapida esa consulta.
            $tabla->index(['tenant_id', 'mascota_id', 'fecha']);
            $tabla->index(['tenant_id', 'profesional_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas');
    }
};
