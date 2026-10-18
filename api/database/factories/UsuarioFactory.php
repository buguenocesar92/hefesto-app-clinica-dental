<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolDental;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'nombre' => $this->faker->name(),
            'correo' => $this->faker->unique()->safeEmail(),
            'rol' => RolDental::Recepcion->value,
            'idioma_authentik' => 'es',
            'activo' => true,
        ];
    }

    /**
     * Una cuenta sin ficha de profesional.
     *
     * Es el caso por omision a proposito: la ficha de profesional es lo que
     * permite registrar intervenciones y desbloquear alergias, y una prueba que
     * no la pide tiene que estar midiendo el caso restringido.
     */
    public function recepcion(): static
    {
        return $this->state(fn (): array => ['rol' => RolDental::Recepcion->value, 'profesional_id' => null]);
    }

    public function administrador(): static
    {
        return $this->state(fn (): array => ['rol' => RolDental::Administrador->value, 'profesional_id' => null]);
    }

    public function profesional(?string $profesionalId = null): static
    {
        return $this->state(fn (): array => [
            'rol' => RolDental::Profesional->value,
            'profesional_id' => $profesionalId,
        ]);
    }

    /**
     * Profesional con ficha: puede registrar intervenciones.
     */
    public function profesionalConFicha(string $profesionalId): static
    {
        return $this->profesional($profesionalId);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}