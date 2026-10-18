<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccionFicha;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Bitacora de accesos a la ficha clinica de una mascota.
 *
 * POR QUE ESTA TABLA ES INMUTABLE
 * -------------------------------
 * Una bitacora que se puede editar no es una bitacora. Esta clase lanza
 * `LogicException` si alguien intenta modificar o borrar un registro, y la
 * conexion de esta tabla se abre aparte (ver App\Services\Ficha\BitacoraFichas)
 * para que el registro sobreviva a una peticion que termina revertida.
 *
 * QUE SE REGISTRA, INCLUSO LO QUE NO DEBERIA PASAR
 * -------------------------------------------------
 * Se anotan los accesos **permitidos y denegados**. Un intento denegado de
 * abrir una ficha es, en una clinica con datos de salud, la senal mas
 * importante de revisar: puede ser un error de permisos, un profesional
 * trabajando en otra clinica, o alguien probando.
 */
class AccesoFicha extends Model
{
    use HasUuids;

    protected $table = 'accesos_ficha';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'usuario_id',
        'mascota_id',
        'accion',
        'motivo_denial',
        'contexto',
        'direccion_ip',
        'user_agent',
    ];

    /** @var list<string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /**
     * La bitacora no tiene `updated_at` de verdad: se registraba en la tabla
     * aunque el modelo dijera que si.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'accion' => AccionFicha::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** @return BelongsTo<Mascota, $this> */
    public function mascota(): BelongsTo
    {
        return $this->belongsTo(Mascota::class);
    }

    /**
     * Un registro de bitacora no se modifica.
     *
     * @throws LogicException siempre.
     */
    public function save(array $opciones = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Un registro de accesos_ficha no se puede modificar.');
        }

        return parent::save($opciones);
    }

    /**
     * Un registro de bitacora no se borra.
     *
     * @throws LogicException siempre.
     */
    public function delete(): void
    {
        throw new LogicException('Un registro de accesos_ficha no se puede borrar.');
    }
}
