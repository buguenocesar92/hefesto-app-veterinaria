<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Enums\RolClinico;
use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Usuarios de la clinica.
 *
 * LO QUE ESTA CLASE NO HACE, Y ES LO IMPORTANTE
 * ----------------------------------------------
 *
 * **NO CREA CUENTAS CON CONTRASENA.** La identidad la emite Authentik por
 * OpenID Connect. Aqui solo se vincula una cuenta de esta base con el correo
 * que Authentik ya valido.
 *
 * Y **no se puede crear un administrador por esta via**: el primer
 * administrador lo deja el despliegue (ver docs/04), porque un administrador
 * que se puede crear desde la interfaz es un administrador que se puede
 * crear desde internet.
 *
 * EL ROL SE RESUELVE EN CADA INGRESO
 * ----------------------------------
 * Esta columna se escribe a mano, pero al entrar se SOBREESCRIBE con el rol
 * que dice Authentik. Escribiarla aqui sirve para lo que ocurre antes del
 * primer ingreso, no para concederse permisos a uno mismo: si alguien se
 * cambia el rol a administrador y luego entra, Authentik lo devuelve a lo que
 * realmente es. Ver App\Services\Auth\ResolutorDeSesion.
 */
class UsuarioController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'usuarios' => Usuario::query()
                ->with('profesional:id,nombre')
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Usuario $u): array => [
                    'id' => $u->id,
                    'nombre' => $u->nombre,
                    'correo' => $u->correo,
                    'rol' => $u->rol->value,
                    'rol_etiqueta' => $u->rol->etiqueta(),
                    'profesional' => $u->profesional?->nombre,
                    'activo' => $u->activo,
                    'ultimo_ingreso_en' => $u->ultimo_ingreso_en?->toDateTimeString(),
                    'capacidades' => $u->capacidades(),
                ])->all(),
        ]);
    }

    public function show(Usuario $usuario): JsonResponse
    {
        return response()->json(['usuario' => [
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->correo,
            'rol' => $usuario->rol->value,
            'rol_etiqueta' => $usuario->rol->etiqueta(),
            'activo' => $usuario->activo,
            'capacidades' => $usuario->capacidades(),
        ]]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'correo' => ['required', 'email', 'max:160'],
            'rol' => ['required', 'string', 'in:' . implode(',', array_column(RolClinico::cases(), 'value'))],
            'profesional_id' => ['nullable', 'uuid'],
        ], [
            'correo.required' => 'Indique el correo: es con el que Authentik vincula la cuenta.',
            'rol.required' => 'Indique el rol.',
        ]);

        if ($datos['rol'] === RolClinico::Profesional->value && empty($datos['profesional_id'])) {
            return $this->error('Un usuario profesional necesita un profesional asociado: es quien puede firmar prescripciones.');
        }

        $existente = Usuario::query()
            ->where('correo', mb_strtolower($datos['correo']))
            ->exists();

        if ($existente) {
            return $this->error('Ya existe un usuario con ese correo en esta clinica.');
        }

        $usuario = Usuario::query()->create([
            'tenant_id' => $peticion->user()->tenant_id,
            'nombre' => $datos['nombre'],
            'correo' => mb_strtolower($datos['correo']),
            'rol' => $datos['rol'],
            'profesional_id' => $datos['profesional_id'] ?? null,
            'activo' => true,
        ]);

        return response()->json([
            'mensaje' => 'Usuario creado. Para entrar tiene que authenticarse en Authentik.',
            'usuario' => ['id' => $usuario->id],
            'nota' => 'No hay contrasena: la identidad la emite Authentik. La persona debe entrar por OIDC.',
        ], 201);
    }

    public function update(Request $peticion, Usuario $usuario): JsonResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'rol' => ['sometimes', 'required', 'string', 'in:' . implode(',', array_column(RolClinico::cases(), 'value'))],
            'profesional_id' => ['nullable', 'uuid'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $usuario->fill($datos)->save();

        return response()->json([
            'mensaje' => 'Usuario actualizado.',
            'nota' => 'El rol se vuelve a leer de Authentik en el siguiente ingreso. Cambiarlo aca no concede permisos.',
        ]);
    }
}
