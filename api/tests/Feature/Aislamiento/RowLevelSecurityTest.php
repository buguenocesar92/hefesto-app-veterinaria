<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Clinica;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QUE LA ESTRUCTURA DE AISLAMIENTO ESTA PUESTA.
 *
 * QUE COMPRUEBA
 * -------------
 * Que las tablas de negocio tienen Row Level Security ACTIVADA y FORZADA, que
 * cada una tiene la politica de aislamiento, y que la funcion de contexto
 * falla cerrada.
 *
 * POR QUE COMPROBAR `relforcerowsecurity`
 * --------------------------------------
 * Porque sin `FORCE`, la politica NO aplica al dueno de la tabla, y Laravel se
 * conecta como el dueno. En ese caso `relrowsecurity` estaria en `true` y la
 * instalacion pareceria estar protegida, cuando en realidad la politica se
 * esta saltando justo en el entorno donde se prueba.
 *
 * Es el fallo que mas se repite en instalaciones de RLS, y por eso tiene
 * prueba propia.
 *
 * QUE NO COMPRUEBA
 * ----------------
 * Que el rol conectado no tenga BYPASSRLS ni sea superusuario. Eso se
 * comprueba en el servidor con `clinica:verificar-aislamiento`, que ademas
 * revisa el rol real. Ver docs/03.
 */
class RowLevelSecurityTest extends TestCase
{
    /**
     * Las tablas de negocio. Tiene que coincidir con la lista de la migracion
     * `2026_10_17_000018_activar_row_level_security.php`.
     *
     * Si se agrega una tabla y no se agrega aqui, esta prueba no la cubre.
     * Por eso la lista es explicita y no se lee de la migracion.
     *
     * @var list<string>
     */
    private const TABLAS = [
        'profesionales',
        'usuarios',
        'tutores',
        'mascotas',
        'tipos_atencion',
        'citas',
        'consultas',
        'controles',
        'prescripciones',
        'esquemas_vacunacion',
        'vacunas',
        'productos',
        'ventas',
        'venta_items',
        'accesos_ficha',
        'sesiones',
    ];

    public function test_todas_las_tablas_de_negocio_tienen_rls_activado_y_forzado(): void
    {
        foreach (self::TABLAS as $tabla) {
            $fila = DB::selectOne(
                'SELECT relrowsecurity AS rls, relforcerowsecurity AS forzado
                 FROM pg_class WHERE relname = ?',
                [$tabla],
            );

            $this->assertNotNull($fila, "La tabla {$tabla} no existe.");

            $this->assertTrue(
                (bool) $fila->rls,
                "La tabla {$tabla} no tiene Row Level Security activado."
            );

            $this->assertTrue(
                (bool) $fila->forzado,
                "La tabla {$tabla} no tiene FORCE ROW LEVEL SECURITY. Sin eso la politica "
                . 'no aplica al dueno, que es el rol con el que se conecta la aplicacion.'
            );
        }
    }

    public function test_cada_tabla_protegida_tiene_la_politica_de_aislamiento(): void
    {
        foreach (self::TABLAS as $tabla) {
            $conteo = DB::selectOne(
                'SELECT count(*) AS total
                 FROM pg_policies
                 WHERE schemaname = \'public\'
                   AND tablename = ?
                   AND policyname = \'aislamiento_tenant\'',
                [$tabla],
            );

            $this->assertSame(
                1,
                (int) $conteo->total,
                "La tabla {$tabla} no tiene la politica aislamiento_tenant."
            );
        }
    }

    public function test_la_politica_tiene_using_y_with_check(): void
    {
        $conteo = DB::selectOne(
            "SELECT count(*) AS total
             FROM pg_policies
             WHERE schemaname = 'public'
               AND policyname = 'aislamiento_tenant'
               AND qual IS NOT NULL
               AND with_check IS NOT NULL",
        );

        $this->assertGreaterThan(
            0,
            (int) $conteo->total,
            'Ninguna politica tiene WITH CHECK. Sin el, se podrian escribir filas de otro tenant.',
        );
    }

    public function test_la_funcion_de_contexto_falla_sin_variable(): void
    {
        $lanzo = false;

        try {
            DB::selectOne('SELECT public.app_clinica_id()');
        } catch (\Throwable) {
            $lanzo = true;
        }

        $this->assertTrue(
            $lanzo,
            'public.app_clinica_id() debe LANZAR EXCEPCION sin contexto. '
            . 'Si devuelve NULL, una consulta sin tenant devuelve cero filas sin avisar.'
        );
    }

    public function test_la_vista_de_clinicas_publicas_no_expone_datos_personales(): void
    {
        // `clinicas` no lleva RLS a proposito (se consulta en el ingreso, sin
        // contexto). La compensacion es esta vista, que expone SOLO estas tres
        // columnas y solo las clinicas activas. Ver docs/03.
        $columnas = DB::selectOne(
            "SELECT string_agg(column_name, ',' ORDER BY ordinal_position) AS lista
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'vw_clinicas_publicas'",
        );

        $this->assertNotNull($columnas, 'La vista vw_clinicas_publicas no existe.');
        $this->assertSame('activo,nombre,slug', $columnas->lista);
    }

    public function test_la_vista_de_clinicas_publicas_no_trae_clinicas_inactivas(): void
    {
        Clinica::query()->create([
            'slug' => 'clinica-visible',
            'nombre' => 'Clinica visible',
            'zona_horaria' => 'America/Santiago',
            'correlacion_identidad' => 'clinica-visible',
            'activo' => true,
        ]);

        Clinica::query()->create([
            'slug' => 'clinica-oculta',
            'nombre' => 'Clinica oculta',
            'zona_horaria' => 'America/Santiago',
            'correlacion_identidad' => 'clinica-oculta',
            'activo' => false,
        ]);

        $visibles = DB::select('SELECT slug FROM public.vw_clinicas_publicas');

        $slugs = array_map(static fn ($fila): string => (string) $fila->slug, $visibles);

        $this->assertContains('clinica-visible', $slugs);
        $this->assertNotContains('clinica-oculta', $slugs);
    }
}
