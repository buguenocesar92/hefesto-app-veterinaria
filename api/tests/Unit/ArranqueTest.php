<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Mascota;
use App\Policies\MascotaPolicy;
use App\Providers\AuthServiceProvider;
use App\Providers\SessionServiceProvider;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * La aplicacion arranca: el esqueleto existe, los proveedores propios estan
 * registrados y el enrutador responde.
 *
 * Hereda de la clase base de Laravel y NO de Tests\TestCase a proposito: esta
 * prueba no toca la base de datos, asi que corre tambien donde no hay
 * PostgreSQL. Es la primera que hay que mirar cuando nada funciona.
 */
class ArranqueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clave efimera: el grupo `web` cifra cookies y sin clave responde 500.
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
    }

    public function test_los_proveedores_propios_estan_registrados(): void
    {
        $this->assertNotEmpty($this->app->getProviders(AuthServiceProvider::class));
        $this->assertNotEmpty($this->app->getProviders(SessionServiceProvider::class));
    }

    public function test_las_abilities_y_policies_quedan_definidas(): void
    {
        foreach ([
            'ver-ficha-clinica',
            'escribir-ficha-clinica',
            'aplicar-vacuna',
            'ver-calendario-vacunas',
            'prescribir',
        ] as $ability) {
            $this->assertTrue(Gate::has($ability), 'Falta la ability ' . $ability);
        }

        $this->assertInstanceOf(MascotaPolicy::class, Gate::getPolicyFor(Mascota::class));
    }

    public function test_las_rutas_de_la_api_estan_registradas(): void
    {
        $deLaApi = 0;

        foreach (Route::getRoutes() as $ruta) {
            if (str_starts_with($ruta->uri(), 'api/v1/')) {
                $deLaApi++;
            }
        }

        $this->assertGreaterThanOrEqual(55, $deLaApi);

        foreach ([
            'api.v1.auth.login',
            'api.v1.tutores.index',
            'api.v1.mascotas.historial',
            'api.v1.mostrador.ventas.store',
            'api.v1.admin.auditoria',
        ] as $nombre) {
            $this->assertTrue(Route::has($nombre), 'Falta la ruta ' . $nombre);
        }
    }

    public function test_la_ruta_de_salud_responde_200(): void
    {
        $this->get('/api/v1/salud')->assertOk();
    }

    public function test_una_ruta_protegida_sin_sesion_responde_401_en_json(): void
    {
        $this->getJson('/api/v1/tutores')->assertUnauthorized();
    }
}
