<?php

declare(strict_types=1);

namespace Tests\Unit;

use Dotenv\Parser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * `api/.env.example` es lo que docs/04 manda copiar a `api/.env`. Si phpdotenv
 * no lo puede leer, ningun comando de artisan carga (tampoco key:generate).
 */
class EjemploDeEntornoSeCargaTest extends TestCase
{
    public function test_el_ejemplo_de_entorno_se_puede_cargar(): void
    {
        $ruta = dirname(__DIR__, 2) . '/.env.example';
        $contenido = file_get_contents($ruta);
        $this->assertIsString($contenido, "No se pudo leer {$ruta}.");

        $valores = [];
        foreach ((new Parser())->parse($contenido) as $entrada) {
            $valor = $entrada->getValue();
            $valores[$entrada->getName()] = $valor->isDefined() ? $valor->get()->getChars() : null;
        }

        $this->assertArrayHasKey('AUTHENTIK_SCOPES', $valores);
        $this->assertSame('openid profile email', $valores['AUTHENTIK_SCOPES']);
    }
}
