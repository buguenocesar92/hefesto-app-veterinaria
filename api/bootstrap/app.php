<?php

declare(strict_types=1);

use App\Http\Middleware\AuditarFicha;
use App\Http\Middleware\ResolverTenant;
use App\Http\Middleware\SoloAdministrador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/**
 * Punto de arranque de la API de VeterinariaHefesto.
 *
 * Nota sobre el esqueleto: este archivo reemplaza al que genera
 * `composer create-project laravel/laravel`. El resto del esqueleto (artisan,
 * public/index.php, storage) lo genera Composer. Ver docs/04.
 *
 * Todas las rutas cuelgan del grupo `web` de Laravel porque la interfaz (Nuxt)
 * se autentica con cookie de sesion: la cookie viaja en el mismo origen y el
 * token CSRF se reenvia como cabecera X-XSRF-TOKEN. Ver docs/01.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/api/v1/salud',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ResolverTenant envuelve la peticion autenticada en una transaccion
        // con la clinica ya fijada, para que la RLS sepa de quien son las
        // filas. Corre ANTES que `auth`, por eso resuelve el usuario por su
        // cuenta con el guard `web`.
        $middleware->web(append: [
            ResolverTenant::class,
        ]);

        $middleware->alias([
            // Marca las rutas que abren la ficha clinica de una mascota. El
            // middleware anota cada intento en `accesos_ficha`, permitido o
            // denegado.
            'ficha.auditada' => AuditarFicha::class,

            // Administracion de la clinica: profesionales, usuarios, esquemas
            // de vacunacion, productos. Recepcion, mostrador y profesionales
            // no entran.
            'admin' => SoloAdministrador::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * UN RECHAZO DE AUTORIZACION RESPONDE 404, NO 403
         * -------------------------------------------------
         * Un 403 confirmaria que el recurso existe. En una ficha clinica eso
         * importa mas que en un portal: no se le dice a quien pregunto si esa
         * mascota existe en esta clinica.
         *
         * El filtro por tenant ya impide que el recurso exista a ojos de la
         * otra clinica (la RLS devuelve cero filas), asi que 404 es ademas la
         * respuesta correcta en el caso habitual.
         */
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'mensaje' => 'No encontrado.',
                ], 404);
            }

            return null;
        });
    })
    ->create();
