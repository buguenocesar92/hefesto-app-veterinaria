<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Lee la sesion ANTES de que exista el contexto de clinica.
 *
 * ===================================================================
 * EL PROBLEMA: UNA TABLA CON RLS QUE HAY QUE LEER SIN EL CONTEXTO
 * ===================================================================
 *
 * `sesiones` tiene `tenant_id` y tiene politica de RLS, como todas las tablas
 * de negocio. Pero el orden real de una peticion es este:
 *
 *   1. El navegador manda la cookie de sesion.
 *   2. Laravel necesita leer la fila de `sesiones` para reconstruir al
 *      usuario y saber de que clinica es.
 *   3. SOLO EN EL PASO 3 se puede fijar `app.clinica_id`.
 *
 * En el paso 2 todavia no hay `app.clinica_id` que poner. Y aqui esta la
 * trampa: `public.app_clinica_id()` **LANZA EXCEPCION** cuando no hay
 * contexto (a proposito, para que una consulta sin tenant falle en vez de
 * devolver cero filas en silencio).
 *
 * O sea: con la politica normal, leer `sesiones` en el paso 2 lanzaria
 * excepcion, la sesion nunca se reconstruiria, y **nadie podria entrar al
 * sistema**.
 *
 * TRES SALIDAS, Y POR QUE SE ELIGIO ESTA
 * --------------------------------------
 *
 * A) Guardar el tenant en un claim de la cookie, firmado, y fijar el contexto
 *    desde ahi antes de tocar la base.
 *    -> Descartada: obliga a mantener el tenant en dos lugares (la cookie y la
 *       fila) y a resolver que pasa cuando no coinciden.
 *
 * B) Quitarle RLS a `sesiones`.
 *    -> Descartada: `sesiones.payload` guarda el estado de autenticacion. Sin
 *       RLS, cualquier rol con acceso a la base puede leer sesiones de otras
 *       clinicas. Es la tabla mas sensitive para ese error.
 *
 * C) Leer la sesion por una conexion SIN RLS, solo por identificador.
 *    -> ELEGIDA. Es lo que hace esta clase.
 *
 * POR QUE LA CONEXION SIN RLS ES SEGURA
 * --------------------------------------
 * La consulta que se hace es `SELECT ... WHERE id = ?`, y el `id` de sesion
 * es una cadena aleatoria de 40 caracteres que genera el SERVIDOR y que
 * viaja en una cookie firmada. No hay `tenant_id` que adivinar: sin la
 * cookie correcta no hay fila que traer, y traer la fila equivocada exigiria
 * adivinar un valor de 40 caracteres.
 *
 * Y el alcance es minimo: solo se usa para LEER la fila de sesion durante el
 * ingreso de la peticion. En cuanto se conoce el usuario, `ResolverTenant`
 * fija el contexto y todo lo demas vuelve a pasar por la conexion normal, con
 * RLS.
 *
 * LO QUE NO SE ESCRIBE POR AQUI
 * -----------------------------
 * El `tenant_id` de la fila de sesion se escribe DESPUES, con el contexto ya
 * fijado, en la conexion normal. Ver `escribirTenantDeLaSesion()`.
 *
 * @see \App\Http\Middleware\ResolverTenant
 * @see \App\Services\Tenancy\ContextoTenant
 */
class AlmacenDeSesion extends DatabaseSessionHandler
{
    /**
     * Conexion que se usa para leer la sesion antes del contexto.
     *
     * Apunta a la misma base, pero con el rol que NO esta sujeto a RLS. Ver
     * config/database.php, conexion `sesiones`.
     */
    private const CONEXION_SIN_RLS = 'sesiones';

    /**
     * Lee el contenido de una sesion.
     *
     * @return string
     */
    public function read($sessionId)
    {
        $fila = $this->conexionSinRls()->table('sesiones')
            ->where('id', $sessionId)
            ->first();

        if ($fila === null) {
            return '';
        }

        return (string) $fila->payload;
    }

    /**
     * Si la sesion existe.
     *
     * @return bool
     */
    public function exists($sessionId): bool
    {
        return $this->conexionSinRls()->table('sesiones')
            ->where('id', $sessionId)
            ->exists();
    }

    /**
     * Anota el `tenant_id` de la sesion, ya con el contexto fijado.
     *
     * Va por la conexion NORMAL a proposito: si fuera por la de este
     * manejador, se escribiria un tenant_id sin que la RLS lo compruebe, que
     * es justo lo que la RLS existe para impedir.
     *
     * @param  string|null  $tenantId
     */
    public function escribirTenantDeLaSesion(string $sessionId, ?string $tenantId, ?string $usuarioId = null): void
    {
        if ($tenantId === null) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('sesiones')
            ->where('id', $sessionId)
            ->update([
                'tenant_id' => $tenantId,
                'usuario_id' => $usuarioId,
                'ultimo_uso' => now(),
            ]);
    }

    /**
     * Borra la sesion al cerrar la peticion.
     *
     * Va por la conexion normal: para borrar hace falta el contexto, y la
     * sesion que se borra es la del usuario que esta saliendo.
     *
     * @return bool
     */
    public function destroy($sessionId): bool
    {
        return (bool) \Illuminate\Support\Facades\DB::table('sesiones')
            ->where('id', $sessionId)
            ->delete();
    }

    /**
     * Conexion sin RLS, solo para leer la sesion.
     */
    private function conexionSinRls(): \Illuminate\Database\Connection
    {
        return \Illuminate\Support\Facades\DB::connection(self::CONEXION_SIN_RLS);
    }
}
