<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPlan;
use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan de tratamiento: lo que se va a hacer.
 *
 * No es una acción hecha. Lo que se hizo está en
 * `intervenciones_pieza`. Un plan aprobado con todas sus etapas realizadas
 * puede no tener ninguna intervención registrada, y un plan en borrador puede
 * tener el odontograma lleno de cambios: son cosas distintas.
 */
class PlanTratamiento extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'planes_tratamiento';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'paciente_id',
        'profesional_id',
        'nombre',
        'diagnostico',
        'estado',
        'presupuesto_clp',
        'fecha_inicio',
        'fecha_fin',
        'fecha_presentacion',
        'fecha_aprobacion',
        'observaciones',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoPlan::class,
            'presupuesto_clp' => 'integer',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_presentacion' => 'date',
            'fecha_aprobacion' => 'date',
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

    /** @return HasMany<EtapaPlan, $this> */
    public function etapas(): HasMany
    {
        return $this->hasMany(EtapaPlan::class, 'plan_tratamiento_id')
            ->orderBy('orden');
    }

    /**
     * Avance del plan en etapas realizadas sobre etapas totales.
     *
     * Devuelve un porcentaje entero, o null si el plan no tiene etapas. Null y
     * cero significan cosas distintas: cero es un plan con etapas y ninguna
     * hecha.
     */
    public function avance(): ?int
    {
        $total = $this->etapas()->count();

        if ($total === 0) {
            return null;
        }

        $hechas = $this->etapas()->whereIn('estado', ['realizada', 'omitida'])->count();

        return (int) round(($hechas / $total) * 100);
    }
}