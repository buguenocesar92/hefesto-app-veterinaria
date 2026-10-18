<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `profesionales`: los veterinarios de la clinica.
 *
 * Es distinta de `usuarios`: el profesional es la persona con registro
 * profesional y la que aparece en la agenda; el usuario es su acceso al
 * sistema. Una misma persona puede tener mas de una cuenta a lo largo del
 * tiempo, y puede tener perfil profesional sin tener cuenta abierta.
 *
 * `registro_profesional` es texto, NO un dato verificado contra ninguna
 * fuente oficial. El sistema no consulta ningun registro. Ver docs/10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profesionales', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 120);
            $tabla->string('rut', 20)->nullable();
            $tabla->string('registro_profesional', 40)->nullable();
            $tabla->string('especialidad', 80)->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();

            // El RUT es unico DENTRO de la clinica. Dos clinicas pueden
            // atender al mismo profesional.
            $tabla->unique(['tenant_id', 'rut']);
            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profesionales');
    }
};
