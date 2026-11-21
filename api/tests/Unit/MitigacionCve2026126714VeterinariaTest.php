<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CANDADO: CVE-2026-126714 (GHSA-fm2m-fjf5-v3cp, 2026-11-19).
 *
 * QUE COMPRUEBA
 * -------------
 *   1. Que la base de la imagen use una version de PHP PARCHEADA para este
 *      CVE (las parcheadas de la serie 8.4 son 8.4.28) y que la base este
 *      FIJADA por digest: un tag flotante puede mudar sin que nadie lo note.
 *   2. Que el ini de mitigacion fije las CUATRO directivas que cierran el
 *      vector: `session.upload_progress.enabled` apagado,
 *      `session.upload_progress.cleanup` encendido,
 *      `session.serialize_handler = php_serialize` y
 *      `session.use_strict_mode = 1`.
 *   3. Que la etapa final del Dockerfile copie ese ini a `conf.d`: si no se
 *      copia, la mitigacion no llega al contenedor.
 *   4. Que NINGUN ini que se cargue DESPUES del nuestro (en `conf.d` se
 *      carga por orden alfabetico del nombre de destino) vuelva a pisar una
 *      de las cuatro directivas: el valor que cuenta es el ULTIMO que se
 *      carga, no el del archivo de la mitigacion.
 *   5. Que la sesion de la aplicacion NO use el driver `file`, que es el
 *      save handler vulnerable de ext-session.
 *
 * POR QUE NO USA LA BASE DE DATOS
 * --------------------------------
 * Solo lee ficheros: no necesita PostgreSQL, ni Docker, ni la base de pruebas.
 * Por eso vive en la suite de unidad y se puede correr con:
 *
 *     vendor/bin/phpunit --testsuite=Unit
 */
final class MitigacionCve2026126714VeterinariaTest extends TestCase
{
    /**
     * La version MINIMA de PHP parcheada de la serie 8.4 para este CVE.
     */
    private const VERSION_MINIMA = '8.4.28';

    private const SERIE = '8.4';

    private const DOCKERFILE = 'deploy/api/Dockerfile';

    private const INI = 'deploy/api/docker/php/99-security-cve-2026-126714.ini';

    /**
     * El nombre del ini YA DENTRO de `conf.d`, que es por el que se ordena
     * la carga: no por el nombre del archivo de origen.
     */
    private const INI_EN_CONF_D = '99-security-cve-2026-126714.ini';

    /**
     * Las cuatro directivas de la mitigacion, tal cual (una por linea).
     *
     * @var list<string>
     */
    private const DIRECTIVAS = [
        'session.upload_progress.enabled = Off',
        'session.upload_progress.cleanup = On',
        'session.serialize_handler = php_serialize',
        'session.use_strict_mode = 1',
    ];

    private function ruta(string $relativa): string
    {
        return dirname(__DIR__, 3).'/'.$relativa;
    }

    private function contenido(string $relativa): string
    {
        $this->assertFileExists($this->ruta($relativa), 'Falta '.$relativa.'.');

        return (string) file_get_contents($this->ruta($relativa));
    }

    /**
     * La primera coincidencia de un patron, comprobando que existe.
     *
     * @return array{0: string, 1: string}
     */
    private function primeraCoincidencia(string $patron, string $sujeto): array
    {
        $resultado = preg_match($patron, $sujeto, $coincidencias);

        $this->assertSame(
            1,
            $resultado,
            'El patron «'.$patron.'» no tiene exito: el archivo no tiene lo que el candado comprueba.',
        );

        return [$coincidencias[0] ?? '', $coincidencias[1] ?? ''];
    }

    /**
     * El texto del Dockerfile con las continuaciones de linea unidas, para que
     * un `RUN` de varias lineas se pueda comprobar con una expresion regular.
     */
    private function dockerfileUnido(): string
    {
        return (string) preg_replace('/\x5c[ \t]*\n[ \t]*/', ' ', $this->contenido(self::DOCKERFILE));
    }

