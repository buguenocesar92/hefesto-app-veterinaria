<?php

declare(strict_types=1);

use App\Enums\Especie;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `esquemas_vacunacion`: reglas que configura la clinica.
 *
 * ===================================================================
 * LOS INTERVALOS DE ESTA TABLA NO SON UNA VERDAD DEL SISTEMA
 * ===================================================================
 *
 * Aqui NO hay calendarios oficiales escritos en el codigo, y esa es una
 * decision deliberada. Un esquema de vacunacion depende de:
 *
 *  1. La ESPECIE (por eso `especie` es parte de la clave).
 *  2. La EDAD a la que se puede aplicar cada dosis.
 *  3. El FABRICANTE del producto, que puede cambiar el calendario.
 *  4. La NORMATIVA SANITARIA de la jurisdiction, que cambia con el tiempo.
 *
 * **Este proyecto no verifico ninguna de esas cuatro cosas.** No se consulto
 * ninguna fuente oficial. Un intervalo que este ahi es lo que la clinica dijo
 * que es, no lo que la ciencia o la norma dicen.
 *
 * POR QUE NO VENEN EN EL CODIGO
 * -----------------------------
 * Porque si vinieran, cada instalacion arrancaria con un calendario que
 * nadie en esa clinica reviso, y la primera decision clinica del sistema
 * seria una que Hefesto tomo sin permiso.
 *
 * `verificado` Y `fuente`
 * -----------------------
 * Guardan si la clinica confirmo la regla y de donde la saco. La semilla trae
 * sus filas con `verificado = false` y la fuente escrita, justamente para que
 * no se confundan con algo comprobado. Ver docs/02 y docs/07.
 *
 * LA UNICIDAD
 * -----------
 * `unique(['tenant_id', 'especie', 'nombre_vacuna', 'dosis_numero'])` impide
 * cargar dos veces la misma dosis. Un esquema duplicado hace que la proxima
 * dosis se proponga dos veces, que es como aparecen los errores de agenda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esquemas_vacunacion', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            // Parte de la clave: el esquema de un perro no es el de un gato.
            $tabla->string('especie', 20);

            $tabla->string('nombre_vacuna', 120);

            // Que dosis de la serie es esta (1, 2, 3...). Es parte de la
            // clave porque "la segunda dosis" y "la tercera" son reglas
            // distintas, con intervalos distintos.
            $tabla->unsignedSmallInteger('dosis_numero');

            // A partir de que edad se puede aplicar.
            $tabla->unsignedSmallInteger('edad_minima_meses');

            // Separacion respecto de la dosis anterior.
            $tabla->unsignedSmallInteger('intervalo_meses');

            // Si esta dosis es un refuerzo posterior a la serie. Si viene, MANDA
            // sobre `intervalo_meses`: es el caso de la dosis anual.
            $tabla->unsignedSmallInteger('refuerzo_meses')->nullable();

            // De donde salio la regla y si alguien la confirmo.
            $tabla->string('fuente', 240)->nullable();
            $tabla->boolean('verificado')->default(false);

            $tabla->text('notas')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();

            $tabla->unique(['tenant_id', 'especie', 'nombre_vacuna', 'dosis_numero']);
            $tabla->index(['tenant_id', 'especie', 'activo']);
        });

        $especies = array_map(
            static fn (Especie $especie): string => "'" . $especie->value . "'",
            Especie::cases(),
        );

        DB::statement(
            'ALTER TABLE esquemas_vacunacion ADD CONSTRAINT esquemas_especie_check CHECK (especie IN (' . implode(', ', $especies) . '))'
        );

        // La regla tiene que ser utilizable: una dosis sin edad no se puede
        // sugerir, y una sin separacion no tiene proxima fecha.
        DB::statement(<<<'SQL'
            ALTER TABLE esquemas_vacunacion
            ADD CONSTRAINT esquemas_regla_utilizable_check CHECK (
                edad_minima_meses > 0
                AND (intervalo_meses > 0 OR refuerzo_meses > 0)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('esquemas_vacunacion');
    }
};
