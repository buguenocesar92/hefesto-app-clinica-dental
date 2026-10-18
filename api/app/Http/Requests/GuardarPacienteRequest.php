<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Sexo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación para guardar un paciente.
 *
 * CUÁLES SON DATOS DE IDENTIFICACIÓN Y CUÁLES NO
 * ----------------------------------------------
 * Acá solo entra identificación: nombre, RUT, contacto. El historial
 * odontológico va por otros endpoints, con sus propias reglas. La razón está
 * en `docs/03-MULTITENANCY-Y-ROLES.md`: recepción tiene que poder buscar a
 * quién agenda, y no por eso también ver la historia clínica.
 *
 * `notas_administrativas` es el único campo de texto libre, y es
 * deliberadamente corto: las notas largas son el lugar donde se cuelan datos
 * clínicos sin auditoría ni permiso. Si hace falta una nota clínica, va en la
 * cronología de una pieza, que sí tiene auditoría.
 */
class GuardarPacienteRequest extends FormRequest
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
            'rut' => ['nullable', 'string', 'max:16', 'regex:/^[0-9.\-kK]{3,16}$/'],
            'nombres' => ['required', 'string', 'min:2', 'max:80'],
            'apellidos' => ['required', 'string', 'min:2', 'max:80'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'sexo' => ['nullable', Rule::enum(Sexo::class)],
            'telefono' => ['nullable', 'string', 'max:32'],
            'correo' => ['nullable', 'email:rfc', 'max:160'],
            'direccion' => ['nullable', 'string', 'max:240'],
            'prevision' => ['nullable', 'string', 'max:120'],
            'notas_administrativas' => ['nullable', 'string', 'max:1000'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rut.regex' => 'El RUT solo admite dígitos, puntos y guiones.',
            'nombres.min' => 'El nombre es demasiado corto.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'fecha_nacimiento.before' => 'La fecha de nacimiento no puede ser futura.',
        ];
    }

    /**
     * Datos limpios, con el RUT normalizado.
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $datos = $this->validated();

        if (isset($datos['rut']) && is_string($datos['rut'])) {
            $datos['rut'] = strtoupper(str_replace(['.', '-', ' '], '', $datos['rut']));
        }

        return $datos;
    }
}