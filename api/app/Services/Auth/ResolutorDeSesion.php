<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\RolClinico;
use App\Models\Clinica;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Traduce la identidad de Authentik a una sesion de VeterinariaHefesto.
 *
 * Tres preguntas, en este orden:
 *
 *  1. De que clinica es? Se deduce del equipo de Authentik que declara la
 *     clinica (config `authentik.grupo_por_clinica`). Sin clinica no hay
 *     tenant y no hay datos: se corta el acceso con 403.
 *  2. Que rol tiene? Se deduce de los equipos de rol (config
 *     `authentik.grupos_por_rol`). El orden del mapa manda: gana el primero
 *     que coincide.
 *  3. Existe la cuenta? Si existe, se ACTUALIZA el rol con el que dice
 *     Authentik. Si no existe, se crea.
 *
 * POR QUE EL ROL SE RESUELVE EN CADA INGRESO
 * -------------------------------------------
 * Si a alguien le quitan el equipo de profesionales en Authentik, en esta
 * aplicacion pierde el acceso en la sesion siguiente. La alternativa (guardar
 * el rol para siempre) deja ventanas de acceso que nadie cerraba.
 *
 * NO SE CREAN CUENTAS A VOLUNTAD
 * ------------------------------
 * Sin equipo de clinica, no hay ingreso. Y no se crea un administrador: esa
 * cuenta la deja el despliegue. Ver docs/03.
 */
class ResolutorDeSesion
{
    /**
     * @param  array<string, string>  $grupos  equipos de Authentik.
     * @param  array{
     *     sub: string,
     *     correo: ?string,
     *     correo_verificado: bool,
     *     nombre: ?string,
     *     usuario_preferido: ?string,
     *     grupos: list<string>
     * }  $claims
     */
    public function resolver(array $claims, array $grupos): Usuario
    {
        $clinica = $this->resolverClinica($grupos);
        $rol = $this->resolverRol($grupos);

        $correo = $this->correoDe($claims, $grupos);

        return $this->vincularCuenta($clinica, $correo, $rol, $claims['nombre']);
    }

    /**
     * @param  list<string>  $grupos
     */
    private function resolverClinica(array $grupos): Clinica
    {
        $equipoClinica = config('authentik.grupo_por_clinica');

        if (! is_string($equipoClinica) || $equipoClinica === '') {
            throw new RuntimeException(
                'No hay ningun prefijo de equipo de clinica configurado (AUTHENTIK_GRUPO_POR_CLINICA). '
                . 'Sin el no se puede saber de que clinica es la persona que entra. Ver docs/05.'
            );
        }

        foreach ($grupos as $grupo) {
            $correlacion = $this->correlacionClinica($grupo);

            if ($correlacion === null) {
                continue;
            }

            $clinica = Clinica::query()
                ->where('correlacion_identidad', $correlacion)
                ->first();

            if ($clinica === null) {
                throw new RuntimeException(
                    'La persona pertenece al equipo de Authentik de una clinica que no existe en esta instalacion.'
                );
            }

            if (! $clinica->activo) {
                throw new RuntimeException('La clinica esta desactivada.');
            }

            return $clinica;
        }

        throw new RuntimeException('La cuenta no pertenece a ninguna clinica habilitada.');
    }

    /**
     * La correlacion viaja en el nombre del equipo de Authentik, con el
     * formato `<prefijo>:<correlacion>`, por ejemplo
     * `VeterinariaHefesto:veterinaria-demo`. Ver docs/04.
     */
    private function correlacionClinica(string $grupo): ?string
    {
        $prefijo = (string) config('authentik.grupo_por_clinica', 'VeterinariaHefesto');
        $prefijo = str_ends_with($prefijo, ':') ? $prefijo : $prefijo . ':';

        if (! str_starts_with($grupo, $prefijo)) {
            return null;
        }

        $correlacion = trim(substr($grupo, strlen($prefijo)));

        return $correlacion === '' ? null : $correlacion;
    }

    /**
     * @param  list<string>  $grupos
     */
    private function resolverRol(array $grupos): RolClinico
    {
        $mapa = config('authentik.grupos_por_rol', []);

        if (! is_array($mapa) || $mapa === []) {
            throw new RuntimeException(
                'No hay ningun equipo de rol configurado en config/authentik.php. '
                . 'Sin el, cualquier persona que entre tendria el mismo rol. Ver docs/05.'
            );
        }

        $normalizados = array_map(
            static fn (string $grupo): string => mb_strtolower(trim($grupo)),
            $grupos
        );

        foreach ($mapa as $equipo => $rol) {
            if (in_array(mb_strtolower(trim((string) $equipo)), $normalizados, true)) {
                $caso = RolClinico::tryFrom((string) $rol);

                if ($caso === null) {
                    throw new RuntimeException('El rol configurado "' . $rol . '" no es un rol valido.');
                }

                return $caso;
            }
        }

        throw new RuntimeException('La persona no pertenece a ningun equipo de rol de VeterinariaHefesto.');
    }

    /**
     * @param  array{sub: string, correo: ?string, correo_verificado: bool, nombre: ?string, usuario_preferido: ?string, grupos: list<string>}  $claims
     * @param  list<string>  $grupos
     */
    private function correoDe(array $claims, array $grupos): string
    {
        $correo = $claims['correo'] ?? null;

        if (is_string($correo) && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false) {
            return mb_strtolower($correo);
        }

        if (in_array('email', $grupos, true)) {
            throw new RuntimeException('El equipo de Authentik declara correo, pero el token no lo trae.');
        }

        throw new RuntimeException(
            'El token de identificacion no trae un correo valido. '
            . 'Sin correo no hay forma de vincular la cuenta con la tabla usuarios.'
        );
    }

    private function vincularCuenta(Clinica $clinica, string $correo, RolClinico $rol, ?string $nombre): Usuario
    {
        return DB::transaction(function () use ($clinica, $correo, $rol, $nombre): Usuario {
            $usuario = Usuario::query()
                ->where('tenant_id', $clinica->id)
                ->where('correo', $correo)
                ->first();

            if ($usuario === null) {
                return Usuario::query()->create([
                    'tenant_id' => $clinica->id,
                    'nombre' => $nombre ?: $correo,
                    'correo' => $correo,
                    'rol' => $rol,
                    'activo' => true,
                ]);
            }

            // El rol se actualiza en cada ingreso; el nombre solo si Authentik
            // lo trae, para no borrar un nombre que la clinica corrigio.
            $usuario->rol = $rol;

            if ($nombre !== null && $nombre !== '') {
                $usuario->nombre = $nombre;
            }

            $usuario->ultimo_ingreso_en = now();

            $usuario->save();

            return $usuario;
        });
    }
}
