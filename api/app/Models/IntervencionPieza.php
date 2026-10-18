<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPieza;
use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intervención sobre una pieza: UN HECHO, CON SU FECHA.
 *
 * Esta es la cronología, y es acumulativa. Una fila no se modifica ni se
 * borra: hay un disparador en la base (`cronologia_sin_borrado`) que rechaza
 * el `UPDATE` y el `DELETE` sin importar quién los ejecute.
 *
 * `fecha` (cuándo ocurrió) y `fecha_registro` (cuándo se escribió acá) están
 * separadas porque un consultorio que viene de un cuaderno tiene hechos de
 * hace años que se digitan hoy. Si se guardara una sola fecha, el
 * profesional leería que el paciente fue tratado hace seis meses.
 *
 * `estado_anterior` y `estado_resultante` se leen con la fila anterior y
 * cuentan la historia solos: no hay que deducir nada.
 */
class IntervencionPieza extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'intervenciones_pieza';

    /**
     * Estas filas son de solo creación. `forceFill` queda disponible para el
     * seeder y para una eventual migración de cuaderno viejo, pero la base
     * rechaza cualquier `UPDATE` que se intente después.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'paciente_id',
        'odontograma_id',
        'pieza_id',
        'numero_fdi',
        'profesional_id',
        'procedimiento_id',
        'plan_tratamiento_id',
        'etapa_plan_id',
        'fecha',
        'fecha_registro',
        'descripcion',
        'estado_anterior',
        'estado_resultante',
        'registrado_por',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero_fdi' => 'integer',
            'fecha' => 'datetime',
            'fecha_registro' => 'datetime',
            'estado_anterior' => EstadoPieza::class,
            'estado_resultante' => EstadoPieza::class,
        ];
    }

    /** @return BelongsTo<Pieza, $this> */
    public function pieza(): BelongsTo
    {
        return $this->belongsTo(Pieza::class, 'pieza_id');
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

    /** @return BelongsTo<PlanTratamiento, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanTratamiento::class, 'plan_tratamiento_id');
    }

    /**
     * Cuánto pasó entre dos intervenciones de la misma pieza.
     *
     * Sirve para el caso que el producto viene a resolver: dos hechos sobre
     * la misma pieza, separados por años, son dos hechos y no uno.
     */
    public function diasDesde(IntervencionPieza $anterior): int
    {
        return (int) $anterior->fecha->diffInDays($this->fecha);
    }
}