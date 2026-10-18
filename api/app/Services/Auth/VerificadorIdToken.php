<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use Throwable;

/**
 * Verifica el token de identificacion (ID token) que entrega Authentik.
 *
 * Socialite canjea el codigo de autorizacion, pero NO verifica el token de
 * identificacion. Sin esta clase la aplicacion aceptaria como valido cualquier
 * token, incluso uno con el `iss` de otro proveedor de identidad. En una
 * aplicacion que abre fichas clinicas, eso no es un detalle: es la puerta de
 * entrada.
 *
 * Se comprueba, en este orden:
 *
 *  1. Que el token tenga tres segmentos y una firma.
 *  2. Que el algoritmo de la cabecera sea el esperado. Esto corta el ataque de
 *     confusion de algoritmo, en el que alguien cambia RS256 por HS256 y firma
 *     con la clave publica.
 *  3. Que la clave de verificacion (JWKS) corresponda al `kid` del token.
 *  4. Firma valida (la verifica `JWT::decode`).
 *  5. `iss` igual al emisor configurado.
 *  6. `aud` contiene el identificador de cliente.
 *  7. `nonce` igual al generado en esta misma sesion.
 *  8. `exp` en el futuro (con margen de reloj).
 *
 * @see https://docs.goauthentik.io/add-secure-apps/providers/oauth2/
 */
class VerificadorIdToken
{
    public function __construct(private readonly DescubridorOidc $descubridor) {}

    /**
     * @return array{
     *     sub: string,
     *     correo: ?string,
     *     correo_verificado: bool,
     *     nombre: ?string,
     *     usuario_preferido: ?string,
     *     grupos: list<string>
     * }
     */
    public function verificar(string $idToken, ?string $nonceEsperado): array
    {
        $partes = explode('.', $idToken);

        if (count($partes) !== 3) {
            throw new RuntimeException('El token de identificacion no tiene el formato de un JWT.');
        }

        [$cabeceraCruda, $cargaCruda, $firmaCruda] = $partes;

        if ($firmaCruda === '') {
            throw new RuntimeException('El token de identificacion no viene firmado.');
        }

        $cabecera = $this->decodificarSegmento($cabeceraCruda);
        $algoritmoEsperado = (string) config('authentik.algoritmo', 'RS256');
        $algoritmo = $cabecera['alg'] ?? null;

        if ($algoritmo !== $algoritmoEsperado) {
            throw new RuntimeException(
                'El token de identificacion fue firmado con el algoritmo '
                . (is_string($algoritmo) ? $algoritmo : '(desconocido)') . ' y se esperaba ' . $algoritmoEsperado . '.'
            );
        }

        $kid = $cabecera['kid'] ?? null;

        if (! is_string($kid) || $kid === '') {
            throw new RuntimeException('El token de identificacion no indica que clave lo firmo (kid).');
        }

        JWT::$leeway = (int) config('authentik.leeway', 60);

        try {
            $claves = JWK::parseKeySet($this->descubridor->jwks(), $algoritmoEsperado);
        } catch (Throwable $excepcion) {
            throw new RuntimeException(
                'No se pudo leer el conjunto de claves de Authentik: ' . $excepcion->getMessage(),
                previous: $excepcion
            );
        }

        $clave = $claves[$kid] ?? null;

        if ($clave === null) {
            throw new RuntimeException(
                'La clave ' . $kid . ' que firmo el token no esta en el JWKS de Authentik. '
                . 'Puede que Authentik haya rotado sus claves: ejecute '
                . '"php artisan veterinaria:olvidar-cache-oidc".'
            );
        }

        try {
            $claims = JWT::decode($idToken, $clave);
        } catch (Throwable $excepcion) {
            throw new RuntimeException(
                'El token de identificacion no supero la verificacion: ' . $excepcion->getMessage(),
                previous: $excepcion
            );
        }

        $this->verificarEmisor($claims->iss ?? null);
        $this->verificarAudiencia($claims->aud ?? null, $claims->azp ?? null);
        $this->verificarNonce($claims->nonce ?? null, $nonceEsperado);

        $grupos = $claims->groups ?? [];
        $grupos = is_array($grupos) ? array_values(array_map('strval', $grupos)) : [];

        return [
            'sub' => (string) ($claims->sub ?? ''),
            'correo' => isset($claims->email) ? (string) $claims->email : null,
            'correo_verificado' => (bool) ($claims->email_verified ?? false),
            'nombre' => isset($claims->name) ? (string) $claims->name : null,
            'usuario_preferido' => isset($claims->preferred_username) ? (string) $claims->preferred_username : null,
            'grupos' => $grupos,
        ];
    }

    /**
     * El emisor tiene que coincidir EXACTAMENTE con el configurado: si no,
     * un token emitido por otro proveedor con la misma clave publica seria
     * aceptado.
     */
    private function verificarEmisor(mixed $emisor): void
    {
        $esperado = rtrim((string) config('authentik.issuer'), '/') . '/';

        if (! is_string($emisor) || rtrim($emisor, '/') . '/' !== $esperado) {
            throw new RuntimeException(
                'El token de identificacion no fue emitido por el Authentik configurado '
                . '(emisor recibido: ' . (is_string($emisor) ? $emisor : '(ausente)') . ').'
            );
        }
    }

    /**
     * La audiencia debe contener el identificador de cliente. `azp` (authorized
     * party) se revisa cuando viene: si el token declara mas de una audiencia,
     * `azp` tiene que decir cual es.
     */
    private function verificarAudiencia(mixed $audiencia, mixed $parteAutorizada): void
    {
        $cliente = (string) config('authentik.client_id');
        $audiencias = is_array($audiencia) ? $audiencia : [$audiencia];

        if (! in_array($cliente, array_map('strval', $audiencias), true)) {
            throw new RuntimeException('El token de identificacion no fue emitido para esta aplicacion.');
        }

        if (is_string($parteAutorizada) && $parteAutorizada !== '' && $parteAutorizada !== $cliente) {
            throw new RuntimeException('El token de identificacion declara otra aplicacion como parte autorizada.');
        }
    }

    /**
     * El `nonce` tiene que ser el que esta en la sesion de ESTA peticion: es
     * lo que impide que alguien reutilice un token capturado.
     */
    private function verificarNonce(mixed $nonce, ?string $esperado): void
    {
        if ($esperado === null || $esperado === '') {
            throw new RuntimeException('La peticion no tiene nonce de sesion: no se puede verificar el token.');
        }

        if (! is_string($nonce) || ! hash_equals($esperado, $nonce)) {
            throw new RuntimeException('El nonce del token no coincide con el de esta sesion.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodificarSegmento(string $segmento): array
    {
        $decodificado = base64_decode(strtr($segmento, '-_', '+/'), true);

        if ($decodificado === false) {
            throw new RuntimeException('El token de identificacion tiene un segmento ilegible.');
        }

        $datos = json_decode($decodificado, true);

        if (! is_array($datos)) {
            throw new RuntimeException('El token de identificacion tiene una cabecera ilegible.');
        }

        return $datos;
    }
}