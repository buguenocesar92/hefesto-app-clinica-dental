<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Odontograma: el mapa de un paciente.
 *
 * La fila casi no lleva datos. Lo que importa son las relaciones: 32 filas
 * en `piezas` (el estado de hoy) y todas las filas que hagan falta en
 * `intervenciones_pieza` (la historia).
 *
 * `origen` distingue un mapa creado en el sistema de uno migrado desde un
 * cuaderno de papel. No es un detalle: si el mapa viene de una migración,
 * las fechas de `estado_desde` son estimaciones, y el profesional tiene que
 * saberlo antes de sacar conclusiones de "hace cuánto".
 */
class Odontograma extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'odontogramas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'paciente_id',
        'fecha_alta',
        'origen',
        'notas',
        'ultima_intervencion_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_alta' => 'date',
            'ultima_intervencion_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Paciente, $this> */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    /** @return HasMany<Pieza, $this> */
    public function piezas(): HasMany
    {
        return $this->hasMany(Pieza::class, 'odontograma_id');
    }

    /** @return HasOne<Pieza, $this> */
    public function pieza(int $numeroFdi): ?Pieza
    {
        return $this->piezas()->where('numero_fdi', $numeroFdi)->first();
    }

    /** @return HasMany<IntervencionPieza, $this> */
    public function intervenciones(): HasMany
    {
        return $this->hasMany(IntervencionPieza::class, 'odontograma_id');
    }
}