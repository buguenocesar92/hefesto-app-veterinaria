<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que hizo una persona con la ficha clinica de una mascota.
 *
 * Va a la bitacora `accesos_ficha`. Se registra **tambien el intento
 * denegado**: un 404 de una ficha que alguien no deberia ver es, en una
 * clinica con datos de salud, la senal que mas importa revisar.
 *
 * Es la misma razon por la que un historial medico de mascota se trata como
 * una ficha humana: identifica a una persona y a su domicilio. Ver docs/07.
 */
enum AccionFicha: string
{
    case Permitido = 'permitido';
    case Denegado = 'denegado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Permitido => 'Permitido',
            self::Denegado => 'Denegado',
        };
    }
}
