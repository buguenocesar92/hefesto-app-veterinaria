<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QUE LAS TABLAS CON AISLAMIMIENTO SEAN LAS QUE DICE LA LISTA.
 *
 * LA PRUEBA DE QUE LA LISTA NO SE QUEDE CORTA
 * -------------------------------------------
 * El fallo tipico de un sistema con RLS no es que la politica este mal: es
 * que alguien agrego una tabla nueva y se olvido de la lista. La tabla queda
 * sin politica y **el sistema sigue funcionando con normalidad**: no hay
 * error, no hay aviso, solo datos de una clinica que aparecen en otra.
 *
 * Esta prueba recorre el catalogo del sistema y falla si encuentra una tabla
 * que tenga columna `tenant_id`, este en la lista de la migracion, y no este
 * protegida.
 *
 * Es la red que agarra el error que nadie va a ver.
 */
class SinTablasSinAislamientoTest extends TestCase
{
    /**
     * Tablas que tienen `tenant_id` y NO llevan RLS a proposito.
     *
     * Cada una necesita una justificacion escrita, no una excepcion generica.
     *
     * @var array<string, string>
     */
    private const EXENTAS = [
        // Se consulta durante el ingreso, sin contexto. Se compensa con la
        // vista publica de tres columnas y con un rol sin BYPASSRLS.
        'clinicas' => 'Es el tenant. Se lee en el ingreso; ver docs/03.',
    ];

    public function test_ninguna_tabla_con_tenant_id_queda_sin_aislamiento(): void
    {
        // Las tablas que registre la migracion de este entorno mas las que
        // declaran las migraciones, para no depender de que la base este
        // sembrada.
        $tablas = DB::select(<<<'SQL'
            SELECT c.relname AS nombre
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind = 'r'
              AND n.nspname = 'public'
              AND EXISTS (
                  SELECT 1
                  FROM pg_attribute a
                  WHERE a.attrelid = c.oid
                    AND a.attname = 'tenant_id'
                    AND a.attnum > 0
                    AND NOT a.attisdropped
              )
            ORDER BY c.relname
        SQL);

        $this->assertNotEmpty($tablas, 'No se encontro ninguna tabla con tenant_id.');

        foreach ($tablas as $fila) {
            $tabla = (string) $fila->nombre;

            if (array_key_exists($tabla, self::EXENTAS)) {
                continue;
            }

            $estado = DB::selectOne(
                'SELECT relrowsecurity AS rls, relforcerowsecurity AS forzado
                 FROM pg_class WHERE relname = ?',
                [$tabla],
            );

            $this->assertNotNull($estado, "No se encontro el catalogo de la tabla {$tabla}.");

            $this->assertTrue(
                (bool) $estado->rls,
                "La tabla {$tabla} tiene tenant_id pero NO tiene Row Level Security. "
                . 'Quedaria sin aislamiento y nada lo avisaria.'
            );

            $this->assertTrue(
                (bool) $estado->forzado,
                "La tabla {$tabla} tiene RLS pero NO FORCE. La politica no aplicaria al dueno."
            );
        }
    }
}
