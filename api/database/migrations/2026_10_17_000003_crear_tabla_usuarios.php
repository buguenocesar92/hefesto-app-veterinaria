<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `usuarios`: quien entra al sistema.
 *
 * NO HAY CONTRASENAS
 * ------------------
 * La identidad la emite Authentik por OpenID Connect. La columna `password`
 * existe solo porque el proveedor por defecto de Laravel la espera, se deja
 * como cadena vacia y nunca se escribe. Ver docs/07.
 *
 * `rol` se vuelve a leer de Authentik en cada ingreso: si a alguien le
 * quitan un equipo ahi, en esta aplicacion pierde el acceso en la sesion
 * siguiente, sin esperar a que caduque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 120);
            $tabla->string('correo', 160);

            // Sin uso: la identidad es externa.
            $tabla->string('password')->default('');

            $tabla->string('rol', 20);
            $tabla->uuid('profesional_id')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampTz('ultimo_ingreso_en')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->nullOnDelete();

            // El correo es unico DENTRO de la clinica: la misma persona puede
            // trabajar en dos clinicas con el mismo correo, y son dos cuentas
            // distintas.
            $tabla->unique(['tenant_id', 'correo']);
            $tabla->index(['tenant_id', 'rol']);
            $tabla->index(['tenant_id', 'activo']);
        });

        $roles = array_map(
            static fn (App\Enums\RolClinico $rol): string => "'" . $rol->value . "'",
            App\Enums\RolClinico::cases(),
        );

        DB::statement(
            'ALTER TABLE usuarios ADD CONSTRAINT usuarios_rol_check CHECK (rol IN (' . implode(', ', $roles) . '))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
