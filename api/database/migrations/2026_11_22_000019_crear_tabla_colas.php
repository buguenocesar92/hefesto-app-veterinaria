<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `jobs` y `failed_jobs`: las tablas de la cola de Laravel.
 *
 * POR QUE FALTABAN
 * ----------------
 * Laravel usa la conexion `database` para las colas cuando no se dice otra
 * cosa, y `api/.env.example` dice `redis`: con redis, la cola vive en redis
 * y no hace falta ninguna tabla. Pero el nombre de la conexion se puede
 * cambiar, y cuando falta la variable de entorno Laravel vuelve a `database`.
 * En ese caso el worker se levanta y falla cada tres segundos con
 * `relation "jobs" does not exist`: no procesa nada y llena el registro de
 * trazas.
 *
 * Son las tablas estandar de Laravel (`php artisan queue:table` y
 * `php artisan queue:failed-table`), sin tocar.
 *
 * POR QUE NO LLEVAN RLS
 * ---------------------
 * La cola es GLOBAL: un trabajo no pertenece a una clinica, lo encola la
 * aplicacion y lo recoge el worker, que todavia no sabe de que clinica es.
 * Con la politica de `tenant_id` de las demas tablas, el worker no veria
 * NINGUN trabajo (la lectura de `jobs` ocurre fuera del contexto de clinica)
 * y la cola no funcionaria.
 *
 * Es el mismo problema que `clinicas`, y por el mismo motivo: la tabla de la
 * cola no se consulta desde una peticion de usuario.
 *
 * LO QUE HAY QUE TENER EN CUENTA
 * -------------------------------
 * El contenido de `jobs` es el del trabajo que se esta ejecutando. Si alguna
 * vez se encolan trabajos con datos de salud, hay que acordarse de que esta
 * tabla la puede leer cualquiera con permiso sobre ella: el contexto de
 * clinica se fija DENTRO del trabajo, no antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $tabla): void {
            $tabla->bigIncrements('id');

            $tabla->string('queue')->index();
            $tabla->longText('payload');
            $tabla->unsignedTinyInteger('attempts');
            $tabla->unsignedInteger('reserved_at')->nullable();
            $tabla->unsignedInteger('available_at');
            $tabla->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->string('uuid')->unique();
            $tabla->text('connection');
            $tabla->text('queue');
            $tabla->longText('payload');
            $tabla->longText('exception');
            $tabla->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
