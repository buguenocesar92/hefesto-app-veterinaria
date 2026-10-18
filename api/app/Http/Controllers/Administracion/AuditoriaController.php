<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Enums\AccionFicha;
use App\Http\Controllers\Controller;
use App\Models\AccesoFicha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bitacora de accesos a la ficha clinica.
 *
 * Solo administracion. Y con una lectura que conviene hacer con cuidado: esta
 * tabla dice QUIEN ABRIO QUE HISTORIA. Es la herramienta para revisar accesos
 * indebidos, y por lo mismo hay que tratarla como dato sensible.
 *
 * QUE SE REGISTRA
 * ---------------
 * Los accesos **permitidos y denegados**. Un intento denegado es informacion:
 * puede ser un error de permisos, un profesional trabajando con cuentas de otra
 * clinica, o alguien probando. Ver App\Services\Ficha\BitacoraFichas.
 *
 * POR QUE LA CONSULTA VA CON CONTEXTO
 * -----------------------------------
 * Un registro suelto dice poco: "el usuario X entro a la ficha Y". Importa
 * cuando ademas se ve de donde venia y si fue permitido o denegado. Por eso
 * la respuesta trae la fila completa y no solo un conteo.
 */
class AuditoriaController extends Controller
{
    public function __invoke(Request $peticion): JsonResponse
    {
        $registros = AccesoFicha::query()
            ->when($peticion->query('accion'), fn ($q, $v) => $q->where('accion', $v))
            ->when($peticion->query('usuario_id'), fn ($q, $v) => $q->where('usuario_id', $v))
            ->when($peticion->query('mascota_id'), fn ($q, $v) => $q->where('mascota_id', $v))
            ->with([
                'usuario:id,nombre,correo,rol',
                'mascota:id,nombre',
            ])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'registros' => $registros->map(static fn (AccesoFicha $r): array => [
                'id' => $r->id,
                'created_at' => $r->created_at?->toDateTimeString(),
                'accion' => $r->accion->value,
                'motivo_denial' => $r->motivo_denial,
                'usuario' => $r->usuario?->nombre,
                'usuario_correo' => $r->usuario?->correo,
                'rol' => $r->usuario?->rol->value,
                'mascota' => $r->mascota?->nombre,
                'direccion_ip' => $r->direccion_ip,
                'user_agent' => $r->user_agent,
                'contexto' => $r->contexto,
            ])->all(),

            'denegados' => $registros
                ->where('accion', AccionFicha::Denegado)
                ->count(),

            'aviso' => 'Esta tabla registra quien abrio que ficha. Trate la como dato sensible.',
        ]);
    }
}
