<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Consultorio: el tenant.
 *
 * El `id` de esta tabla es lo que viaja en `app.tenant_id` en cada petición.
 */
class Consultorio extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'consultorios';

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'nombre',
        'nombre_corto',
        'direccion',
        'telefono',
        'correo',
        'activo',
    ];

    /** @var list<string> */
    protected $hidden = [
        'created_at',
        'updated_at',
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
        return $this->hasMany(Usuario::class, 'tenant_id');
    }

    /** @return HasMany<Paciente, $this> */
    public function pacientes(): HasMany
    {
        return $this->hasMany(Paciente::class, 'tenant_id');
    }

    /** @return HasMany<Profesional, $this> */
    public function profesionales(): HasMany
    {
        return $this->hasMany(Profesional::class, 'tenant_id');
    }
}