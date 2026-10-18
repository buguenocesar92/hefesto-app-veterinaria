<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una aplicacion de vacuna ya registrada.
 *
 * SOLO DOS ESTADOS, A PROPOSITO
 * -----------------------------
 * "Vigente", "por vencer" y "atrasada" NO viven aqui. Son consequences del
 * calendario, no hechos de la aplicacion:
 *
 *   - Que se aplico o no se aplico: ES UN HECHO. Ocurrio una vez y no
 *     cambia.
 *   - Si esa dosis sigue vigente: SE DERIVA de la fecha de la proxima dosis.
 *
 * Confundir las dos cosas produce el error clasico de estos sistemas: se
 * guarda un `estado = 'vigente'` el dia de la aplicacion, nadie lo actualiza
 * nunca, y dos anos despues el sistema sigue saying que la vacuna esta al
 * dia. Aqui el estado se guarda solo cuando es un hecho, y la vigencia se
 * calcula en cada lectura. Ver App\Services\Vacunas\CalculadorDeVigencia.
 *
 * Ver la analogia con `EstadoCita`: mismo criterio, misma razon.
 */
enum EstadoVacuna: string
{
    case Aplicada = 'aplicada';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Aplicada => 'Aplicada',
            self::Anulada => 'Anulada',
        };
    }

    /** Una aplicacion anulada no cuenta para el historial ni para el calendario. */
    public function cuenta(): bool
    {
        return $this === self::Aplicada;
    }
}
