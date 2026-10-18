<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Profesional;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Profesional>
 */
class ProfesionalFactory extends Factory
{
    protected $model = Profesional::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'nombre' => $this->faker->name(),
            'especialidad' => $this->faker->randomElement([
                'Odontologia general',
                'Endodoncia',
                'Cirugia dental',
                'Ortodoncia',
                'Periodoncia',
                'Odontopediatria',
                'Rehabilitacion oral',
            ]),
            // `registro_profesional` es texto libre y NO se valida contra
            // ninguna fuente oficial. Se pone uno de ejemplo para que se vea
            // que el campo existe; en una instalacion real queda vacio hasta
            // que el consultorio lo complete. Ver docs/07.
            'registro_profesional' => null,
            'correo' => $this->faker->safeEmail(),
            'telefono' => '+56 9 ' . $this->faker->numerify('#### ####'),
            'color_agenda' => '#' . $this->faker->hexColor(),
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}