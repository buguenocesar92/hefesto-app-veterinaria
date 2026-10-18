<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Ficha\BitacoraFichas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Anota en la bitacora CADA intento de abrir una ficha clinica.
 *
 * POR QUE UN MIDDLEWARE Y NO DENTRO DEL CONTROLADOR
 * -------------------------------------------------
 * Porque el intento denegado casi nunca llega al controlador: lo corta la
 * Policy, o la RLS devuelve cero filas. Si la anotacion estuviera en el
 * controlador, los intentos denegados no existirian.
 *
 * El middleware anotara antes de que la Policy decida, y el controlador no
 * sabe que paso antes que el.
 *
 * QUE ANOTA
 * ---------
 * `permitido` cuando la Policy dejo pasar, `denegado` cuando no. En los dos
 * casos, con el usuario, la mascota a la que se quiso llegar y desde donde.
 *
 * LA MASCOTA VIENE DE LA RUTA, SIN CONSULTAR LA BASE
 * --------------------------------------------------
 * `mascota` es el identificador de la ruta, no una fila leida. Es
 * deliberado: si el middleware consultara la ficha para saber a que mascota
 * se intenta llegar, el propio intento denegado seria una lectura de ficha,
 * y quedaria registrado como permiso.
 *
 * LA EXCEPCION AL FINAL
 * --------------------
 * Si la peticion termina en `AuthorizationException`, se anota `denegado`.
 * Si termina bien, `permitido`. Lo que sea otro error (de validacion, de
 * base) no dice nada sobre el acceso a la ficha y no se anota.
 */
class AuditarFicha
{
    public function __construct(private readonly BitacoraFichas $bitacora) {}

    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = $peticion->user();

        if ($usuario === null) {
            return $siguiente($peticion);
        }

        $mascotaId = $peticion->route('mascota');
        $mascotaId = is_string($mascotaId) ? $mascotaId : null;

        $ip = $peticion->ip();
        $agente = substr((string) $peticion->userAgent(), 0, 240);

        try {
            $respuesta = $siguiente($peticion);
        } catch (Throwable $excepcion) {
            if ($this->esDenegadoDeAutorizacion($excepcion)) {
                $this->bitacora->denegado(
                    (string) $usuario->id,
                    $mascotaId,
                    (string) $usuario->tenant_id,
                    'La Policy no permitio el acceso.',
                    $ip,
                    $agente,
                );
            }

            throw $excepcion;
        }

        $this->bitacora->permitido(
            (string) $usuario->id,
            $mascotaId,
            (string) $usuario->tenant_id,
            $ip,
            $agente,
        );

        return $respuesta;
    }

    /**
     * Si la excepcion es un rechazo de autorizacion y no otra cosa.
     */
    private function esDenegadoDeAutorizacion(Throwable $excepcion): bool
    {
        return $excepcion instanceof \Illuminate\Auth\Access\AuthorizationException
            || $excepcion instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
    }
}
