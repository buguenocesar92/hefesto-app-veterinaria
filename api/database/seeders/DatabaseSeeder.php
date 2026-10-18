<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de la semilla.
 *
 * QUE CARGA
 * ---------
 * Una clinica de ejemplo con:
 *
 *  - cuatro usuarios, uno por rol (administracion, profesional, recepcion,
 *    mostrador);
 *  - un tutor con **DOS mascotas**: un perro y un gato. Esa parte es
 *    deliberada, no decorativa. Es el caso que separa un sistema que mantiene
 *    los historiales separados de uno que los mezcla, y permite comprobarlo a
 *    mano en datos reales.
 *  - catalogo de atenciones con el bloque de vacunacion aparte;
 *  - esquemas de vacunacion de ejemplo, **sin verificar**;
 *  - agenda con dos citas;
 *  - una venta de mostrador con el stock ya descontado.
 *
 * LO QUE LA SEMILLA NO ES
 * ----------------------
 * No es una fuente de verdad clinica. Los esquemas de vacunacion que carga
 * salen con `verificado = false` y con la fuente escrita, porque **este
 * proyecto no verifico ningun intervalo contra ninguna fuente oficial**.
 * Ver docs/02 y docs/07.
 *
 * COMO CORRERLA
 * -------------
 *     php artisan migrate --seed
 *
 * En una instalacion con datos reales NO se corre. Se crea una base de datos
 * aparte para desarrollo. Ver docs/04.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ClinicaDeEjemploSeeder::class);
    }
}
