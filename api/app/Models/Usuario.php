<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolDental;
use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Usuario de la aplicación.
 *
 * La identidad viene de Authentik; acá se guarda el correo con el que la
 * persona entró, su nombre y su rol dentro del consultorio.
 *
 * `profesional_id` es lo que separa "puede entrar" de "puede tratar". Recepción
 * entra; no puede registrar una intervención porque no tiene profesional
 * asociado, y la validación del endpoint lo comprueba.
 */
class Usuario extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'usuarios';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'profesional_id',
        'nombre',
        'correo',
        'rol',
        'idioma_authentik',
        'ultimo_acceso',
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
            'rol' => RolDental::class,
            'ultimo_acceso' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class, 'profesional_id');
    }

    /** True si puede registrar intervenciones sobre piezas. */
    public function puedeTratar(): bool
    {
        return $this->rol === RolDental::Profesional && $this->profesional_id !== null;
    }

    /** True si administra el consultorio. */
    public function esAdministrador(): bool
    {
        return $this->rol === RolDental::Administrador;
    }

    /** Nombre que se muestra junto a la acción en la bitácora. */
    public function nombreParaAuditoria(): string
    {
        return $this->nombre !== '' ? $this->nombre : (string) $this->correo;
    }
}