<?php

declare(strict_types=1);

/*
 * Proveedores propios de la aplicacion. Laravel carga esta lista al arrancar
 * (Application::configure() en bootstrap/app.php). Sin este archivo las
 * Policies, las abilities y el manejador de sesion no se registran nunca.
 */
return [
    App\Providers\AuthServiceProvider::class,
    App\Providers\SessionServiceProvider::class,
];
