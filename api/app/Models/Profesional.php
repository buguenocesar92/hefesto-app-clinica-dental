<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Profesional del consultorio.
 */
class Profesional extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'profesionales';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'especialidad',
        'registro_profesional',
        'correo',
        'telefono',
        'color_agenda',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Usuario, $this> */
    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'profesional_id');
    }

    /** @return HasMany<Cita, $this> */
    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'profesional_id');
    }
}