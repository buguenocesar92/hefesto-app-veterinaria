<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sesiones`: la sesion de Laravel, en la base.
 *
 * POR QUE EN LA BASE Y NO EN REDIS
 * -------------------------------
 * Una sesion que autoriza el acceso a una ficha clinica tiene que sobrevivir
 * al reinicio de la API sin perder a quien esta atendiendo en ese momento.
 * Redis es la cache: si se pierde, se pierde. En la base, no.
 *
 * Redis se usa para lo que es cache y para las colas (ver config/database.php),
 * no para el almacen de sesiones.
 *
 * `tenant_id` Y RLS EN SESIONES, UN CASO RARO
 * -------------------------------------------
 * Esta tabla SI lleva `tenant_id` y SI lleva politica, a diferencia de
 * `clinicas`. Y eso obliga a resolver un problema que no aparece en las
 * demas tablas: **el guard de sesion lee la sesion ANTES de que exista el
 * contexto de clinica**.
 *
 * El orden real de una peticion es:
 *
 *   1. El navegador manda la cookie.
 *   2. Laravel lee la sesion de la base para reconstruir el usuario.
 *   3. SOLO AHORA se sabe de que clinica es, y se fija el contexto.
 *
 * En el paso 2 todavia no hay `app.clinica_id` que poner, y con la politica
 * normal la lectura de `sesiones` devolveria cero filas: la sesion nunca se
 * reconstruiria y nadie podria entrar.
 *
 * La solucion NO es aflojar la politica. Es leer la sesion por una conexion
 * sin RLS, y solo por identificador de sesion, que es un valor aleatorio que
 * genera el servidor. Se implementa en App\Services\Auth\AlmacenDeSesion, y
 * se deja anotado como el punto mas delicado del sistema, con su prueba en
 * `tests/Feature/Aislamiento/SesionAntesDelContextoTest.php`.
 *
 * ALTERNATIVA DESCARTADA
 * ----------------------
 * Poner el tenant en un claim de la cookie de sesion, firmado, para fijar el
 * contexto antes de tocar la base. Es lo haria una aplicacion sin el problema
 * de la tabla de sesiones. No se eligio porque obliga a mantener el tenant en
 * dos lugares (cookie y fila) y a resolver que pasa cuando no coinciden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones', function (Blueprint $tabla): void {
            $tabla->string('id')->primary();

            // Se llena al fijar el contexto, no al crear la sesion: en el
            // momento de crearla todavia no se sabe de que clinica es.
            $tabla->uuid('tenant_id')->nullable();

            $tabla->uuid('usuario_id')->nullable();
            $tabla->text('payload');
            $tabla->timestampTz('ultimo_uso')->nullable();
            $tabla->timestampTz('caduca')->nullable();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('usuario_id')->references('id')->on('usuarios')->cascadeOnDelete();

            $tabla->index(['tenant_id']);
            $tabla->index(['usuario_id']);
            $tabla->index('caduca');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones');
    }
};
