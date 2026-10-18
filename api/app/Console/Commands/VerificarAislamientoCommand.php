<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Clinica;
use App\Models\Mascota;
use App\Models\Tutor;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comprueba, desde la consola del servidor, que el aislamiento funciona.
 *
 *     php artisan clinica:verificar-aislamiento
 *
 * QUE COMPRUEBA
 * -------------
 *  1. Que las tablas de negocio tengan RLS ACTIVADO y FORZADO.
 *  2. Que la lectura cruzada entre dos clinicas de verdad devuelva cero filas.
 *  3. Que una consulta SIN contexto lance excepcion (falla cerrado).
 *  4. Que el rol conectado NO sea superusuario ni tenga BYPASSRLS.
 *
 * POR QUE HAY QUE CORRERLO EN EL SERVIDOR
 * --------------------------------------
 * Las pruebas automatizadas comprueban el comportamiento en la base de
 * pruebas, con el rol que uso el contenedor. En el servidor el rol suele ser
 * otro, con otros permisos. Un rol con BYPASSRLS apaga el aislamiento
 * COMPLETO, y no hay ningun sintoma visible: la aplicacion funciona
 * perfectamente y se ven datos de otras clinicas.
 *
 * NO ES UNA PRUEBA UNITARIA
 * -------------------------
 * Escribe filas de prueba y las borra al final. **No debe correrse contra una
 * base con datos reales.**
 */
class VerificarAislamientoCommand extends Command
{
    protected $signature = 'clinica:verificar-aislamiento
        {--clinica : Correlacion de una clinica existente para usar en la prueba. Si no se indica, se crean clinicas temporales.}';

    protected $description = 'Comprueba que el aislamiento entre clinicas funciona a nivel de base de datos.';

    /**
     * Tablas que tienen que estar protegidas.
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

    public function handle(ContextoTenant $contexto): int
    {
        if (! $this->comprobarRol()) {
            return self::FAILURE;
        }

        if (! $this->comprobarTablas()) {
            return self::FAILURE;
        }

        return $this->comprobarLecturaCruzada($contexto);
    }

    /**
     * QUE EL ROL NO SE SALTE LA POLITICA
     * -----------------------------------
     * Un rol con `BYPASSRLS`, o superusuario, ignora TODAS las politicas. El
     * sistema quedaria sin aislamiento y sin ningun sintoma: la aplicacion
     * funciona bien y cada quien ve los datos de las demas clinicas.
     *
     * Es el fallo mas grave posible y el mas silencioso, asi que se comprueba
     * primero, antes de dar la instalacion por buena y antes de escribir las
     * filas de la prueba.
     *
     * La consulta va directo a `pg_roles` por el nombre del rol: no depende de
     * que la base tenga datos, asi que una instalacion recien creada, vacia,
     * tambien se verifica.
     */
    private function comprobarRol(): bool
    {
        $fila = DB::selectOne(
            'SELECT rolname AS nombre, rolsuper AS superusuario, rolbypassrls AS bypass
             FROM pg_roles WHERE rolname = current_user',
        );

        if ($fila === null) {
            $this->components->error('No se pudo identificar el rol conectado.');

            return false;
        }

        if ($fila->superusuario) {
            $this->components->error("El rol \"{$fila->nombre}\" ES SUPERUSUARIO.");
            $this->components->error('Un superusuario ignora TODAS las politicas de Row Level Security.');
            $this->components->error('`FORCE ROW LEVEL SECURITY` sujeta al dueno de la tabla, pero NO al');
            $this->components->error('superusuario: con este rol el aislamiento entre clinicas no existe.');
            $this->components->error('Que hacer: `POSTGRES_USER` debe ser el rol de arranque');
            $this->components->error('`veterinaria_admin`, que la aplicacion nunca usa, y el rol de la');
            $this->components->error('aplicacion tiene que estar creado NOSUPERUSER NOBYPASSRLS en');
            $this->components->error('`deploy/postgres/inicializar-rol.sh`.');
            $this->components->error('Patron de referencia: apps/almacen/deploy/postgres/inicializar-rol.sh');

            return false;
        }

        if ($fila->bypass) {
            $this->components->error("El rol \"{$fila->nombre}\" tiene BYPASSRLS.");
            $this->components->error('Con BYPASSRLS, PostgreSQL ignora TODAS las politicas de Row Level Security,');
            $this->components->error('aunque las tablas tengan `FORCE ROW LEVEL SECURITY` puesto.');
            $this->components->error('Que hacer: el mismo de arriba. `POSTGRES_USER` debe ser el rol de');
            $this->components->error('arranque `veterinaria_admin`, que la aplicacion nunca usa, y el rol de');
            $this->components->error('la aplicacion tiene que estar creado NOSUPERUSER NOBYPASSRLS en');
            $this->components->error('`deploy/postgres/inicializar-rol.sh`.');
            $this->components->error('Patron de referencia: apps/almacen/deploy/postgres/inicializar-rol.sh');

            return false;
        }

        $this->components->info("El rol \"{$fila->nombre}\" no es superusuario ni tiene BYPASSRLS.");

        return true;
    }

