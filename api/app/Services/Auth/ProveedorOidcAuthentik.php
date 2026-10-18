<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use RuntimeException;

/**
 * Proveedor OIDC para Authentik sobre Socialite.
 *
 * MISMO CRITERIO QUE EN EL PORTAL DE AUTOSERVICIO
 * ----------------------------------------------
 * En Socialite v5.31.0 no hay un controlador OIDC generico en `src/Two`: solo
 * proveedores concretos y `AbstractProvider`. Por eso esta clase extiende
 * `AbstractProvider` e implementa el flujo de codigo de autorizacion de OIDC
 * contra los endpoints que declara el documento de descubrimiento.
 *
 * PKCE
 * ----
 * `AbstractProvider::redirect()` genera el `code_verifier`, lo deja en la
 * sesion y arma el `code_challenge` con `getCodeChallenge()`. El metodo
 * `getTokenFields()` lo vuelve a leer al canjear el codigo. Se usa S256.
 *
 * Flujo implementado: authorization code + PKCE. No el flujo implicito, que la
 * propia documentacion de Authentik marca como legado.
 *
 * @see https://docs.goauthentik.io/add-secure-apps/providers/oauth2/
 */
class ProveedorOidcAuthentik extends AbstractProvider implements ProviderInterface
{
    /** @var list<string> */
    protected $scopes = ['openid', 'profile', 'email'];

    /** OIDC separa alcances con espacio (RFC 6749). */
    protected $scopeSeparator = ' ';

    private DescubridorOidc $descubridor;

    /**
     * Token de identificacion de la ultima respuesta de token.
     *
     * Hace falta porque Socialite lo descarta: en `AbstractProvider::user()`
     * la respuesta del endpoint de token pasa por `userInstance()`, y ahi
     * `User::setToken()` recibe SOLO el `access_token`. Sin recuperarlo no se
     * podrian verificar `iss`, `aud` ni `nonce` (ver VerificadorIdToken), que
     * son justamente las comprobaciones que hacen segura la entrada a una
     * aplicacion con datos de salud.
     */
    private ?string $idToken = null;

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        $request,
        $clientId,
        $clientSecret,
        $redirectUrl,
        array $scopes = [],
        ?DescubridorOidc $descubridor = null
    ) {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl);

        if ($scopes !== []) {
            $this->scopes = $scopes;
        }

        $this->usesPKCE = true;

        $this->descubridor = $descubridor ?? app(DescubridorOidc::class);
    }

    /**
     * URL a la que se redirige a la persona para autenticarse en Authentik.
     *
     * Se anade `nonce`, que es OBLIGATORIO en OIDC: despues se comprueba que
     * el token de identificacion corresponde a esta misma peticion y no a una
     * respuesta inyectada.
     */
    protected function getAuthUrl($state): string
    {
        $url = $this->descubridor->endpointAutorizacion();

        $campos = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'response_type' => 'code',
            'scope' => $this->formatScopes($this->getScopes(), $this->scopeSeparator),
            'state' => $state,
            'code_challenge' => $this->getCodeChallenge(),
            'code_challenge_method' => $this->getCodeChallengeMethod(),
        ];

        $nonce = request()->session()->get('oidc_nonce');

        if (is_string($nonce) && $nonce !== '') {
            $campos['nonce'] = $nonce;
        }

        return $url . '?' . http_build_query($campos, '', '&', $this->encodingType);
    }

    /** Endpoint de token, tomado del documento de descubrimiento. */
    protected function getTokenUrl(): string
    {
        return $this->descubridor->endpointToken();
    }

    /**
     * `AbstractProvider::getTokenFields()` ya envia `grant_type`, `client_id`,
     * `client_secret`, `code`, `redirect_uri` y el verificador de PKCE.
     */
    protected function getTokenFields($code): array
    {
        return array_merge(
            parent::getTokenFields($code),
            ['scope' => $this->formatScopes($this->getScopes(), $this->scopeSeparator)]
        );
    }

    /**
     * Datos del perfil leidos del endpoint `userinfo`.
     *
     * Los claims que se TIENEN que validar (emisor, audiencia, nonce,
     * expiracion) se validan aparte, en VerificadorIdToken, sobre el token de
     * identificacion.
     */
    protected function getUserByToken($token): array
    {
        $respuesta = $this->getHttpClient()->get($this->descubridor->endpointUserInfo(), [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        return json_decode((string) $respuesta->getBody(), true) ?: [];
    }

    /**
     * Traduce la respuesta de Authentik al objeto de Socialite.
     */
    protected function mapUserToObject(array $usuarioBruto): User
    {
        return (new User)->setRaw($usuarioBruto)->map([
            'id' => $usuarioBruto['sub'] ?? null,
            'nickname' => $usuarioBruto['preferred_username'] ?? null,
            'name' => $usuarioBruto['name'] ?? null,
            'email' => $usuarioBruto['email'] ?? null,
        ]);
    }

    /**
     * Conserva el `id_token` antes de que Socialite lo pierda.
     */
    protected function userInstance(array $respuesta, array $usuario): User
    {
        $idToken = $respuesta['id_token'] ?? null;

        $this->idToken = is_string($idToken) && $idToken !== '' ? $idToken : null;

        return parent::userInstance($respuesta, $usuario);
    }

    /**
     * Token de identificacion de la ultima operacion.
     *
     * @throws RuntimeException si Authentik no devolvio `id_token`: es un fallo
     *         de configuracion del proveedor, no algo que deba tolerarse.
     */
    public function idToken(): string
    {
        if ($this->idToken === null) {
            throw new RuntimeException(
                'Authentik no devolvio un token de identificacion (id_token). '
                . 'Revise que el alcance "openid" este habilitado en el proveedor OAuth2 de Authentik.'
            );
        }

        return $this->idToken;
    }

    /**
     * Claim `groups` de Authentik, que no existe en el objeto de Socialite.
     *
     * Es de donde salen el rol y la clinica de la persona. Llega con el
     * alcance `profile` segun la documentacion oficial.
     *
     * @return list<string>
     */
    public function gruposDe(array $usuarioBruto): array
    {
        $grupos = $usuarioBruto['groups'] ?? [];

        return is_array($grupos) ? array_values(array_map('strval', $grupos)) : [];
    }

    public function emisorEsperado(): string
    {
        return rtrim((string) config('authentik.issuer'), '/') . '/';
    }
}