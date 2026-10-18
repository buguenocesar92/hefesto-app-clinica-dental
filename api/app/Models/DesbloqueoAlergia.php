<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desbloqueo de una alergia o de una condición, con su profesional y su
 * motivo.
 *
 * No es una bandera: es un acto clínico registrado. Sin `motivo` y sin
 * `profesional_id` no hay desbloqueo.
 */
class DesbloqueoAlergia extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'desbloqueos_alergia';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'paciente_id',
        'alergia_id',
        'condicion_id',
        'profesional_id',
        'procedimiento_id',
        'motivo',
        'concedido',
        'vigente_hasta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'concedido' => 'datetime',
            'vigente_hasta' => 'date',
        ];
    }

    /** @return BelongsTo<Paciente, $this> */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    /** @return BelongsTo<Alergia, $this> */
    public function alergia(): BelongsTo
    {
        return $this->belongsTo(Alergia::class, 'alergia_id');
    }

    /** @return BelongsTo<Condicion, $this> */
    public function condicion(): BelongsTo
    {
        return $this->belongsTo(Condicion::class, 'condicion_id');
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

    /**
     * True si el desbloqueo sigue cubriendo la fecha de hoy.
     *
     * `procedimiento_id` en blanco significa que el desbloqueo vale para
     * cualquier procedimiento del paciente: es más ancho, y por eso la
     * interfaz lo muestra distinto.
     */
    public function vigenteEn(string $fecha): bool
    {
        return $this->vigente_hasta === null || $this->vigente_hasta->greaterThanOrEqualTo($fecha);
    }
}