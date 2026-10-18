<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Como se pago una venta de mostrador.
 *
 * `Efectivo` y `Transferencia` cubren el mostrador chileno pequeno. `Tarjeta`
 * se acepta como **declaracion del pago**, no como integracion con una
 * pasarela: el sistema no se comunica con ningun terminal ni con la
 * transbank. Ver docs/09.
 */
enum MedioPago: string
{
    case Efectivo = 'efectivo';
    case Tarjeta = 'tarjeta';
    case Transferencia = 'transferencia';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::Tarjeta => 'Tarjeta (declarado)',
            self::Transferencia => 'Transferencia',
        };
    }
}
