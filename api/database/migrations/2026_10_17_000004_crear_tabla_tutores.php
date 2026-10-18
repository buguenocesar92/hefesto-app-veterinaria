<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tutores`: la persona que decide.
 *
 * DATOS PERSONALES DE UNA PERSONA REAL
 * ------------------------------------
 * Esta tabla es la que protege la Ley 19.628, porque el tutor es la persona
 * natural. El animal no es sujeto de la ley.
 *
 * Y hay una consideracion que va mas alla de lo legal: **la direccion y el
 * telefono de un tutor identifican a una familia**, y cuando se los cruza con
 * el historial de una mascota, se sabe que en esa casa hay un animal con una
 * enfermedad cronica. Por eso `direccion` no aparece en la agenda de la
 * pantalla de llamada de la recepcion, y por eso el acceso a la ficha de la
 * mascota es un rol aparte del que ve esta tabla. Ver docs/07.
 *
 * LO QUE NO ESTA ACA
 * ------------------
 * Ningun dato clinico. Ni diagnostico, ni evolucion, ni receta. Eso cuelga de
 * `mascotas`, y una mascota puede tener varias, todas del mismo tutor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutores', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('rut', 20);
            $tabla->string('nombres', 80);
            $tabla->string('apellidos', 80);
            $tabla->string('telefono', 30)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->string('direccion', 180)->nullable();
            $tabla->string('comuna', 80)->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();

            // Unico DENTRO de la clinica. Dos clinicas pueden tener al mismo
            // tutor, y son dos personas distintas en el sistema.
            $tabla->unique(['tenant_id', 'rut']);
            $tabla->index(['tenant_id', 'apellidos']);
            $tabla->index(['tenant_id', 'activo']);
            $tabla->index(['tenant_id', 'telefono']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutores');
    }
};
