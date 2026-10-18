<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Especie de la mascota.
 *
 * POR QUE LA ESPECIE MANDA EN ESTA APLICACION
 * -------------------------------------------
 * En medicina humana la especie del paciente es practicamente constante y el
 * protocolo medico no depende de ella. En veterinaria no: **el esquema de
 * vacunacion, el intervalo entre dosis y la dosis misma dependen de la
 * especie**. Un perro y un gato de la misma edad NO comparten calendario, y
 * una terriblya de tres anos con un caballo no tiene nada que ver con una
 * gata.
 *
 * Por eso `Especie` no es un dato decorativo de la ficha: es la columna por
 * la que se busca el esquema de vacunacion aplicable.
 *
 * Los intervalos concretos NO estan escritos aqui a proposito. Viven en la
 * tabla `esquemas_vacunacion`, que la clinica configura y revisa, porque
 * dependen de la especie, de la edad, del fabricante de la vacuna y de la
 * normativa sanitaria aplicable. Ver docs/02 y docs/07.
 */
enum Especie: string
{
    case Canino = 'canino';
    case Felino = 'felino';
    case Equino = 'equino';
    case PequenosMamiferos = 'pequenos_mamiferos';
    case Ave = 'ave';
    case Reptil = 'reptil';
    case Otro = 'otro';

    /** Etiqueta para la interfaz y para los mensajes de error. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Canino => 'Canino',
            self::Felino => 'Felino',
            self::Equino => 'Equino',
            self::PequenosMamiferos => 'Pequenos mamiferos',
            self::Ave => 'Ave',
            self::Reptil => 'Reptil',
            self::Otro => 'Otro',
        };
    }

    /**
     * Si esta especie tiene un esquema de vacunacion conocido por la clinica.
     *
     * Los reptiles y el caballo no se vacunan con el mismo calendario que un
     * perro, y algunos no tienenCalendario rutinario. La respuesta NO decide
     * nada clinicamente: solo evita mostrar un formulario vacio. La decision
     * la toma siempre un profesional.
     */
    public function admiteEsquemaVacunacion(): bool
    {
        return $this !== self::Reptil;
    }
}
