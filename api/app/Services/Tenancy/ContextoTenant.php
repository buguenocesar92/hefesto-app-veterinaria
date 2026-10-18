<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contexto de clinica (tenant) de la peticion.
 *
 * PATRON TOMADO, NO INVENTADO
 * --------------------------
 * Este es el mismo patron que esta decidido y documentado en
 * apps/portal-autoservicio/docs/03-MULTITENANCY.md: columna `tenant_id` mas
 * Row Level Security de PostgreSQL, con el contexto viajando por
 * `set_config(..., true)`. La justificacion completa esta en ese documento y
 * se repite, adaptada a este dominio, en docs/03-MULTITENANCY-Y-ROLES.md.
 *
 * Solo cambia el nombre de la variable de sesion: aqui es `app.clinica_id`,
 * porque el tenant de esta aplicacion es una clinica veterinaria.
 *
 * ALCANCE DE TRANSICCION, Y POR QUE NO ES UN DETALLE
 * --------------------------------------------------
 * El `true` final de `set_config` significa ALCANCE DE TRANSACCION: al
 * confirmar o revertir, PostgreSQL descarta el valor solo.
 *
 * Con un `SET` normal (ambito de sesion) el valor sobrevive a la peticion. Y
 * el worker de colas **reutiliza la misma conexion** para trabajos de
 * clinicas distintas: un `SET` a sesion significa que un trabajo de la
 * clinica de Valparaiso podria leer los datos de la de Santiago. Ese es
 * exactamente el fallo que este diseno evita, y por eso `establecer()`
 * LANZA EXCEPCION si no hay una transaccion abierta.
 *
 * FALLA CERRADO
 * -------------
 * Si una consulta se ejecuta sin contexto, `public.app_clinica_id()` lanza
 * excepcion en la base en vez de devolver cero filas en silencio. Podria
 * haber devuelto NULL y filtrado todo calladamente, pero entonces nadie se
 * entera nunca. Que falle ruidosamente es lo que permite descubrir que una
 * operacion se escapo del middleware.
 */
class ContextoTenant
{
    /** Variable de sesion de PostgreSQL que leen las politicas RLS. */
    public const VARIABLE = 'app.clinica_id';

    /**
     * Fija la clinica de la peticion en la conexion actual.
     *
     * @throws RuntimeException si no hay una transaccion abierta.
     */
    public function establecer(string $clinicaId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'No se puede fijar el contexto de clinica fuera de una transaccion. '
                . 'Use App\Services\Tenancy\ContextoTenant::ejecutarAislado().'
            );
        }

        DB::select('SELECT set_config(?, ?, true)', [self::VARIABLE, $clinicaId]);
    }

    /**
     * Ejecuta una operacion dentro de una transaccion con la clinica fija.
     * Al salir, PostgreSQL descarta el valor de la variable.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    public function ejecutarAislado(string $clinicaId, Closure $operacion): mixed
    {
        return DB::transaction(function () use ($clinicaId, $operacion) {
            $this->establecer($clinicaId);

            return $operacion();
        });
    }

    /**
     * Lee la variable de sesion sin pasar por `public.app_clinica_id()`, que
     * lanza excepcion cuando no hay contexto.
     *
     * Si devuelve null, ninguna politica RLS de la base va a devolver filas.
     */
    public function clinicaIdEnConexion(): ?string
    {
        $fila = DB::select('SELECT current_setting(?, true) AS valor', [self::VARIABLE]);

        if ($fila === [] || $fila[0]->valor === null || $fila[0]->valor === '') {
            return null;
        }

        return (string) $fila[0]->valor;
    }

    /**
     * Ejecuta una operacion SIN contexto de clinica.
     *
     * Solo para tareas que por definicion cruzan clinicas: crear una clinica
     * nueva, el comando `clinica:verificar-aislamiento` y la comprobacion de
     * salud. Cualquier consulta a una tabla protegida fallara en RLS, que es
     * el comportamiento correcto.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    public function ejecutarSinClinica(Closure $operacion): mixed
    {
        return $operacion();
    }
}
