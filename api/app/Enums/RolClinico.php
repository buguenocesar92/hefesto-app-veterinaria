<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol de la persona que trabaja en la clinica.
 *
 * CUATRO ROLES, Y POR QUE NO TRES
 * -------------------------------
 * En una clinica veterinaria hay un trabajo que no existe en una clinica
 * humana: la venta de productos en mostrador. Esa persona necesita el precio
 * y el stock, y **no necesita ver ninguna ficha clinica**: si una receta dice
 * "se aplica 1 ml cada 12 horas" y el mostrador lo ve, ya esta filtrando un
 * dato de salud de un animal que vive en la casa de alguien.
 *
 * Por eso el mostrador es un rol aparte de recepcion. Recepcion agenda y
 * conoce a los tutores; el mostrador cobra. Mezclarlos obligaria a elegir
 * entre abrir la agenda a quien cobra, o abrir el historico a quien agenda.
 *
 * IDENTIFICADORES EN ASCII
 * ------------------------
 * Los nombres de esta clase y de los casos NO llevan tilde a proposito. Un
 * identificador PHP acentuado se puede escribir de dos maneras distintas
 * (con y sin tilde) y PHP las trata como clases diferentes: es un error fatal
 * en ejecucion, no un aviso. Ver
 * herramientas/verificar_referencias.py, que revisa esto.
 *
 * Las etiquetas que ve una persona SI llevan tilde.
 */
enum RolClinico: string
{
    /** Direccion de la clinica. Administra, pero no es un cargo clinico. */
    case Administrador = 'administrador';

    /** Veterinario (o estudiante avanzado). Es el unico que abre la ficha. */
    case Profesional = 'profesional';

    /** Agenda, tutores y datos de identificacion de las mascotas. */
    case Recepcion = 'recepcion';

    /** Venta de productos en mostrador. Sin ficha clinica. */
    case Mostrador = 'mostrador';

    /** Etiqueta que se muestra en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Administrador => 'Administracion',
            self::Profesional => 'Profesional veterinario',
            self::Recepcion => 'Recepcion',
            self::Mostrador => 'Mostrador',
        };
    }

    /** El rol que puede firmar una prescripcion. */
    public function puedePrescribir(): bool
    {
        return $this === self::Profesional;
    }

    /**
     * El rol que puede leer la ficha clinica de una mascota.
     *
     * El administrador NO esta. La direccion de la clinica no es un cargo
     * clinico, y abrirle la historia completa de todos los animales por
     * cargo es exactamente lo que la Ley 19.628 pide acotar.
     */
    public function puedeLeerFichaClinica(): bool
    {
        return $this === self::Profesional;
    }

    /** Agenda clinica: quien agenda y la ve. */
    public function puedeUsarAgenda(): bool
    {
        return $this === self::Profesional
            || $this === self::Recepcion
            || $this === self::Administrador;
    }

    /** Mostrador: cobra, descuenta stock y ve productos. */
    public function puedeUsarMostrador(): bool
    {
        return $this === self::Mostrador
            || $this === self::Administrador
            || $this === self::Recepcion;
    }

    /**
     * Administracion de la clinica: profesionales, servicios, precios,
     * usuarios, esquemas de vacunacion e inventario.
     */
    public function administra(): bool
    {
        return $this === self::Administrador;
    }

    /** Todos los roles, en el orden en que se resuelven en Authentik. */
    public static function paraAuthentik(): array
    {
        return [
            'Administradores de Veterinaria' => self::Administrador->value,
            'Profesionales de Veterinaria' => self::Profesional->value,
            'Recepcion de Veterinaria' => self::Recepcion->value,
            'Mostrador de Veterinaria' => self::Mostrador->value,
        ];
    }
}
