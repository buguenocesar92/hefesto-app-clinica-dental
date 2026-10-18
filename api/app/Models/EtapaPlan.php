<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoEtapa;
use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Etapa de un plan de tratamiento.
 *
 * Puede o no tocar piezas. Si toca, `pieza_id` dice cuál, y la intervención
 * que la ejecute apunta a esta etapa. Si no toca, es una etapa administrativa
 * (un examen, una derivación) y `pieza_id` queda en blanco.
 */
class EtapaPlan extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'etapas_plan';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'plan_tratamiento_id',
        'pieza_id',
        'numero_fdi',
        'orden',
        'nombre',
        'descripcion',
        'estado',
        'profesional_id',
        'procedimiento_id',
        'fecha_prevista',
        'fecha_realizada',
        'presupuesto_clp',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'numero_fdi' => 'integer',
            'estado' => EstadoEtapa::class,
            'fecha_prevista' => 'date',
            'fecha_realizada' => 'datetime',
            'presupuesto_clp' => 'integer',
        ];
    }

    /** @return BelongsTo<PlanTratamiento, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanTratamiento::class, 'plan_tratamiento_id');
    }

    /** @return BelongsTo<Pieza, $this> */
    public function pieza(): BelongsTo
    {
        return $this->belongsTo(Pieza::class, 'pieza_id');
    }

    /** @return BelongsTo<Procedimiento, $this> */
    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class, 'procedimiento_id');
    }
}