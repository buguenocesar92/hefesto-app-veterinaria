<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\AlmacenDeSesion;
use Illuminate\Support\ServiceProvider;

/**
 * Registra el manejador de sesion que resuelve el problema de orden con RLS.
 *
 * POR QUE ESTE ARCHIVO EXISTE
 * ---------------------------
 * La tabla `sesiones` tiene Row Level Security, como todas las tablas de
 * negocio. Pero el guard de sesion tiene que leerla antes de saber de que
 * clinica es la peticion, y con el manejador por defecto de Laravel esa
 * lectura lanzaria excepcion de la politica: la sesion no se reconstruye y
 * **nadie puede entrar**.
 *
 * `SessionManager::extend()` es el punto de extension que ofrece Laravel para
 * cambiar el manejador del controlador `database` sin replacear todo el
 * gestor de sesiones.
 *
 * La justificacion completa, con las alternativas descartadas, esta en
 * App\Services\Auth\AlmacenDeSesion.
 */
class SessionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->app['session']->extend('database', function ($app, $config) {
            $manejador = new AlmacenDeSesion(
                $app['db']->connection(),
                $config['table'] ?? 'sesiones',
                (int) ($config['lifetime'] ?? 120),
            );

            return $manejador;
        });
    }
}
