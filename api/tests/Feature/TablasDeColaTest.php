<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Las tablas de la cola de Laravel existen despues de migrar.
 *
 * `jobs` la necesita el worker cuando QUEUE_CONNECTION=database (el valor por
 * defecto de Laravel). `failed_jobs` la necesita SIEMPRE: Laravel registra ahi
 * los trabajos fallidos tambien con QUEUE_CONNECTION=redis.
 *
 * Ninguna de las dos lleva tenant_id: la cola es global (ver docs/03).
 */
class TablasDeColaTest extends TestCase
{
    public function test_existe_la_tabla_jobs_con_las_columnas_de_laravel(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'), 'Falta la tabla jobs.');
        $this->assertTrue(
            Schema::hasColumns('jobs', ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']),
            'La tabla jobs no tiene las columnas que usa Illuminate\Queue\DatabaseQueue.'
        );
        $this->assertFalse(Schema::hasColumn('jobs', 'tenant_id'), 'La cola es global: jobs no lleva tenant_id.');
    }

    public function test_existe_la_tabla_failed_jobs_con_las_columnas_de_laravel(): void
    {
        $this->assertTrue(Schema::hasTable('failed_jobs'), 'Falta la tabla failed_jobs.');
        $this->assertTrue(
            Schema::hasColumns('failed_jobs', ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']),
            'La tabla failed_jobs no tiene las columnas que usa el proveedor database-uuids.'
        );
        $this->assertFalse(Schema::hasColumn('failed_jobs', 'tenant_id'), 'La cola es global: failed_jobs no lleva tenant_id.');
    }
}
