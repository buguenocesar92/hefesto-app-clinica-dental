<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoCita;
use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cita de la agenda clínica.
 *
 * `fecha_fin` sale de `duracion_minutos`, que viene del procedimiento. No es
 * una constante de la aplicación: cada procedimiento dura lo que el consultorio
 * dijo que dura.
 *
 * `duracion_minutos` se copia a la cita para que un cambio posterior en el
 * catálogo no redibuje el pasado: la cita que fue de 60 minutos sigue siendo,
 * en el registro, una cita de 60 minutos.
 *
 * El solapamiento no se comprueba solo en el código: hay una restricción
 * `EXCLUDE USING gist` en la base que lo prohíbe. Ver la migración
 * `2026_02_10_000016_crear_tabla_citas.php`.
 */
class Cita extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'citas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'paciente_id',
        'profesional_id',
        'procedimiento_id',
        'plan_tratamiento_id',
        'etapa_plan_id',
        'fecha_inicio',
        'fecha_fin',
        'duracion_minutos',
        'estado',
        'silla',
        'motivo',
        'notas',
        'desbloqueo_alergia_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
            'duracion_minutos' => 'integer',
            'estado' => EstadoCita::class,
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

    /** @return BelongsTo<Procedimiento, $this> */
    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class, 'procedimiento_id');
    }

    /** @return BelongsTo<DesbloqueoAlergia, $this> */
    public function desbloqueo(): BelongsTo
    {
        return $this->belongsTo(DesbloqueoAlergia::class, 'desbloqueo_alergia_id');
    }
}