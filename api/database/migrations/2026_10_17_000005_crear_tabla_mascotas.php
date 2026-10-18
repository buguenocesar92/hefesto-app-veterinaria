<?php

declare(strict_types=1);

use App\Enums\Especie;
use App\Enums\Sexo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `mascotas`: el paciente. De aqui cuelga TODA la ficha clinica.
 *
 * LA REGLA ESTRUCTURAL DE ESTE MODELO
 * ----------------------------------
 * `consultas`, `controles`, `prescripciones` y `vacunas` tienen
 * `mascota_id` y NO tienen `tutor_id`. Un tutor puede tener varias mascotas y
 * **sus historiales no se mezclan nunca**.
 *
 * Escribirlo de otra forma (consultas por tutor, o consultas con ambos
 * campos) es el error grave de este dominio: la radiografia de un perro
 * aparece pegada al diagnostico de un gato de la misma casa, y la
 * prescripcion se calcula sobre el peso equivocado. Hay una prueba dedicada
 * a esto: `HistorialNoSeMezclaEntreMascotasDelMismoTutorTest`.
 *
 * UNA MASCOTA, UN TUTOR
 * ---------------------
 * `tutor_id` no es una tabla puente: una mascota tiene un solo tutor
 * responsable en este sistema. Si una familia se separa o se traspasa la
 * tenencia, lo que se hace es reasignar `tutor_id` y queda la trazabilidad en
 * la bitacora, no una historia compartida de acceso.
 *
 * MICROCHIP
 * ---------
 * El microchip identifica al animal y no a la persona. Es unico por clinica
 * (no globalmente: el mismo chip se puede haber leido en dos clinicas sin
 * que nadie lo haya dado de alta), y se puede dejar en blanco: muchos animales
 * domésticos no tienen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mascotas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('tutor_id');
            $tabla->string('nombre', 80);
            $tabla->string('especie', 20);
            $tabla->string('raza', 80)->nullable();
            $tabla->string('sexo', 16)->nullable();
            $tabla->date('fecha_nacimiento')->nullable();
            $tabla->decimal('peso_kg', 6, 2)->nullable();
            $tabla->decimal('altura_cm', 6, 1)->nullable();
            $tabla->string('color', 60)->nullable();
            $tabla->string('microchip', 40)->nullable();
            $tabla->boolean('esterilizado')->default(false);
            $tabla->boolean('activo')->default(true);
            $tabla->date('fecha_fallecimiento')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('tutor_id')->references('id')->on('tutores')->cascadeOnDelete();

            // Indice clave del modulo clinico: la ficha siempre se busca por
            // mascota, nunca por tutor.
            $tabla->index(['tenant_id', 'tutor_id']);
            $tabla->index(['tenant_id', 'nombre']);
            $tabla->index(['tenant_id', 'especie']);

            // El microchip es unico por clinica, pero NULLS NOT DISTINCT no
            // existe en todas las versiones soportadas, asi que el indice
            // es normal y la unicidad real se comprueba en la capa de
            // aplicacion. Ver MascotaController.
            $tabla->index(['tenant_id', 'microchip']);
        });

        $especies = array_map(
            static fn (Especie $especie): string => "'" . $especie->value . "'",
            Especie::cases(),
        );

        DB::statement(
            'ALTER TABLE mascotas ADD CONSTRAINT mascotas_especie_check CHECK (especie IN (' . implode(', ', $especies) . '))'
        );

        $sexos = array_map(
            static fn (Sexo $sexo): string => "'" . $sexo->value . "'",
            Sexo::cases(),
        );

        DB::statement(
            'ALTER TABLE mascotas ADD CONSTRAINT mascotas_sexo_check CHECK (sexo IS NULL OR sexo IN (' . implode(', ', $sexos) . '))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mascotas');
    }
};
