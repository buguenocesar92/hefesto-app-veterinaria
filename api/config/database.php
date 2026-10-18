<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Conexiones de base de datos
|------------------------------------------------------------------------------
|
| PostgreSQL es obligatorio. La Row Level Security no existe en otro motor, y
| el aislamiento entre clinicas depende de ella. Ver docs/03.
|
| HAY TRES CONEXIONES, Y CADA UNA TIENE UN MOTIVO
| -------------------------------------------------
|
|  - `pgsql`: la de la peticion. Corre dentro de una transaccion con el
|    contexto de clinica fijado, que es lo que leen las politicas RLS.
|
|  - `sesiones`: la MISMA base, pero por un rol que NO esta sujeto a RLS.
|    Existe por un problema de orden: el guard de sesion tiene que leer la
|    fila de `sesiones` ANTES de saber de que clinica es la peticion, y con
|    la politica activa eso lanzaria excepcion y nadie podria entrar.
|    La justificacion completa esta en App\Services\Auth\AlmacenDeSesion.
|
|  - `bitacora`: la de los accesos a la ficha clinica. Es APARTE porque la
|    peticion puede terminar revertida (un acceso denegado) y el registro del
|    intento tiene que sobrevivir a esa reversa.
|
| Las tres apuntan a la misma base por omision. Se pueden separar
| fisicamente con las variables `DB_SESIONES_*` y `DB_BITACORA_*` si la
| clinica lo necesita: son dos de las separaciones que suele pedir una
| auditoria externa. Ver docs/07 y docs/08.
|
*/

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'veterinaria'),
            'username' => env('DB_USERNAME', 'veterinaria'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            // La aplicacion no usa SSL contra PostgreSQL porque los
            // contenedores se comunican por la red interna de Compose. Si la
            // base queda afuera, hay que poner `DB_SSLMODE=require` y revisar
            // la cadena de conexion. Ver docs/04.
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
         * Solo para leer la fila de sesion antes del contexto de clinica.
         * Ver App\Services\Auth\AlmacenDeSesion.
         *
         * El usuario que se pone aqui DEBE ser un rol sin BYPASSRLS y con
         * permiso de SELECT sobre `sesiones` solamente. `clinica:verificar-aislamiento`
         * comprueba que ese rol no tenga BYPASSRLS ni sea superusuario.
         */
        'sesiones' => [
            'driver' => 'pgsql',
            'host' => env('DB_SESIONES_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_SESIONES_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_SESIONES_DATABASE', env('DB_DATABASE', 'veterinaria')),
            'username' => env('DB_SESIONES_USERNAME', env('DB_USERNAME', 'veterinaria')),
            'password' => env('DB_SESIONES_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SESIONES_SSLMODE', env('DB_SSLMODE', 'prefer')),
        ],

        /*
         * Para la bitacora de accesos a la ficha clinica.
         *
         * Puede apuntar a otra base de datos, incluso a otra maquina: es una
         * de las separaciones que suele pedir una auditoria externa, porque
         * asi los accesos quedan fuera del alcance de quien opera la
         * aplicacion. Ver docs/07.
         */
        'bitacora' => [
            'driver' => 'pgsql',
            'host' => env('DB_BITACORA_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_BITACORA_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_BITACORA_DATABASE', env('DB_DATABASE', 'veterinaria')),
            'username' => env('DB_BITACORA_USERNAME', env('DB_USERNAME', 'veterinaria')),
            'password' => env('DB_BITACORA_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_BITACORA_SSLMODE', env('DB_SSLMODE', 'prefer')),
        ],

    ],

    /*
    | Lecturas MySQL y SQLite quedan para que la estructura sea la misma que
    | en las demas aplicaciones de Hefesto. VeterinariaHefesto NO los usa: la
    | RLS es de PostgreSQL y no se negocia. Ver docs/01.
    */
    'mysql' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'veterinaria'),
        'username' => env('DB_USERNAME', 'veterinaria'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
    ],

    'sqlite' => [
        'driver' => 'sqlite',
        'database' => env('DB_DATABASE', database_path('database.sqlite')),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),

        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];