    private function comprobarTablas(): bool
    {
        $fallos = 0;

        $this->components->info('Revisando que las tablas de negocio tengan RLS forzado.');

        foreach (self::TABLAS as $tabla) {
            $fila = DB::selectOne(
                'SELECT relrowsecurity AS rls, relforcerowsecurity AS forzado
                 FROM pg_class WHERE relname = ?',
                [$tabla],
            );

            if ($fila === null) {
                $this->components->error("La tabla {$tabla} no existe.");
                $fallos++;

                continue;
            }

            if (! $fila->rls || ! $fila->forzado) {
                $this->components->error("La tabla {$tabla} no tiene RLS ACTIVADO Y FORZADO.");
                $fallos++;
            }
        }

        if ($fallos > 0) {
            $this->components->error('La instalacion NO tiene el aislamiento activo. No sigas.');

            return false;
        }

        $this->components->info('Las tablas tienen RLS forzado.');

        return true;
    }

    private function comprobarLecturaCruzada(ContextoTenant $contexto): int
    {
        $clinicaA = $this->obtenerOCrearClinica('verificacion-a');
        $clinicaB = $this->obtenerOCrearClinica('verificacion-b');

        $tutorB = $contexto->ejecutarAislado($clinicaB->id, fn (): Tutor => Tutor::query()->create([
            'tenant_id' => $clinicaB->id,
            'rut' => '00.000.001-1',
            'nombres' => 'Tutor',
            'apellidos' => 'DeVerificacion',
            'activo' => true,
        ]));

        $mascotaB = $contexto->ejecutarAislado($clinicaB->id, fn (): Mascota => Mascota::query()->create([
            'tenant_id' => $clinicaB->id,
            'tutor_id' => $tutorB->id,
            'nombre' => 'MascotaDeVerificacion',
            'especie' => 'canino',
            'activo' => true,
        ]));

        try {
            $vistos = $contexto->ejecutarAislado(
                $clinicaA->id,
                fn (): int => Mascota::query()->whereKey($mascotaB->id)->count(),
            );

            if ($vistos !== 0) {
                $this->components->error('La clinica A alcanzo a ver una mascota de la clinica B.');
                $this->components->error('El aislamiento NO funciona. Revise FORCE ROW LEVEL SECURITY.');

                return self::FAILURE;
            }

            $this->components->info('A no ve mascotas de B. Correcto.');

            /*
             * Y la prueba de escritura: `WITH CHECK` tiene que impedir
             * guardar una fila declarando el tenant de otra.
             */
            try {
                $contexto->ejecutarAislado($clinicaA->id, function () use ($clinicaA, $clinicaB, $tutorB): void {
                    Mascota::query()->create([
                        'tenant_id' => $clinicaB->id,
                        'tutor_id' => $tutorB->id,
                        'nombre' => 'Intrusa',
                        'especie' => 'canino',
                        'activo' => true,
                    ]);
                });

                $this->components->error('A pudo escribir una fila declarando el tenant de B.');
                $this->components->error('Falta WITH CHECK en la politica. El aislamiento NO esta completo.');

                return self::FAILURE;
            } catch (Throwable) {
                $this->components->info('A no puede escribir en nombre de B. Correcto.');
            }

            try {
                Mascota::query()->count();

                $this->components->error('Una consulta sin contexto devolvio filas en vez de fallar.');
                $this->components->error('La funcion public.app_clinica_id() deberia lanzar excepcion.');

                return self::FAILURE;
            } catch (Throwable) {
                $this->components->info('Sin contexto, la consulta falla. El aislamiento falla cerrado.');
            }
        } finally {
            $contexto->ejecutarAislado($clinicaB->id, function () use ($mascotaB): void {
                $mascotaB->delete();
            });
        }

        $this->components->info('Aislamiento verificado.');

        return self::SUCCESS;
    }

    private function obtenerOCrearClinica(string $sufijo): Clinica
    {
        $correlacion = 'veterinaria-' . $sufijo;

        return Clinica::query()->firstOrCreate(
            ['correlacion_identidad' => $correlacion],
            [
                'slug' => $correlacion,
                'nombre' => 'Temporal de verificacion (' . $sufijo . ')',
                'zona_horaria' => 'America/Santiago',
                'activo' => true,
            ],
        );
    }
}
