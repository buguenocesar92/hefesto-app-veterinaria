<?php

declare(strict_types=1);

namespace Tests\Feature\Permisos;

use App\Models\AccesoFicha;
use LogicException;
use Tests\TestCase;

/**
 * QUE LA BITACORA DE ACCESOS NO SE PUEDA TOCAR DESPUES.
 *
 * POR QUE ESTO ES UNA PRUEBA Y NO UN COMENTARIO
 * ----------------------------------------------
 * Una bitacora que se puede editar no es una bitacora. La restriccion esta en
 * el modelo (lanza `LogicException`), y sin esta prueba se podria "simplificar"
 * el modelo en una refactorizacion y nadie se enteraria hasta que un registro
 * hubiera desaparecido.
 */
class BitacoraInmutableTest extends TestCase
{
    public function test_un_registro_no_se_puede_modificar(): void
    {
        [$profesional] = $this->crearClinicaConProfesional();
        [, $mascota] = $this->crearTutorYMascota($profesional);

        $registro = $this->crearRegistro($profesional, $mascota->id);

        $this->expectException(LogicException::class);

        $registro->accion = 'permitido';
        $registro->save();
    }

    public function test_un_registro_no_se_puede_borrar(): void
    {
        [$profesional] = $this->crearClinicaConProfesional();
        [, $mascota] = $this->crearTutorYMascota($profesional);

        $registro = $this->crearRegistro($profesional, $mascota->id);

        $this->expectException(LogicException::class);

        $registro->delete();
    }

    private function crearRegistro($usuario, string $mascotaId): AccesoFicha
    {
        return $this->dentroDe($usuario, static fn (): AccesoFicha => AccesoFicha::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'usuario_id' => $usuario->id,
            'mascota_id' => $mascotaId,
            'accion' => 'permitido',
            'contexto' => 'prueba',
        ]));
    }
}
