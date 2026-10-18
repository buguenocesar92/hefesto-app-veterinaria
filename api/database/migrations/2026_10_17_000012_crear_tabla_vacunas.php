<?php

declare(strict_types=1);

use App\Enums\EstadoVacuna;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `vacunas`: aplicaciones. HECHOS, no calculos.
 *
 * LA COLUMNA MAS IMPORTANTE DE ESTA TABLA ES `proxima_dose_en`
 * ------------------------------------------------------------
 * Es una fecha GUARDADA. Se calcula una vez, el dia que se registra la
 * aplicacion, leyendo el esquema que habia en ese momento. Despues no se
 * vuelve a tocar, ni aunque se edite el esquema.
 *
 * Por que, y por que no se recalcula:
 *
 *  - Recalcular contra el esquema actual moveria hacia atras las fechas de
 *    una dosis que ya se aplico, y dejaria desmentido un certificado ya
 *    impreso.
 *  - Recalcular contra "hoy" haria que el resultado cambie solo con que pase
 *    el tiempo, y el dia que mas importa (control, aviso de atraso) el
 *    sistema podria decir una cosa hoy y otra manana.
 *
 * Lo que SI se deriva en cada lectura es si esa fecha ya paso: eso vive en
 * `App\Services\Vacunas\CalculadorDeVigencia` y depende de la fecha de hoy.
 * La separacion es:
 *
 *   GUARDADO: cuando cae la proxima dosis (un hecho ya decidido).
 *   DERIVADO: si esa fecha ya paso (cambia con cada dia).
 *
 * Es el mismo criterio que el control de vencimientos de la flota de
 * vehiculos de Hefesto. Ver docs/02.
 *
 * POR QUE `estado` SOLO TIENE DOS VALORES
 * --------------------------------------
 * "Vigente", "por vencer" y "atrasada" NO se guardan. Son consecuencias del
 * calendario. Si se guardaran, quedarian viejos solos: nadie los actualiza.
 * Ver App\Enums\EstadoVacuna.
 *
 * `esquema_id` es nullable a proposito: puede haberse aplicado una vacuna que
 * la clinica todavia no tiene cargada como esquema, y el hecho ocurrio igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacunas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('mascota_id');
            $tabla->uuid('esquema_id')->nullable();
            $tabla->uuid('profesional_id');
            $tabla->uuid('cita_id')->nullable();

            // Fecha, no marca de tiempo: un certificado tiene dia, y el dia es
            // lo que la gente recuerda y lo que se compara entre animales.
            $tabla->date('fecha_aplicacion');

            // EL VENCIMIENTO GUARDADO. Ver la nota de arriba.
            $tabla->date('proxima_dose_en')->nullable();

            // Trazabilidad del producto aplicado. Sin esto no se puede
            // responder que paso si una vacuna resulta tener un problema.
            $tabla->string('lote', 60)->nullable();
            $tabla->string('laboratorio', 120)->nullable();

            $tabla->string('estado', 16)->default('aplicada');
            $tabla->text('observaciones')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('mascota_id')->references('id')->on('mascotas')->cascadeOnDelete();
            $tabla->foreign('esquema_id')->references('id')->on('esquemas_vacunacion')->nullOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales');
            $tabla->foreign('cita_id')->references('id')->on('citas')->nullOnDelete();

            // El tablero de atraso se apoya en estas dos columnas.
            $tabla->index(['tenant_id', 'mascota_id', 'fecha_aplicacion']);
            $tabla->index(['tenant_id', 'proxima_dose_en']);

            /*
             * UNA MASCOTA, UNA APLICACION POR ESQUEMA Y FECHA.
             *
             * Sin esta unicidad, un doble clic en "registrar vacuna" carga
             * dos veces la misma dosis y el historial de la mascota muestra
             * dos inyecciones que nunca ocurrieron.
             *
             * LIMITACION CONOCIDA, DICHA EN VOZ ALTA: cuando `esquema_id` es
             * NULL, este indice NO protege, porque en PostgreSQL los NULL se
             * consideran distintos entre si. Es decir, dos aplicaciones de
             * una MISMA vacuna sin esquema cargado y en la misma fecha SI
             * podrían quedar duplicadas.
             *
             * Se acepta a proposito: es preferible el aviso del servicio de
             * registro (que detecta la repetida) a un indice que exija
             * inventar un esquema para poder guardar el hecho. Ver
             * App\Services\Vacunas\RegistradorDeVacuna.
             */
            $tabla->unique(
                ['mascota_id', 'esquema_id', 'fecha_aplicacion'],
                'vacunas_aplicacion_unica'
            );
        });

        $estados = array_map(
            static fn (EstadoVacuna $estado): string => "'" . $estado->value . "'",
            EstadoVacuna::cases(),
        );

        DB::statement(
            'ALTER TABLE vacunas ADD CONSTRAINT vacunas_estado_check CHECK (estado IN (' . implode(', ', $estados) . '))'
        );

        // La proxima dosis no puede ser anterior a la aplicacion. Si lo fuera,
        // es un error de calculo, y mejor que la base lo diga al guardar.
        DB::statement(
            'ALTER TABLE vacunas ADD CONSTRAINT vacunas_proxima_posterior '
            . 'CHECK (proxima_dose_en IS NULL OR proxima_dose_en >= fecha_aplicacion)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vacunas');
    }
};
