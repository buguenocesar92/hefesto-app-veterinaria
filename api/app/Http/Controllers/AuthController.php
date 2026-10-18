<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Auth\DescubridorOidc;
use App\Services\Auth\ProveedorOidcAuthentik;
use App\Services\Auth\ResolutorDeSesion;
use App\Services\Auth\VerificadorIdToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Entrada y salida de VeterinariaHefesto.
 *
 * El flujo es el mismo del portal de autoservicio de Hefesto: authorization
 * code + PKCE contra Authentik, verificacion propia del token de
 * identificacion, y sesion propia en Laravel a partir de ahi.
 *
 * QUE HAY QUE ENTENDER DE ESTA CLASE
 * ---------------------------------
 * El token de Authentik no se usa para autorizar cada llamada. Se usa una vez,
 * al entrar, para saber QUIEN es y de QUE clinica es; despues manda la sesion
 * de Laravel. La sesion es lo que viaja al navegador como cookie firmada.
 *
 * Es la cookie de sesion la que despues lleva el contexto de clinica a la RLS
 * de PostgreSQL (ver ResolverTenant).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly DescubridorOidc $descubridor,
        private readonly VerificadorIdToken $verificador,
        private readonly ResolutorDeSesion $resolutor,
    ) {}

    /**
     * Paso 1: mandar a Authentik.
     *
     * Antes genera el `nonce` de esta sesion, que despues se comprueba contra
     * el token de identificacion. Sin el, un token capturado seria aceptable.
     */
    public function login(Request $peticion): RedirectResponse
    {
        $this->comprobarConfiguracion();

        $peticion->session()->put('oidc_nonce', Str::random(64));

        return $this->proveedor($peticion)->redirect();
    }

    /**
     * Paso 2: Authentik devuelve el codigo y se canjea por los tokens.
     */
    public function callback(Request $peticion): RedirectResponse
    {
        $this->comprobarConfiguracion();

        try {
            $proveedor = $this->proveedor($peticion);
            $usuarioBruto = $proveedor->user();

            $claims = $this->verificador->verificar(
                $proveedor->idToken(),
                $peticion->session()->pull('oidc_nonce')
            );

            $grupos = $proveedor->gruposDe($usuarioBruto->getRaw());
            $usuario = $this->resolutor->resolver($claims, $grupos);
        } catch (Throwable $e) {
            // No se registra el motivo en la respuesta: un mensaje util para
            // quien ataca es informacion gratis. El detalle va al registro del
            // servidor.
            Log::warning('Ingreso rechazado.', [
                'ruta' => $peticion->path(),
                'error' => $e->getMessage(),
                'direccion_ip' => $peticion->ip(),
            ]);

            return redirect()->route('login')->with('error', 'No se pudo completar el ingreso.');
        }

        if (! $usuario->activo) {
            return redirect()->route('login')->with('error', 'La cuenta esta desactivada.');
        }

        // Renovacion de sesion: evita el fijamiento de sesion, en el que el
        // identificador de sesion de otra persona queda como valido despues de
        // iniciar sesion. Es control CWE-384.
        $peticion->session()->invalidate();
        $peticion->session()->regenerateToken();

        Auth::guard('web')->login($usuario);

        return redirect()->intended('/agenda');
    }

    public function logout(Request $peticion): JsonResponse
    {
        Auth::guard('web')->logout();

        $peticion->session()->invalidate();
        $peticion->session()->regenerateToken();

        // Cierre en Authentik: la sesion local termina, pero sin esto la
        // persona sigue con la sesion de Authentik abierta y entra de nuevo
        // con un clic.
        $cierre = $this->descubridor->endpointCierreSesion();

        return response()->json([
            'mensaje' => 'Sesion cerrada.',
            'cierre_remoto' => $cierre,
        ]);
    }

    /**
     * Quien soy, que rol tengo y que puedo hacer.
     *
     * `capacidades` es lo que usa la interfaz para mostrar u ocultar botones.
     * NO es una defensa: cada ruta vuelve a preguntar al servidor. Ver docs/09.
     */
    public function usuario(Request $peticion): JsonResponse
    {
        $usuario = $peticion->user();

        return response()->json([
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->correo,
            'rol' => $usuario->rol->value,
            'rol_etiqueta' => $usuario->rol->etiqueta(),
            'clinica' => [
                'id' => $usuario->clinica?->id,
                'nombre' => $usuario->clinica?->nombre,
                'zona_horaria' => $usuario->clinica?->zona_horaria,
            ],
            'capacidades' => $usuario->capacidades(),
        ]);
    }

    /**
     * Proveedor de Socialite con la configuracion de Authentik.
     */
    private function proveedor(Request $peticion): ProveedorOidcAuthentik
    {
        $config = Socialite::driver('authentik')->getConfig();

        return new ProveedorOidcAuthentik(
            $peticion,
            (string) $config['client_id'],
            (string) $config['client_secret'],
            (string) $config['redirect'],
            array_values((array) config('authentik.scopes', [])),
            $this->descubridor,
        );
    }

    /**
     * Falla temprano y con un mensaje util si falta configuracion, en vez de
     * fallar con una excepcion de Socialite a mitad del flujo.
     */
    private function comprobarConfiguracion(): void
    {
        $faltantes = [];

        foreach (
            [
                'authentik.issuer',
                'authentik.client_id',
                'authentik.client_secret',
                'authentik.redirect_uri',
            ] as $clave
        ) {
            if ((string) config($clave) === '') {
                $faltantes[] = $clave;
            }
        }

        if ($faltantes !== []) {
            abort(500, 'VeterinariaHefesto no esta configurada: falta ' . implode(', ', $faltantes) . ' (ver docs/05).');
        }
    }
}
