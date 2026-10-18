<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base de los controladores de la API.
 *
 * Trae `authorize()`, que es la que delega en las Policies. Casi todos los
 * controladores de este proyecto la usan, y por eso esta clase la expone.
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;

    /**
     * Respuesta de error unificada.
     *
     * El `mensaje` va en espanol y sin detalles internos: un mensaje util para
     * quien ataca es informacion gratis. El detalle va al registro del
     * servidor.
     */
    protected function error(string $mensaje, int $codigo = 422): \Illuminate\Http\JsonResponse
    {
        return response()->json(['mensaje' => $mensaje], $codigo);
    }
}
