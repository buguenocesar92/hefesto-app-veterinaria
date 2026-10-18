<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sesion
    |--------------------------------------------------------------------------
    |
    | `driver = database`, con las sesiones en la tabla `sesiones`.
    |
    | POR QUE EN LA BASE Y NO EN REDIS
    | --------------------------------
    | Una sesion autoriza el acceso a una ficha clinica. Si Redis (que es
    | cache) se pierde o se reinicia, quien estaba atendiendo se queda fuera
    | en mitad de una consulta. En la base, no pasa.
    |
    | Redis se usa para lo que es cache y para las colas, no para el almacen
    | de sesiones.
    |
    | POR QUE `AlmacenDeSesion` Y NO EL MANEJADOR DE LARAVEL
    | ------------------------------------------------------
    | La tabla `sesiones` tiene Row Level Security, y el guard de sesion tiene
    | que leerla ANTES de saber de que clinica es la peticion. Con el manejador
    | de Laravel, esa lectura lanzaria excepcion de la politica y **nadie
    | podria entrar al sistema**.
    |
    | `AlmacenDeSesion` resuelve el orden: lee por una conexion sin RLS y solo
    | por identificador de sesion (un valor aleatorio de 40 caracteres que
    | genera el servidor). La justificacion completa, incluyendo las dos
    | alternativas descartadas, esta en App\Services\Auth\AlmacenDeSesion.
    |
    */

    'driver' => env('SESSION_DRIVER', 'database'),

    'lifetime' => (int) env('SESSION_EXPIRACION_MINUTOS', 120),

    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => null,

    'table' => 'sesiones',

    'store' => null,

    'lottery' => [2, 100],

    /*
    | Cookie de sesion
    |
    | `secure` en produccion: la cookie solo viaja por HTTPS. En local queda en
    | `false` porque no hay TLS.
    |
    | `same_site = lax` evita que una sesion se mande desde otro sitio, que es
    | la mitad del ataque CSRF.
    */

    'domain' => env('SESSION_DOMAIN'),

    'path' => '/',

    'secure' => (bool) env('SESSION_SECURE_COOKIE', false),

    'http_only' => true,

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

];
