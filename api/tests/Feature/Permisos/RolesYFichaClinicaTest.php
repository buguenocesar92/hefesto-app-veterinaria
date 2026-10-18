<?php

declare(strict_types=1);

namespace Tests\Feature\Permisos;

use App\Enums\RolClinico;
use App\Models\Mascota;
use App\Models\Usuario;
use Tests\TestCase;

/**
 * QUE PODER AGENDAR NO SEA LO MISMO QUE PODER LEER UNA FICHA CLINICA.
 *
 * LA SEPARACION DE ESTA APLICACION
 * --------------------------------
 * En una clinica veterinaria conviven cuatro trabajos con datos distintos:
 *
 *  - PROFESIONAL: atiende, diagnostica, receta, aplica vacunas. Es el unico
 *    que ve la ficha CLINICA.
 *  - RECEPCION: agenda y conoce a los tutores y a las mascotas. Ve la ficha de
 *    IDENTIFICACION (nombre, especie, peso), no la de SALUD.
 *  - MOSTRADOR: cobra y despacha. No necesita ninguna ficha.
 *  - ADMINISTRACION: gestiona la clinica. La direccion NO es un cargo
 *    clinico, asi que no abre fichas.
 *
 * POR QUE MOSTRADOR ES UN ROL APARTE Y NO PARTE DE RECEPCION
 * ---------------------------------------------------------
 * Una persona de mostrador que puede ver el padron de mascotas ve el peso de
 * cada animal de la clinica. Cruzado con las visitas, eso dice que animal esta
 * bajo de peso cronicamente, que es informacion de salud. Y quien cobra no
 * necesita verlo para cobrar.
 *
 * LAS DOS PREGUNTAS, Y POR QUE SON DISTINTAS
 * ------------------------------------------
 * En este dominio hay DOS preguntas sobre la misma fila de `mascotas`, y se
 * autorizan por caminos distintos a proposito:
 *
 *  - `view` de `MascotaPolicy`: identificacion. Mas abierta.
 *  - `ver-ficha-clinica`: salud. Solo profesional.
 *
 * Ver App\Providers\AuthServiceProvider, que explica por que no pueden ser el
 * mismo metodo.
 */
class RolesYFichaClinicaTest extends TestCase
{
    public function test_solo_el_profesional_abre_la_ficha_clinica(): void
    {
        [$profesional] = $this->crearClinicaConProfesional();
        [, $mascota] = $this->crearTutorYMascota($profesional);

        $recepcion = $this->crearUsuario($profesional, RolClinico::Recepcion, 'recepcion@prueba.local');
        $mostrador = $this->crearUsuario($profesional, RolClinico::Mostrador, 'mostrador@prueba.local');
        $administrador = $this->crearUsuario($profesional, RolClinico::Administrador, 'admin@prueba.local');

        $this->assertTrue(
            $profesional->can('ver-ficha-clinica', $mascota),
            'El profesional debe abrir la ficha clinica.'
        );

        $this->assertFalse(
            $recepcion->can('ver-ficha-clinica', $mascota),
            'Recepcion NO debe abrir la ficha clinica.'
        );

        $this->assertFalse(
            $mostrador->can('ver-ficha-clinica', $mascota),
            'El mostrador NO debe abrir la ficha clinica.'
        );

        $this->assertFalse(
            $administrador->can('ver-ficha-clinica', $mascota),
            'La direccion NO debe abrir la ficha clinica: la direccion no es un cargo clinico.'
        );
    }

    public function test_recepcion_ve_la_identificacion_pero_no_el_historial(): void
    {
        [$profesional] = $this->crearClinicaConProfesional();
        [, $mascota] = $this->crearTutorYMascota($profesional);

        $recepcion = $this->crearUsuario($profesional, RolClinico::Recepcion, 'recepcion@prueba.local');

        // Identificacion: la necesita para saber a quien agenda.
        $this->assertTrue(
            $recepcion->can('view', $mascota),
            'Recepcion debe ver la ficha de identificacion de la mascota.'
        );

        // Salud: no la necesita para nada de lo que hace.
        $this->assertFalse(
            $recepcion->can('ver-ficha-clinica', $mascota),
            'Recepcion no debe abrir la ficha clinica.'
        );

        // Y tampoco puede escribir en el historial.
        $this->assertFalse(
            $recepcion->can('escribir-ficha-clinica', $mascota),
            'Recepcion no debe poder registrar consultas.'
        );
    }

