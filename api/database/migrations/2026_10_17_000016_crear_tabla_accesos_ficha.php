<?php

declare(strict_types=1);

use App\Enums\AccionFicha;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `accesos_ficha`: bitacora de quien abrio que ficha.
 *
 * POR QUE SE REGISTRAN LOS INTENTOS DENEGADOS
 * --------------------------------------------
 * Un acceso denegado es informacion: puede ser un profesional trabajando con
 * permisos de otra clinica, un error de configuracion, o alguien probando.
 * En una aplicacion con datos de salud, ese es el tipo de evento que una
 * clinica necesita poder revisar despues.
 *
 * Y hay una razon tecnica: si se anotara solo lo permitido, no habria forma de
 * distinguir "nadie intento esto" de "se registro y se perdio".
 *
 * INMUTABLE
 * ---------
 * El modelo (App\Models\AccesoFicha) lanza excepcion si alguien intenta
 * modificar o borrar una fila, y esta tabla se escribe por una conexion
 * aparte (ver App\Services\Ficha\BitacoraFichas), porque la peticion que
 * deniega el acceso suele terminar revertida y el registro tiene que
 * sobrevivir a esa reversa.
 *
 * `direccion_ip` y `user_agent`: datos de la persona que entro, no de la
 * mascota. Se guardan porque son parte de la trazabilidad, y porque estan
 * sujetos a la misma Ley 19.628 que el resto. Ver docs/07.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos_ficha', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('usuario_id');
            $tabla->uuid('mascota_id')->nullable();

            $tabla->string('accion', 16);

            // Por que se denego. Es lo que hace util la bitacora: "no tiene
            // permiso" no dice si fue un error de configuracion o un intento.
            $tabla->string('motivo_denial', 240)->nullable();

            // De donde venia la peticion.
            $tabla->string('contexto', 120)->nullable();
            $tabla->string('direccion_ip', 45)->nullable();
            $tabla->string('user_agent', 240)->nullable();

            // Solo `created_at`: un registro de bitacora no se actualiza.
            $tabla->timestampTz('created_at')->useCurrent();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('usuario_id')->references('id')->on('usuarios')->cascadeOnDelete();
            $tabla->foreign('mascota_id')->references('id')->on('mascotas')->cascadeOnDelete();

            // La consulta tipica es "quien abrio la ficha de esta mascota",
            // y "que accesos denegados hubo ayer".
            $tabla->index(['tenant_id', 'mascota_id', 'created_at']);
            $tabla->index(['tenant_id', 'accion', 'created_at']);
            $tabla->index(['tenant_id', 'usuario_id', 'created_at']);
        });

        $acciones = array_map(
            static fn (AccionFicha $accion): string => "'" . $accion->value . "'",
            AccionFicha::cases(),
        );

        DB::statement(
            'ALTER TABLE accesos_ficha ADD CONSTRAINT accesos_ficha_accion_check CHECK (accion IN (' . implode(', ', $acciones) . '))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos_ficha');
    }
};