    /**
     * Las lineas de la ETAPA FINAL del Dockerfile (la que produce la imagen de
     * runtime): lo que va en una etapa de dependencias no llega al contenedor.
     *
     * @return list<string>
     */
    private function lineasDeLaEtapaFinal(): array
    {
        $lineas = array_values(array_filter(
            array_map('trim', explode("\n", $this->dockerfileUnido())),
            fn (string $linea): bool => $linea !== '' && ! str_starts_with($linea, '#'),
        ));

        $ultimoFrom = 0;
        foreach ($lineas as $indice => $linea) {
            if (preg_match('/^FROM\s/i', $linea) === 1) {
                $ultimoFrom = $indice;
            }
        }

        return array_slice($lineas, $ultimoFrom);
    }

    public function test_la_base_usa_una_version_de_php_parcheada_para_este_cve(): void
    {
        $coincidencia = $this->primeraCoincidencia(
            '/^FROM\s+(?:docker\.io\/library\/)?php:(\S+)/mi',
            $this->contenido(self::DOCKERFILE),
        );
        $tag = $coincidencia[1];

        $version = $this->primeraCoincidencia('/^(\d+\.\d+\.\d+)/', $tag);

        $this->assertTrue(
            version_compare($version[1], self::VERSION_MINIMA, '>='),
            'La base «'.$tag.'» esta por debajo de la version parcheada ('.self::VERSION_MINIMA.') de la serie '.self::SERIE.' para CVE-2026-126714.',
        );
    }

    public function test_la_base_esta_fijada_por_digest(): void
    {
        $this->assertMatchesRegularExpression(
            '/^FROM\s+(?:docker\.io\/library\/)?php:\S+@sha256:[0-9a-f]{64}\s*$/mi',
            $this->contenido(self::DOCKERFILE),
            'La base no esta fijada por digest: con solo la etiqueta, una actualizacion silenciosa en produccion no es un despliegue.',
        );
    }

    public function test_el_ini_fija_las_cuatro_directivas_de_la_mitigacion(): void
    {
        $lineas = array_map(
            'trim',
            explode("\n", $this->contenido(self::INI)),
        );

        foreach (self::DIRECTIVAS as $directiva) {
            $this->assertContains(
                $directiva,
                $lineas,
                self::INI.' no fija «'.$directiva.'» tal cual (una por linea): sin esa directiva, el vector de CVE-2026-126714 queda abierto.',
            );
        }
    }

    public function test_la_etapa_final_de_la_imagen_copia_el_ini_a_conf_d(): void
    {
        $copia = array_filter(
            $this->lineasDeLaEtapaFinal(),
            fn (string $linea): bool => preg_match(
                '#^COPY\s+'.preg_quote(self::INI, '#').'\s+/usr/local/etc/php/conf\.d/[\w.-]+\.ini$#',
                $linea,
            ) === 1,
        );

        $this->assertNotEmpty(
            $copia,
            'La etapa final del Dockerfile no copia '.self::INI.' a /usr/local/etc/php/conf.d/: la mitigacion no llega al contenedor.',
        );
    }

    public function test_la_version_de_php_verificada_esta_parcheada(): void
    {
        $coincidencia = $this->primeraCoincidencia(
            '/PHP verificado:\s*(\d+\.\d+\.\d+)/',
            $this->contenido(self::INI),
        );

        $this->assertTrue(
            version_compare($coincidencia[1], self::VERSION_MINIMA, '>='),
            'La version de PHP verificada ('.$coincidencia[1].') esta por debajo de la version parcheada ('.self::VERSION_MINIMA.') de la serie '.self::SERIE.'.',
        );
    }

