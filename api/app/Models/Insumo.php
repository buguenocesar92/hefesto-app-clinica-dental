<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Insumo del consultorio.
 *
 * Conteo manual: esta versión no lleva movimientos de bodega. Ver
 * `docs/10-PRUEBAS-Y-ESTADO.md`.
 */
class Insumo extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'insumos';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'unidad',
        'stock_actual',
        'stock_minimo',
        'fecha_ultima_carga',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock_actual' => 'integer',
            'stock_minimo' => 'integer',
            'fecha_ultima_carga' => 'date',
            'activo' => 'boolean',
        ];
    }

    /** El insumo está bajo el mínimo declarado por el consultorio. */
    public function bajoStock(): bool
    {
        return $this->stock_actual <= $this->stock_minimo;
    }
}