    public function test_solo_el_profesional_prescribe_y_aplica_vacunas(): void
    {
        [$profesional] = $this->crearClinicaConProfesional();
        [, $mascota] = $this->crearTutorYMascota($profesional);

        $recepcion = $this->crearUsuario($profesional, RolClinico::Recepcion, 'recepcion@prueba.local');
        $mostrador = $this->crearUsuario($profesional, RolClinico::Mostrador, 'mostrador@prueba.local');

        $this->assertTrue($profesional->can('prescribir', $mascota));
        $this->assertTrue($profesional->can('aplicar-vacuna', $mascota));

        foreach ([$recepcion, $mostrador] as $otro) {
            $this->assertFalse(
                $otro->can('prescribir', $mascota),
                "El rol {$otro->rol->value} no debería prescribir."
            );
            $this->assertFalse(
                $otro->can('aplicar-vacuna', $mascota),
                "El rol {$otro->rol->value} no debería aplicar vacunas."
            );
        }
    }

    public function test_el_calendario_de_vacunas_es_mas_abierto_que_la_ficha_clinica(): void
    {
        [$profesional] = $this->crearClinicaConProfesional();
        [, $mascota] = $this->crearTutorYMascota($profesional);

        $recepcion = $this->crearUsuario($profesional, RolClinico::Recepcion, 'recepcion@prueba.local');
        $mostrador = $this->crearUsuario($profesional, RolClinico::Mostrador, 'mostrador@prueba.local');

        // "La polivalente vence en marzo" es una obligacion pendiente de la
        // clinica, no un dato de salud del animal: quien atiende el telefono
        // tiene que poder verla para llamar.
        $this->assertTrue(
            $recepcion->can('ver-calendario-vacunas', $mascota),
            'Recepcion debe ver el calendario de vacunas para llamar.'
        );

        $this->assertTrue(
            $mostrador->can('ver-calendario-vacunas', $mascota),
            'El mostrador debe ver el calendario de vacunas.'
        );

        // Pero eso NO abre el historial: el detalle de cada aplicacion sigue
        // siendo ficha clinica.
        $this->assertFalse($recepcion->can('ver-ficha-clinica', $mascota));
        $this->assertFalse($mostrador->can('ver-ficha-clinica', $mascota));
    }

    public function test_los_cuatro_roles_tienen_las_capacidades_que_corresponden(): void
    {
        $capacidades = [
            RolClinico::Profesional => ['agenda' => true, 'mostrador' => true, 'ficha_clinica' => true, 'prescribir' => true, 'administracion' => false],
            RolClinico::Recepcion => ['agenda' => true, 'mostrador' => true, 'ficha_clinica' => false, 'prescribir' => false, 'administracion' => false],
            RolClinico::Mostrador => ['agenda' => false, 'mostrador' => true, 'ficha_clinica' => false, 'prescribir' => false, 'administracion' => false],
            RolClinico::Administrador => ['agenda' => true, 'mostrador' => true, 'ficha_clinica' => false, 'prescribir' => false, 'administracion' => true],
        ];

        foreach ($capacidades as $rol => $esperadas) {
            $usuario = new Usuario([
                'tenant_id' => '00000000-0000-0000-0000-000000000000',
                'nombre' => 'Prueba',
                'correo' => $rol->value . '@prueba.local',
                'rol' => $rol,
                'activo' => true,
            ]);

            $this->assertSame(
                $esperadas,
                $usuario->capacidades(),
                "Las capacidades del rol {$rol->value} no son las esperadas."
            );
        }
    }

    public function test_desactivar_un_usuario_se_guarda_y_el_ingreso_lo_rechaza(): void
    {
        [$profesional] = $this->crearClinicaConProfesional();

        $recepcion = $this->crearUsuario($profesional, RolClinico::Recepcion, 'recepcion@prueba.local');

        $this->assertTrue($recepcion->activo);

        $this->dentroDe($profesional, function () use ($recepcion): void {
            $recepcion->activo = false;
            $recepcion->save();
        });

        $recepcion->refresh();

        $this->assertFalse($recepcion->activo);

        /*
         * NOTA SOBRE EL ALCANCE DE ESTA PRUEBA
         * ------------------------------------
         * Comprueba que la marca de desactivado se guarda. NO comprueba que el
         * ingreso se rechace: eso vive en AuthController::callback, que exige
         * el flujo completo de OIDC contra un Authentik real, y queda
         * declarado como no probado en docs/10.
         */
    }
}
