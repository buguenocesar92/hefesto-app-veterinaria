<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Auth\DescubridorOidc;
use Illuminate\Console\Command;

/**
 * Descarta la cache de descubrimiento y de claves de Authentik.
 *
 *     php artisan clinica:olvidar-cache-oidc
 *
 * CUANDO SE NECESITA
 * ------------------
 * Cuando Authentik rota sus claves de firma y la API sigue rechazando tokens
 * validos con el error "la clave que firmo el token no esta en el JWKS".
 *
 * El JWKS esta cacheado (por `AUTHENTIK_CACHE_JWKS`, un dia por omision), y
 * un proceso de PHP de larga duracion lo tiene en memoria ademas. Este
 * comando limpia ambas cosas.
 *
 * NO ES UNA SOLUCION PARA EL PROBLEMA REAL
 * ----------------------------------------
 * Si la API dice que la clave no esta en el JWKS, puede que Authentik haya
 * rotado de verdad, o puede que el `issuer` este mal escrito. Revisar antes de
 * limpiar la cache: si el problema es la configuracion, limpiarla solo retrasa
 * el error.
 */
class OlvidarCacheOidcCommand extends Command
{
    protected $signature = 'clinica:olvidar-cache-oidc';

    protected $description = 'Descarta la cache del documento de descubrimiento y de las claves de Authentik.';

    public function handle(DescubridorOidc $descubridor): int
    {
        $this->descubridor->olvidarCache();

        $this->components->info('Cache de Authentik descartada.');

        $this->components->warn(
            'Si la API sigue rechazando tokens, el problema probablemente sea otro: '
            . 'revise AUTHENTIK_ISSUER y que el alcance "openid" este habilitado en el proveedor OAuth2.'
        );

        return self::SUCCESS;
    }
}
