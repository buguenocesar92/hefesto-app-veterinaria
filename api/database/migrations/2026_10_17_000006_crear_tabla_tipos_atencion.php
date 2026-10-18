<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tipos_atencion`: el catalogo de la agenda.
 *
 * `bloque_vacunacion` SEPARA EL SERVICIO QUE LLENA LA AGENDA DEL RESTO
 * ----------------------------------------------------------------------
 * En una clinica veterinaria tipo, la atencion mas frecuente del dia no es
 * una consulta: es una vacuna. Es rapida, se hace en cadena y es la que da
 * la plata.
 *
 * Mezclarla con las consultas produce el problema de siempre: la gente va por
 * una vacuna, espera veinte minutos a que se desocupe el consultorio de al
 * lado, y la clinica pierde al cliente. Con el bloque separado, la agenda de
 * vacunas es rapida y masiva, y se puede abrir un turno de vacunas un domingo
 * sin tocar la agenda de consultas.
 *
 * `duracion_minutos` es lo que la agenda usa para calcular el `fin` de la
 * cita. No lo decide quien agenda: lo declara el tipo de atencion, para que
 * dos personas que agenden la misma cosa ocupen siempre el mismo rato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_atencion', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 80);
            $tabla->string('descripcion', 240)->nullable();
            $tabla->unsignedSmallInteger('duracion_minutos');

            // El bloque de vacunacion: rapido, masivo y aparte.
            $tabla->boolean('bloque_vacunacion')->default(false);

            $tabla->integer('precio')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->unique(['tenant_id', 'nombre']);
            $tabla->index(['tenant_id', 'activo', 'bloque_vacunacion']);

            // Una atencion no puede durar cero ni un dia entero. Es lo que
            // impide que alguien con un error de tipeo guarde 0 y que la
            // agenda nunca termine de calcular el `fin`.
            DB::statement(
                'ALTER TABLE tipos_atencion ADD CONSTRAINT tipos_atencion_duracion_check '
                . 'CHECK (duracion_minutos > 0 AND duracion_minutos <= 480)'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_atencion');
    }
};
