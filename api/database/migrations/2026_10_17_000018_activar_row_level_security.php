<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security: el aislamiento entre clinicas lo impone PostgreSQL.
 *
 * MISMO PATRON QUE EL PORTAL DE AUTOSERVICIO, MISMA JUSTIFICACION
 * --------------------------------------------------------------
 * La decision esta tomada y documentada en
 * apps/portal-autoservicio/docs/03-MULTITENANCY.md: columna `tenant_id` mas
 * politicas de RLS, NO un esquema de base de datos por clinica. Ese
 * documento explica por que se descarto el esquema por clinica y porque la
 * RLS no. Aqui no se repite el argumento; se aplica.
 *
 * LO QUE HACE ESTA MIGRACION, EN ORDEN
 * ------------------------------------
 *  1. `public.app_clinica_id()`: lee el contexto de la peticion y LANZA
 *     EXCEPCION si no hay. Falla cerrado.
 *  2. Una politica por tabla, con `USING` y `WITH CHECK`.
 *  3. `FORCE ROW LEVEL SECURITY` en cada una.
 *  4. `GRANT` explicito al rol de la aplicacion, porque con RLS las tablas
 *     quedan invisibles para el rol que no tenga permisos.
 *
 * 1. POR QUE FALLA CERRADO Y NO EN SILENCIO
 * -----------------------------------------
 * La funcion podria devolver NULL y filtrar todo de forma silenciosa. Se
 * eligio que lance excepcion porque:
 *
 *  - Con NULL, una consulta sin contexto devuelve cero filas y no hay aviso.
 *    Alguien "arregla" el reporte devolviendo NULL cuando lo necesita, la
 *    puerta queda abierta y nada lo delata.
 *  - Con excepcion, el error aparece en el registro en el momento, y se sabe
 *    exactamente que operacion se escapo del middleware.
 *
 * 2. POR QUE `WITH CHECK`
 * ----------------------
 * `USING` filtra lo que se LEE. `WITH CHECK` impide ESCRIBIR una fila con un
 * `tenant_id` distinto al del contexto. Sin esto, un `INSERT` con el tenant
 * equivocado pasaria la lectura y dejaria una fila invisible que nadie podria
 * ver ni borrar.
 *
 * 3. POR QUE `FORCE ROW LEVEL SECURITY`
 * -------------------------------------
 * Sin `FORCE`, las politicas NO aplican al dueno de la tabla, y Laravel se
 * conecta como el dueno (el mismo usuario que migra). En desarrollo, que es
 * donde se prueba, el aislamiento seria decorativo.
 *
 * SIN `SECURITY DEFINER` A PROPOSITO
 * ----------------------------------
 * Una funcion definida por el dueno saltaria sus propias politicas. La
 * funcion de contexto es `STABLE` y normal.
 *
 * SOBRE `sesiones`
 * ---------------
 * `sesiones` SI tiene politica, con una excepcion que hay que conocer: el
 * guard de sesion la lee ANTES de que exista el contexto de clinica. Por eso
 * la lee `App\Services\Auth\AlmacenDeSesion`, por una conexion sin RLS y solo
 * por identificador de sesion (un valor aleatorio que genera el servidor).
 * La justificacion completa esta en la migracion que crea la tabla.
 */
return new class extends Migration
{
    /**
     * Tablas de negocio, todas con `tenant_id`.
     *
     * La lista esta completa a proposito: si se agrega una tabla y no se
     * agrega aqui, se queda SIN AISLAMIENTO y nada avisa. Por eso
     * `clinica:verificar-aislamiento` recorre la lista y falla si una tabla
     * de negocio no tiene politica.
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

    public function up(): void
    {
        $this->crearFuncionDeContexto();
        $this->crearVistaPublica();

        foreach (self::TABLAS as $tabla) {
            $this->proteger($tabla);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLAS) as $tabla) {
            DB::statement('ALTER TABLE ' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS aislamiento_tenant ON ' . $tabla);
        }

        DB::statement('DROP VIEW IF EXISTS public.vw_clinicas_publicas');
        DB::statement('DROP FUNCTION IF EXISTS public.app_clinica_id()');
    }

    /**
     * Funcion que lee el contexto de clinica de la peticion.
     *
     * `current_setting(..., true)` devuelve NULL si la variable no existe, en
     * vez de fallar. Eso es justo el caso que hay que detectar.
     */
    private function crearFuncionDeContexto(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_clinica_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.clinica_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION
                        'Falta el contexto de clinica (app.clinica_id). '
                        'Usa App\Services\Tenancy\ContextoTenant::ejecutarAislado().'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN valor::uuid;
            END;
            $$
            SQL);
    }

    /**
     * La compensacion por que `clinicas` no lleva RLS.
     *
     * `clinicas` se consulta durante el ingreso, cuando todavia no hay sesion
     * y no hay contexto que poner. Antes del login, la aplicacion no lee la
     * tabla cruda: lee esta vista, que expone SOLO tres columnas y solo las
     * clinicas activas. No expone razon social, contactos ni la correlacion
     * de identidad.
     *
     * La segunda compensacion es que el rol de la aplicacion no sea
     * superusuario ni tenga BYPASSRLS: lo comprueba
     * `clinica:verificar-aislamiento`.
     */
    private function crearVistaPublica(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_clinicas_publicas AS
            SELECT
                slug,
                nombre,
                activo
            FROM public.clinicas
            WHERE activo = true
        SQL);
    }

    private function proteger(string $tabla): void
    {
        DB::statement('ALTER TABLE ' . $tabla . ' ENABLE ROW LEVEL SECURITY');

        // Sin esto, las politicas no aplican al dueno de la tabla.
        DB::statement('ALTER TABLE ' . $tabla . ' FORCE ROW LEVEL SECURITY');

        DB::statement(<<<SQL
            CREATE POLICY aislamiento_tenant ON {$tabla}
                USING (tenant_id = public.app_clinica_id())
                WITH CHECK (tenant_id = public.app_clinica_id())
            SQL);

        /*
         * POR QUE NO HAY `GRANT` AQUI
         * --------------------------
         * Se podria escribir `GRANT SELECT, INSERT, UPDATE, DELETE ON <tabla>
         * TO PUBLIC` creyendo que, al activar politicas, PostgreSQL deja de
         * aplicar los permisos de tabla. No es asi: el dueno de una tabla
         * tiene TODOS los privilegios sobre ella de forma implicita, y lo que
         * `FORCE ROW LEVEL SECURITY` hace es someterlo tambien a las
         * POLITICAS, no quitarle permisos.
         *
         * Y `TO PUBLIC` seria un error grave: en una base multi-clinica,
         * conceder a PUBLIC significa que CUALQUIER rol conectado a esta base
         * (incluidos los de respaldo, de migracion o de solo lectura) pasa a
         * tener permiso de escritura sobre datos de salud. La politica lo
         * seguiria limitando por tenant, pero se pierde una capa.
         *
         * QUE HACER SI EL ROL DE LA APLICACION NO ES EL DUENO
         * ------------------------------------------------------
         * En el despliegue de referencia el rol de la aplicacion ES el dueno
         * (ver docs/04). Si alguien usa un rol aparte de solo lectura para
         * reportar, los permisos hay que darlos a mano, por rol y con nombre
         * explicito. No se hace aqui a proposito: esta migracion no conoce
         * los roles del despliegue.
         */
    }
};
