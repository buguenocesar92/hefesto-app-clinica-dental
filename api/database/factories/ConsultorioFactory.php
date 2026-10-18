<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Consultorio;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Consultorio>
 */
class ConsultorioFactory extends Factory
{
    protected $model = Consultorio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = 'Consultorio ' . $this->faker->lastName();

        return [
            'id' => (string) Str::uuid(),
            'slug' => 'consultorio-' . Str::lower(Str::random(10)),
            'nombre' => $nombre,
            'nombre_corto' => Str::limit($nombre, 50, ''),
            'direccion' => $this->faker->streetAddress(),
            'telefono' => '+56 2 ' . $this->faker->numerify('#### ####'),
            'correo' => $this->faker->safeEmail(),
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}