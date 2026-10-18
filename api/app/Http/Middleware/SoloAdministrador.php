<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta las rutas de administracion de la clinica.
 *
 * Aplica a: profesionales, usuarios, esquemas de vacunacion, productos y
 * precios.
 *
 * POR QUE UN MIDDLEWARE Y NO UNA POLICY
 * ------------------------------------
 * Porque aqui no hay un recurso que autorizar sino una accion. La pregunta no
 * es "este profesional, ¿puede editarse?" sino "¿esta persona es
 * administradora de esta clinica?". Un middleware responde exactamente a
 * eso y corta antes de llegar al controlador, que es lo que se quiere: no
 * tiene sentido cargar el controlador para que responda 403.
 *
 * NO SE APLICA AL CATALOGO QUE NECESITA LA AGENDA
 * ----------------------------------------------
 * Las rutas de listado de profesionales y tipos de atencion NO llevan este
 * middleware. Recepcion tiene que poder leer el catalogo para poder agendar;
 * si lo cortaran, la recepcion no podria hacer su trabajo. Crear y modificar
 * si van por aqui. Ver routes/web.php.
 */
class SoloAdministrador
{
    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = $peticion->user();

        if ($usuario === null || ! $usuario->rol->administra()) {
            abort(404, 'No encontrado.');
        }

        return $siguiente($peticion);
    }
}
