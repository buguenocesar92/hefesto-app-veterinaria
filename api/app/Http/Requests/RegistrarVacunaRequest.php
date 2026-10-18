<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Especie;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validacion de una aplicacion de vacuna.
 *
 * QUE VALIDA Y QUE NO
 * -------------------
 * Valida que los DATOS esten completos y tengan forma. **No valida que la

 * decision sea correcta**: no comprueba el farmaco, ni la dosis, ni que el
 * intervalo sea el que corresponde.
 *
 * Esos son criterios clinicos, y la opinion de este proyecto es clara: los
 * decide el profesional que aplica, con su registro vigente. Un sistema que
 * "valida" la decision clinica solo le da la ilusion de un aval que no
 * tiene. Ver docs/07.
 *
 * LO QUE SI SE VALIDA, Y POR QUE
 * ------------------------------
 *  - Que la fecha de aplicacion no sea futura. Una vacuna que se aplicara manana
 *    es un error de tipeo, y deja una fila que dice que el animal esta al dia
 *    cuando no lo esta.
 *  - Que la especie del esquema calce con la de la mascota (lo revisa el
 *    controlador, porque necesita cargar el esquema).
 *  - Que el lote tenga largo razonable: sin trazabilidad, ante un problema con
 *    un lote no hay a quien preguntar.
 */
class RegistrarVacunaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El permiso real lo decide la Policy, en el controlador. Aca solo se
        // exige que haya sesion: quien entre sin sesion ya lo bloquea `auth`.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'esquema_id' => [
                'nullable',
                'uuid',
                Rule::exists('esquemas_vacunacion', 'id')->where(
                    fn ($q) => $q->where('tenant_id', (string) $this->user()->tenant_id),
                ),
            ],

            'cita_id' => [
                'nullable',
                'uuid',
                Rule::exists('citas', 'id')->where(
                    fn ($q) => $q->where('tenant_id', (string) $this->user()->tenant_id),
                ),
            ],

            'fecha_aplicacion' => [
                'required',
                'date',
                'before_or_equal:today',
            ],

            'lote' => [
                'nullable',
                'string',
                'max:60',
            ],

            'laboratorio' => [
                'nullable',
                'string',
                'max:120',
            ],

            'observaciones' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_aplicacion.required' => 'Indique la fecha en que se aplico la vacuna.',
            'fecha_aplicacion.before_or_equal' => 'La fecha de aplicacion no puede ser futura.',
            'esquema_id.exists' => 'El esquema de vacunacion no existe en esta clinica.',
            'cita_id.exists' => 'La cita no existe en esta clinica.',
            'lote.max' => 'El lote no puede tener mas de 60 caracteres.',
        ];
    }

    /**
     * Convierte la fecha a Carbon para que el controlador no tenga que
     * adivinar el formato.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('fecha_aplicacion') && $this->input('fecha_aplicacion') !== null) {
            $this->merge([
                'fecha_aplicacion' => \Illuminate\Support\Carbon::parse($this->input('fecha_aplicacion')),
            ]);
        }
    }
}
