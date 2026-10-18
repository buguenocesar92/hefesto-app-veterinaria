<?php

declare(strict_types=1);

namespace App\Services\Ficha;

use App\Enums\AccionFicha;
use App\Models\AccesoFicha;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Escribe en la bitacora de accesos a la ficha clinica.
 *
 * POR QUE UNA CONEXION APARTE
 * --------------------------
 * La peticion que deniega un acceso suele terminar REVERTIDA. Si el registro
 * del intento fuera por la conexion normal, la reversa se llevaria el registro
 * con ella, y la bitacora no tendria los intentos denegados, que son
 * justamente los mas interesantes.
 *
 * Por eso esta clase escribe por la conexion `bitacora`, que es una conexion
 * propia (ver config/database.php) y no esta dentro de la transaccion de la
 * peticion.
 *
 * Y si no hay a mano una conexion separada, `DB::afterCommit` deja el mismo
 * rastro con menos piezas: en vez de abrir otra conexion, espera a que la
 * peticion termine y escribe. El metodo principal usa `afterCommit` y la
 * conexion aparte queda para el caso de que la clinica la haya separado de
 * verdad.
 *
 * UNA BITACORA NUNCA DEBE ROMPER LA PETICION
 * ------------------------------------------
 * Si falla el registro de un acceso, la peticion **no** se cae. Perder la
 * bitacora es malo; perder la atencion de un animal es peor. Se avisa al
 * registro del servidor y se sigue.
 */
class BitacoraFichas
{
    /**
     * Registra un acceso a la ficha de una mascota.
     *
     * @param  string  $accion  valor de App\Enums\AccionFicha.
     * @param  string|null  $motivo  por que se denego, si se denego.
     */
    public function registrar(
        string $usuarioId,
        ?string $mascotaId,
        string $tenantId,
        string $accion,
        ?string $motivo = null,
        ?string $ip = null,
        ?string $agente = null,
    ): void {
        try {
            $registro = new AccesoFicha([
                'tenant_id' => $tenantId,
                'usuario_id' => $usuarioId,
                'mascota_id' => $mascotaId,
                'accion' => $accion,
                'motivo_denial' => $motivo,
                'direccion_ip' => $ip,
                'user_agent' => $agente,
            ]);

            $conexionBitacora = DB::connection('bitacora');

            // Si la peticion todavia esta dentro de una transaccion, se espera
            // a que termine. Asi el registro sobrevive a una reversa.
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(static function () use ($registro, $conexionBitacora): void {
                    $conexionBitacora->table('accesos_ficha')->insert([
                        'id' => $registro->id,
                        'tenant_id' => $registro->tenant_id,
                        'usuario_id' => $registro->usuario_id,
                        'mascota_id' => $registro->mascota_id,
                        'accion' => $registro->accion,
                        'motivo_denial' => $registro->motivo_denial,
                        'direccion_ip' => $registro->direccion_ip,
                        'user_agent' => $registro->user_agent,
                        'created_at' => now(),
                    ]);
                });

                return;
            }

            $conexionBitacora->table('accesos_ficha')->insert([
                'id' => $registro->id,
                'tenant_id' => $registro->tenant_id,
                'usuario_id' => $registro->usuario_id,
                'mascota_id' => $registro->mascota_id,
                'accion' => $registro->accion,
                'motivo_denial' => $registro->motivo_denial,
                'direccion_ip' => $registro->direccion_ip,
                'user_agent' => $registro->user_agent,
                'created_at' => now(),
            ]);
        } catch (Throwable $excepcion) {
            // La bitacora no rompe la atencion. Se avisa al registro del
            // servidor y se sigue con la peticion.
            report($excepcion);
        }
    }

    /**
     * Atajo para un acceso permitido.
     */
    public function permitido(
        string $usuarioId,
        string $mascotaId,
        string $tenantId,
        ?string $ip = null,
        ?string $agente = null,
    ): void {
        $this->registrar(
            $usuarioId,
            $mascotaId,
            $tenantId,
            AccionFicha::Permitido->value,
            null,
            $ip,
            $agente,
        );
    }

    /**
     * Atajo para un acceso denegado, con su motivo.
     */
    public function denegado(
        string $usuarioId,
        ?string $mascotaId,
        string $tenantId,
        string $motivo,
        ?string $ip = null,
        ?string $agente = null,
    ): void {
        $this->registrar(
            $usuarioId,
            $mascotaId,
            $tenantId,
            AccionFicha::Denegado->value,
            $motivo,
            $ip,
            $agente,
        );
    }
}
