<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarifa de un procedimiento, vigente desde una fecha.
 *
 * Es un dato histórico: la tarifa que rigió el día de una intervención se
 * sigue leyendo igual aunque el precio haya subido después.
 */
class Tarifa extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'tarifas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'procedimiento_id',
        'nombre',
        'precio_clp',
        'vigente_desde',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_clp' => 'integer',
            'vigente_desde' => 'date',
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Procedimiento, $this> */
    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class, 'procedimiento_id');
    }
}