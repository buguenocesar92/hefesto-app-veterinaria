<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Usuario;
use App\Services\Auth\AlmacenDeSesion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase;

/**
 * Sondas de lo que la suite original no ejercita: el camino de una peticion
 * real (ingreso, sesion en base de datos, navegador sin sesion).
 *
 * Cada prueba afirma el comportamiento CORRECTO. Las que hoy fallan estan en
 * tests/fallos-conocidos.txt y descritas en docs/ESTADO-REAL.md: no se
 * arreglan en el plan de arranque, se dejan reproducidas.
 *
 * No tocan la base de datos (heredan de la clase base de Laravel).
 */
class DefectosDeArranqueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
    }

    /**
     * AuthController::callback hace `Auth::guard('web')->login($usuario)`, y el
     * guard de sesion solo acepta un Authenticatable.
     */
    public function test_el_modelo_usuario_sirve_para_iniciar_sesion(): void
    {
        $this->assertInstanceOf(Authenticatable::class, new Usuario());
    }

    /**
     * Con SESSION_DRIVER=database (el valor de .env.example) el gestor de
     * sesiones tiene que poder construir el manejador propio.
     */
    public function test_el_manejador_de_sesion_database_se_puede_construir(): void
    {
        config(['session.driver' => 'database']);

        $this->assertInstanceOf(
            AlmacenDeSesion::class,
            $this->app->make('session')->driver('database')->getHandler(),
        );
    }

    /**
     * Un navegador sin sesion (peticion sin `Accept: application/json`) no
     * puede recibir un 500.
     */
    public function test_una_ruta_protegida_sin_sesion_no_responde_500_a_un_navegador(): void
    {
        $respuesta = $this->get('/api/v1/tutores');

        $this->assertContains($respuesta->getStatusCode(), [302, 401]);
    }
}
