<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Procedimiento del catálogo, con su duración.
 *
 * `duracion_minutos` es lo que la agenda usa para calcular el fin de la cita.
 * No es un dato de adorno: es la razón por la que la agenda no se atrasa.
 */
class Procedimiento extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'procedimientos';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'categoria',
        'duracion_minutos',
        'es_quirurgico',
        'requiere_anamnesis',
        'descripcion',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duracion_minutos' => 'integer',
            'es_quirurgico' => 'boolean',
            'requiere_anamnesis' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Tarifa, $this> */
    public function tarifas(): HasMany
    {
        return $this->hasMany(Tarifa::class, 'procedimiento_id');
    }

    /**
     * Tarifa vigente a una fecha dada.
     *
     * Se busca la más reciente que ya rigía en esa fecha, porque el
     * tarifario no se edita: se agrega una fila nueva cuando sube.
     */
    public function tarifaVigente(string $fecha): ?Tarifa
    {
        return $this->tarifas()
            ->whereDate('vigente_desde', '<=', $fecha)
            ->orderByDesc('vigente_desde')
            ->first();
    }
}