<?php

declare(strict_types=1);

use App\Console\Commands\OlvidarCacheOidcCommand;
use App\Console\Commands\VerificarAislamientoCommand;
use Illuminate\Support\Facades\Schedule;

/*
|-------------------------------------------------------------------------------
| Comandos de consola
|-------------------------------------------------------------------------------
|
| `clinica:verificar-aislamiento` es el comando que hay que correr en el
| servidor antes de recibir animales reales. Comprueba que la RLS esta puesta y
| que funciona, y falla si el rol de la base tiene BYPASSRLS.
|
| Ver docs/03.
|
*/

Schedule::command(OlvidarCacheOidcCommand::class)->dailyAt('04:10');

/*
 * Tarea de mantenimiento declarada para que quede constancia de que existe el
 * gancho, aunque todavia no haga nada.
 *
 * LO QUE FALTA: purgar los accesos a ficha clinica mas antiguos que
 * `VETERINARIA_RETENCION_BITACORA_DIAS`. **NO ESTA IMPLEMENTADO.** Ver docs/10.
 */
