<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lee el documento de descubrimiento OpenID Connect de Authentik.
 *
 * Rutas verificadas en la documentacion oficial de Authentik
 * (https://docs.goauthentik.io/add-secure-apps/providers/oauth2/):
 *
 *   OpenID Configuration  /application/o/<slug>/.well-known/openid-configuration
 *   JWKS                   /application/o/<slug>/jwks/
 *   Authorization          /application/o/authorize/
 *   Token                  /application/o/token/
 *   User Info              /application/o/userinfo/
 *
 * No se escriben a mano en el codigo: se leen del documento. Si Authentik
 * cambia una ruta, VeterinariaHefesto no se rompe.
 *
 * El resultado se cachea en Redis, que es la misma cache que usa el resto de
 * la aplicacion.
 */
class DescubridorOidc
{
    /** @var array<string, mixed>|null */
    private ?array $documento = null;

    /**
     * @return array<string, mixed>
     */
    public function documento(): array
    {
        if ($this->documento !== null) {
            return $this->documento;
        }

        $emisor = rtrim((string) config('authentik.issuer'), '/') . '/';

        $this->documento = Cache::remember(
            'veterinaria:oidc:descubrimiento:' . sha1($emisor),
            (int) config('authentik.cache_metadata', 3600),
            function () use ($emisor): array {
                $respuesta = Http::timeout(10)
                    ->retry(2, 200)
                    ->acceptJson()
                    ->get($emisor . '.well-known/openid-configuration');

                if (! $respuesta->successful()) {
                    throw new RuntimeException(
                        'No se pudo leer el documento de descubrimiento de Authentik en '
                        . $emisor . '.well-known/openid-configuration (HTTP '
                        . $respuesta->status() . '). Revise AUTHENTIK_ISSUER en la configuracion.'
                    );
                }

                $documento = $respuesta->json();

                if (! is_array($documento)) {
                    throw new RuntimeException('El documento de descubrimiento de Authentik no es un objeto JSON valido.');
                }

                return $documento;
            }
        );

        return $this->documento;
    }

    public function endpoint(string $clave): string
    {
        $documento = $this->documento();
        $valor = $documento[$clave] ?? null;

        if (! is_string($valor) || $valor === '') {
            throw new RuntimeException(
                'El documento de descubrimiento de Authentik no declara el endpoint "' . $clave . '". '
                . 'Revise la version de Authentik y la URL del emisor.'
            );
        }

        return $valor;
    }

    public function endpointAutorizacion(): string
    {
        return $this->endpoint('authorization_endpoint');
    }

    public function endpointToken(): string
    {
        return $this->endpoint('token_endpoint');
    }

    public function endpointUserInfo(): string
    {
        return $this->endpoint('userinfo_endpoint');
    }

    public function endpointJwks(): string
    {
        return $this->endpoint('jwks_uri');
    }

    public function endpointCierreSesion(): ?string
    {
        $valor = $this->documento()['end_session_endpoint'] ?? null;

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    /** Emisor declarado por el propio documento de descubrimiento. */
    public function emisor(): string
    {
        return (string) $this->endpoint('issuer');
    }

    /**
     * Conjunto de claves publicas (JWKS) para verificar los tokens.
     *
     * @return array<int, array<string, mixed>>
     */
    public function jwks(): array
    {
        $emisor = rtrim((string) config('authentik.issuer'), '/') . '/';

        return Cache::remember(
            'veterinaria:oidc:jwks:' . sha1($emisor),
            (int) config('authentik.cache_jwks', 86400),
            function (): array {
                $respuesta = Http::timeout(10)
                    ->retry(2, 200)
                    ->acceptJson()
                    ->get($this->endpointJwks());

                if (! $respuesta->successful()) {
                    throw new RuntimeException(
                        'No se pudo leer el JWKS de Authentik (HTTP ' . $respuesta->status() . ').'
                    );
                }

                $claves = $respuesta->json('keys');

                return is_array($claves) ? $claves : [];
            }
        );
    }

    /**
     * Descarta la cache de descubrimiento y de claves.
     *
     * Se usa cuando Authentik rota su clave de firma y la API sigue contra
     * la clave anterior. Ver docs/05.
     */
    public function olvidarCache(): void
    {
        $emisor = rtrim((string) config('authentik.issuer'), '/') . '/';

        Cache::forget('veterinaria:oidc:descubrimiento:' . sha1($emisor));
        Cache::forget('veterinaria:oidc:jwks:' . sha1($emisor));

        $this->documento = null;
    }
}