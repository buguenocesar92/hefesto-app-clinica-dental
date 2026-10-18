<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloqueo de agenda: vacaciones, reunión u otra indisponibilidad.
 *
 * No tiene paciente ni procedimiento y no genera un hecho clínico. Lo que
 * tiene en común con una cita es que ocupa el tiempo del profesional, y por
 * eso `VerificadorDeDisponibilidad` los consulta juntos.
 */
class BloqueoAgenda extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'bloqueos_agenda';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'profesional_id',
        'fecha_inicio',
        'fecha_fin',
        'motivo',
        'todo_el_dia',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
            'todo_el_dia' => 'boolean',
        ];
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class, 'profesional_id');
    }
}