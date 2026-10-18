<?php

declare(strict_types=1);

namespace App\Services\Agenda;

use App\Models\Profesional;
use Illuminate\Support\Facades\Cache;

/**
 * La agenda del dia, en Redis.
 *
 * POR QUE ESTA SEPARADA DE LA CONSULTA
 * ------------------------------------
 * La consulta de la agenda es la mas cara de la aplicacion (varios `join` con
 * mascotas y tutores) y la que mas veces se abre. Su contenido cambia pocas
 * veces: la lista de un profesional para una fecha dada.
 *
 * EL AISLAMIENTO DE LA CACHE, QUE ES LO IMPORTANTE
 * -------------------------------------------------
 * La clave empieza SIEMPRE por el `tenant_id`.
 *
 * Esto no es cosmetico. La RLS de PostgreSQL protege la consulta, pero la
 * CACHE guarda el RESULTADO: si dos clinicas compartieran clave, la clinica B
 * recibiria de Redis la agenda que se calculo para la clinica A, y la RLS no
 * tendria nada que decir porque la consulta ya ocurrio hace un rato.
 *
 * El cacheo nunca debe poder ampliar el aislamiento. Solo lo puede romper si
 * se olvidan estas dos lineas.
 */
class CacheAgenda
{
    /**
     * Cuanto vive una entrada sin que se la toque. Corto a proposito: la
     * agenda cambia por cancelaciones de ultima hora, y una entrada de una
     * hora muestra una agenda que ya no es.
     */
    private const SEGUNDOS = 300;

    /**
     * Lee la agenda de la cache, o la construye y la guarda.
     *
     * @template T
     *
     * @param  callable(): T  $construir
     * @return T
     */
    public function recordar(string $tenantId, string $fecha, ?string $profesionalId, callable $construir): mixed
    {
        $clave = $this->clave($tenantId, $fecha, $profesionalId);

        return Cache::remember($clave, self::SEGUNDOS, $construir);
    }

    /**
     * Invalida la agenda de una clinica, para el dia y el profesional que se
     * tocaron.
     *
     * Se llama SIEMPRE que se cree, modifique o cancele una cita. Sin esto, la
     * pantalla muestra una agenda vieja hasta que expire la entrada.
     */
    public function olvidar(string $tenantId, string $fecha, ?string $profesionalId = null): void
    {
        // Con `profesional_id = null` hay que tirar la clave general y las de
        // cada profesional, porque la vista "todos" incluye a todos.
        if ($profesionalId === null) {
            Cache::forget($this->clave($tenantId, $fecha, null));

            foreach (Profesional::query()->where('tenant_id', $tenantId)->pluck('id') as $id) {
                Cache::forget($this->clave($tenantId, $fecha, (string) $id));
            }

            return;
        }

        // La entrada del profesional y la general: las dos quedaron viejas.
        Cache::forget($this->clave($tenantId, $fecha, $profesionalId));
        Cache::forget($this->clave($tenantId, $fecha, null));
    }

    /**
     * La clave. El `tenant_id` va primero, siempre.
     */
    private function clave(string $tenantId, string $fecha, ?string $profesionalId): string
    {
        return 'agenda:' . $tenantId . ':' . $fecha . ':' . ($profesionalId ?? 'todos');
    }
}
