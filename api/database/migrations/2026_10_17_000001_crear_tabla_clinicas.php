<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `clinicas`: el TENANT.
 *
 * Es la UNICA tabla sin `tenant_id`, y eso es a proposito: es la tabla que
 * dice de quien son las demas filas.
 *
 * NO LLEVA ROW LEVEL SECURITY
 * --------------------------
 * Se consulta durante el ingreso, cuando todavia no hay sesion y por lo tanto
 * no hay `app.clinica_id` que poner en la conexion. Si llevara RLS con la
 * politica estandar, la propia funcion de contexto entraria en recursion.
 *
 * Es una excepcion consciente con dos compensaciones, descritas en
 * docs/03: una vista publica con tres columnas, y un rol de aplicacion que
 * no es superusuario ni tiene BYPASSRLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinicas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->string('slug', 60)->unique();
            $tabla->string('nombre', 120);

            // Zona horaria por clinica: una clinica de Punta Arenas agenda en
            // horario local, no en el de Santiago.
            $tabla->string('zona_horaria', 64)->default('America/Santiago');

            // Con que equipo de Authentik se declara esta clinica. Se resuelve
            // al ingresar. Ver App\Services\Auth\ResolutorDeSesion.
            $tabla->string('correlacion_identidad', 80)->unique();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinicas');
    }
};
