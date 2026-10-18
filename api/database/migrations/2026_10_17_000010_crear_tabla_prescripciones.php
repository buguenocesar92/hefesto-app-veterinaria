<?php

declare(strict_types=1);

use App\Enums\EstadoPrescripcion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `prescripciones`: lo que se lleva el tutor para medicar al animal.
 *
 * LO QUE ESTA TABLA NO HACE
 * ------------------------
 * Registra e imprime. **No valida.** No comprueba si el farmaco existe, ni la
 * dosis, ni si el producto exige receta retenida, ni si corresponde a la
 * especie. Eso es del profesional que firma, con su registro profesional
 * vigente.
 *
 * En Chile la prescripcion veterinaria se rige por la Ley 20.584 y su
 * reglamento. **Ese marco normativo no esta verificado en este entorno.** Ver
 * docs/07.
 *
 * `profesional_id` NO ES OPCIONAL. Solo un profesional emite
 * prescripciones: ver PrescripcionPolicy, que no admite a recepcion, mostrador
 * ni administracion.
 *
 * Cuelga de `mascota_id`, nunca de `tutor_id`. Ver consultas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescripciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('mascota_id');
            $tabla->uuid('consulta_id')->nullable();
            $tabla->uuid('profesional_id');
            $tabla->timestampTz('fecha');
            $tabla->string('medicamento', 160);
            $tabla->string('dosis', 160);
            $tabla->string('via', 60)->nullable();
            $tabla->string('frecuencia', 160)->nullable();
            $tabla->unsignedSmallInteger('duracion_dias')->nullable();
            $tabla->text('indicaciones')->nullable();
            $tabla->string('estado', 16)->default('emitida');
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('mascota_id')->references('id')->on('mascotas')->cascadeOnDelete();
            $tabla->foreign('consulta_id')->references('id')->on('consultas')->nullOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales');

            $tabla->index(['tenant_id', 'mascota_id', 'fecha']);
            $tabla->index(['tenant_id', 'estado']);
        });

        $estados = array_map(
            static fn (EstadoPrescripcion $estado): string => "'" . $estado->value . "'",
            EstadoPrescripcion::cases(),
        );

        DB::statement(
            'ALTER TABLE prescripciones ADD CONSTRAINT prescripciones_estado_check CHECK (estado IN (' . implode(', ', $estados) . '))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('prescripciones');
    }
};