    public function test_ningun_ini_posterior_contradice_la_mitigacion(): void
    {
        // En `conf.d` los archivos se cargan por orden alfabetico de su
        // nombre de DESTINO: el ultimo que se carga gana. Por eso hay que
        // mirar los ini que van DESPUES del nuestro, no solo el nuestro.
        $copias = [];
        foreach ($this->lineasDeLaEtapaFinal() as $linea) {
            if (preg_match('#^COPY\s+(\S+)\s+/usr/local/etc/php/conf\.d/([\w.-]+\.ini)$#', $linea, $coincidencia) === 1) {
                $copias[$coincidencia[2]] = $coincidencia[1];
            }
        }

        $this->assertArrayHasKey(
            self::INI_EN_CONF_D,
            $copias,
            'La etapa final no copia '.self::INI.' a conf.d con el nombre '.self::INI_EN_CONF_D.': sin el, no se puede saber que ini se carga despues.',
        );

        ksort($copias);

        $despues = false;
        foreach ($copias as $destino => $origen) {
            if ($destino === self::INI_EN_CONF_D) {
                $despues = true;

                continue;
            }

            if ($despues === false) {
                continue;
            }

            $ruta = $this->ruta($origen);
            $this->assertFileExists($ruta, 'El Dockerfile copia «'.$origen.'» a conf.d, pero ese archivo no existe.');

            $valores = parse_ini_file($ruta, false, INI_SCANNER_TYPED);
            $this->assertIsArray($valores, $origen.' no se puede leer como ini.');

            if (array_key_exists('session.upload_progress.enabled', $valores)) {
                $this->assertFalse(
                    (bool) $valores['session.upload_progress.enabled'],
                    basename($ruta).' se carga DESPUES de la mitigacion y vuelve a encender session.upload_progress.enabled: el vector queda abierto.',
                );
            }

            if (array_key_exists('session.upload_progress.cleanup', $valores)) {
                $this->assertTrue(
                    (bool) $valores['session.upload_progress.cleanup'],
                    basename($ruta).' se carga DESPUES de la mitigacion y apaga session.upload_progress.cleanup: la sesion de upload progress se acumula en disco.',
                );
            }

            if (array_key_exists('session.serialize_handler', $valores)) {
                $this->assertSame(
                    'php_serialize',
                    $valores['session.serialize_handler'],
                    basename($ruta).' se carga DESPUES de la mitigacion y deja session.serialize_handler en «'.$valores['session.serialize_handler'].'»: se pierde el endurecimiento del formato de sesion.',
                );
            }

            if (array_key_exists('session.use_strict_mode', $valores)) {
                $this->assertSame(
                    1,
                    (int) $valores['session.use_strict_mode'],
                    basename($ruta).' se carga DESPUES de la mitigacion y desactiva session.use_strict_mode: vuelve el session fixation.',
                );
            }
        }
    }

    public function test_la_aplicacion_no_usa_el_driver_de_sesion_file(): void
    {
        // `files` es el save handler de ext-session que reescribe el archivo de
        // sesion sin truncarlo (el sujeto de este CVE). Laravel no lo usa, y
        // esta sesion vive en PostgreSQL: el candado lo deja escrito.
        $config = $this->contenido('api/config/session.php');

        $coincidencia = $this->primeraCoincidencia(
            "/'driver'\s*=>\s*env\(\s*'SESSION_DRIVER'\s*,\s*'([a-z]+)'\s*\)/",
            $config,
        );

        $this->assertNotSame(
            'file',
            $coincidencia[1],
            'La sesion de la aplicacion usa el driver «file»: es el save handler vulnerable de ext-session (CVE-2026-126714).',
        );

        $ejemplo = $this->contenido('api/.env.example');

        $this->assertDoesNotMatchRegularExpression(
            '/^SESSION_DRIVER=file\s*$/m',
            $ejemplo,
            'api/.env.example usa SESSION_DRIVER=file: es el save handler vulnerable de ext-session (CVE-2026-126714).',
        );
    }
}
