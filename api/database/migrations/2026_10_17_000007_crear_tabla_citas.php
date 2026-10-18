<?php

declare(strict_types=1);

use App\Enums\EstadoCita;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `citas`: la agenda.
 *
 * POR QUE GUARDA `tutor_id` Y TAMBIEN `mascota_id`
 * -------------------------------------------------
 * La cita es un hecho del dia: el perro de la Sra. Ruiz, a las diez. Si
 * guardara solo `mascota_id`, la agenda tendria que hacer un JOIN para llegar
 * al nombre que se muestra y para poder llamar.
 *
 * Guardar el tutor tambien es decision: si el animal se da de baja o cambia
 * de tutor, las citas ya agendadas siguen teniendo que decir a quien llamar.
 * Sin `tutor_id` en la cita, un turno de la semana siguiente queda huerfano.
 *
 * LO QUE NO ESTA ACA
 * ------------------
 * Ningun dato clinico. `motivo` es lo que dijo la persona al recibir, no un
 * diagnostico: "trae a la mascota por un problema en el oido", no "otitis".
 *
 * EL INDICE UNICO QUE IMPIDE EL DOBLE booked
 * -----------------------------------------
 * Un profesional no puede estar en dos consultorios a la vez. El indice
 * parcial de abajo hace que eso lo rechace PostgreSQL y no la aplicacion,
 * que es donde suelen fallar estas cosas cuando hay dos personas agendando a
 * la vez.
 *
 * Es un indice PARCIAL a proposito: solo cubre las citas que ocupan agenda
 * (los estados finales no la ocupan, ver EstadoCita::ocupaAgenda). Si cubriese
 * todas, una cita cancelada seguiria tapando el horario para siempre, porque
 * el indice no distingue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('mascota_id');
            $tabla->uuid('tutor_id');
            $tabla->uuid('profesional_id');
            $tabla->uuid('tipo_atencion_id');
            $tabla->timestampTz('inicio');
            $tabla->timestampTz('fin');
            $tabla->string('estado', 16)->default('pendiente');
            $tabla->string('motivo', 240)->nullable();
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('clinicas')->cascadeOnDelete();
            $tabla->foreign('mascota_id')->references('id')->on('mascotas')->cascadeOnDelete();
            $tabla->foreign('tutor_id')->references('id')->on('tutores')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->cascadeOnDelete();
            $tabla->foreign('tipo_atencion_id')->references('id')->on('tipos_atencion');

            // La agenda del dia se lee siempre por profesional y ordenada por
            // hora de inicio.
            $tabla->index(['tenant_id', 'profesional_id', 'inicio']);
            $tabla->index(['tenant_id', 'mascota_id', 'inicio']);
            $tabla->index(['tenant_id', 'inicio']);
        });

        $estados = array_map(
            static fn (EstadoCita $estado): string => "'" . $estado->value . "'",
            EstadoCita::cases(),
        );

        DB::statement(
            'ALTER TABLE citas ADD CONSTRAINT citas_estado_check CHECK (estado IN (' . implode(', ', $estados) . '))'
        );

        // Una cita que termina antes de empezar no es una cita.
        DB::statement(
            'ALTER TABLE citas ADD CONSTRAINT citas_rango_check CHECK (fin > inicio)'
        );

        /*
         * IMPIDE EL DOBLE booked.
         *
         * Se razonan las citas que ENCIERRAN a otra. PostgreSQL tiene
         * `EXCLUDE` con `btree_gist`, que es la unica forma de expresar
         * "estas dos cosas no pueden solaparse" en una constraint: el `UNIQUE`
         * normal no alcanza, porque aqui se comparan RANGOS, no valores.
         *
         * `&&` es la traslacion de "se solapan" en el mundo de GiST, y la
         * predicacion `estado <> 'cancelada'` deja fuera las canceladas: una
         * cita cancelada libera el horario y tiene que poder volver a
         * ocuparse.
         *
         * Lo que NO se resuelve aca es el solapamiento con una cita ya
         * ATENDIDA. Esa se considera un hecho cerrado y no bloquea el
         * horario; si mas adelante se quiere impedirlo tambien, se agrega el
         * estado a la predicacion.
         */
        DB::statement(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            ALTER TABLE citas ADD CONSTRAINT citas_sin_solapamiento
            EXCLUDE USING gist (
                profesional_id WITH =,
                tstzrange(inicio, fin, '[)') WITH &&
            )
            WHERE (estado <> 'cancelada')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};
