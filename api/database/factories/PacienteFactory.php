<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Sexo;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Paciente>
 */
class PacienteFactory extends Factory
{
    protected $model = Paciente::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            // El RUT que genera la libreria es de una persona que NO existe. Se
            // deja igual: el validador de `GuardarPacienteRequest` solo
            // comprueba el formato, y un RUT con digito verificador correcto
            // seria un RUT que en Chile pertenece a alguien.
            'rut' => null,
            'nombres' => $this->faker->firstName(),
            'apellidos' => $this->faker->lastName(),
            'fecha_nacimiento' => $this->faker->dateTimeBetween('-70 years', '-5 years'),
            'sexo' => Sexo::NoInformado->value,
            'telefono' => '+56 9 ' . $this->faker->numerify('#### ####'),
            'correo' => null,
            'direccion' => $this->faker->streetAddress(),
            'prevision' => $this->faker->randomElement(['FONASA', 'ISAPRE', 'Particular']),
            'notas_administrativas' => null,
            'activo' => true,
        ];
    }

    public function conRut(string $rut): static
    {
        return $this->state(fn (): array => ['rut' => $rut]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}