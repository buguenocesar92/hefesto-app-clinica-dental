<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Condición de salud del paciente que cambia la forma de atender.
 *
 * `bloquea_atencion` la marca el profesional que evaluó al paciente. El
 * sistema no la decide: adivinar qué condiciones bloquean en odontología sería
 * inventar criterio clínico, y un falso positivo detiene pacientes que sí se
 * pueden atender.
 */
class Condicion extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'condiciones';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'paciente_id',
        'profesional_id',
        'condicion',
        'detalle',
        'bloquea_atencion',
        'requiere_autorizacion_especial',
        'referencia_documento',
        'activa',
        'fecha_registro',
        'fecha_cierre',
        'motivo_cierre',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bloquea_atencion' => 'boolean',
            'requiere_autorizacion_especial' => 'boolean',
            'activa' => 'boolean',
            'fecha_registro' => 'date',
            'fecha_cierre' => 'date',
        ];
    }

    /** @return BelongsTo<Paciente, $this> */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class, 'profesional_id');
    }

    /** Línea para la pantalla de bloqueo. */
    public function lineaDeBloqueo(): string
    {
        return sprintf('%s (bloquea la atención)', $this->condicion);
    }
}