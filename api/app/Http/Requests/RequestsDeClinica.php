<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoPieza;
use App\Enums\SeveridadAlergia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación para registrar una alergia.
 *
 * `severidad` la escribe el profesional que evaluó al paciente. El sistema no
 * la deduce y no la calcula: una escala propia sería inventar criterio
 * clínico. Lo que sí hace la aplicación es usarla para ordenar la ficha y
 * para exigir más cuidado en el desbloqueo de los casos graves.
 */
class GuardarAlergiaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sustancia' => ['required', 'string', 'min:2', 'max:120'],
            'reaccion' => ['required', 'string', 'min:2', 'max:240'],
            'severidad' => ['required', Rule::enum(SeveridadAlergia::class)],
            'fecha_registro' => ['nullable', 'date', 'before_or_equal:today'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sustancia.required' => 'Hay que decir a qué sustancia es la alergia.',
            'reaccion.required' => 'Hay que describir qué reacción dio. Sin eso el registro no sirve para decidir nada.',
            'severidad.required' => 'Hay que indicar la gravedad de la reacción.',
        ];
    }
}

/**
 * Validación para registrar una condición de salud.
 *
 * `bloquea_atencion` la marca el profesional. El sistema no la decide:
 * adivinar qué condiciones bloquean en odontología sería inventar criterio
 * clínico, y un falso positivo detiene pacientes que sí se pueden atender.
 */
class GuardarCondicionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'condicion' => ['required', 'string', 'min:2', 'max:160'],
            'detalle' => ['nullable', 'string', 'max:1000'],
            'bloquea_atencion' => ['required', 'boolean'],
            'requiere_autorizacion_especial' => ['nullable', 'boolean'],
            'referencia_documento' => ['nullable', 'string', 'max:240'],
            'fecha_registro' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}

/**
 * Validación para conceder un desbloqueo de alergia o de condición.
 *
 * Sin `motivo` no hay desbloqueo. Es la regla que evita que exista una forma
 * rápida de saltarse el bloqueo sin dejar rastro de quién lo hizo.
 */
class GuardarDesbloqueoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'alergia_id' => ['nullable', 'uuid'],
            'condicion_id' => ['nullable', 'uuid'],
            'procedimiento_id' => ['nullable', 'uuid'],
            'motivo' => ['required', 'string', 'min:15', 'max:400'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'El desbloqueo necesita un motivo escrito: queda en la historia clínica.',
            'motivo.min' => 'El motivo es demasiado corto para explicar una decisión clínica.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messagesDeRegla(): array
    {
        return $this->messages();
    }

    /**
     * Después de validar, comprueba que el desbloqueo apunte a algo.
     */
    public function withValidator(\Illuminate\Validation\Validator $validador): void
    {
        $validador->after(function (\Illuminate\Validation\Validator $validador): void {
            $alergia = $this->input('alergia_id');
            $condicion = $this->input('condicion_id');

            if ($alergia === null && $condicion === null) {
                $validador->errors()->add(
                    'alergia_id',
                    'El desbloqueo tiene que indicar de qué alergia o de qué condición se trata.',
                );
            }

            if ($alergia !== null && $condicion !== null) {
                $validador->errors()->add(
                    'alergia_id',
                    'Un desbloqueo es de una alergia o de una condición, no de las dos.',
                );
            }
        });
    }
}

/**
 * Validación para registrar una intervención sobre una pieza.
 *
 * `fecha` puede ser pasada: es lo que permite migrar un cuaderno de papel sin
 * perder las fechas de 2019. `fecha_registro` no se pide nunca: la pone el
 * sistema, porque es el momento en que alguien escribió el dato acá, y eso no
 * lo decide el usuario.
 */
class RegistrarIntervencionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'numero_fdi' => ['required', 'integer', 'min:11', 'max:48'],
            'estado_resultante' => ['required', Rule::enum(EstadoPieza::class)],
            'descripcion' => ['required', 'string', 'min:5', 'max:2000'],
            'profesional_id' => ['required', 'uuid'],
            'procedimiento_id' => ['nullable', 'uuid'],
            'plan_tratamiento_id' => ['nullable', 'uuid'],
            'etapa_plan_id' => ['nullable', 'uuid'],
            'fecha' => ['nullable', 'date', 'before_or_equal:today'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero_fdi.min' => 'La pieza se escribe en notación FDI: de 11 a 48. Por ejemplo, 16 es el primer molar superior derecho.',
            'numero_fdi.max' => 'La pieza se escribe en notación FDI: de 11 a 48. Por ejemplo, 38 es el tercer molar inferior izquierdo.',
            'descripcion.min' => 'La descripción es demasiado corta para que sirva dentro de seis años.',
            'fecha.before_or_equal' => 'Una intervención no puede tener fecha futura.',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validador): void
    {
        $validador->after(function (\Illuminate\Validation\Validator $validador): void {
            $numero = $this->input('numero_fdi');

            if (is_int($numero) && ! \App\Services\Odontograma\PiezaFdi::esValido($numero)) {
                $validador->errors()->add(
                    'numero_fdi',
                    sprintf(
                        'El número %d no es una pieza válida. Las piezas permanentes van de 11 a 48: '
                        . 'primer dígito el cuadrante, segundo dígito la pieza.',
                        $numero,
                    ),
                );
            }
        });
    }
}