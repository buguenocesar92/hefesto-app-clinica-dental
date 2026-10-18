<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPieza;
use App\Models\Concerns\PerteneceAlConsultorio;
use App\Services\Odontograma\PiezaFdi;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pieza: el estado ACTUAL de una pieza dentaria.
 *
 * Esta fila es una **proyección** de la cronología: dice cómo está la pieza
 * hoy. La verdad de por qué está así está en `intervenciones_pieza`.
 *
 * `estado` no se puede cambiar por los medios habituales: hay dos disparadores
 * en la base que lo impiden.
 *
 *  - `piezas_exigen_cronologia`: si cambia el estado, tiene que existir una
 *    intervención nueva ligada a esta pieza.
 *  - `piezas_no_se_restaura_tras_extraccion`: si estaba `extraida`, no puede
 *    volver a un estado natural.
 *
 * Migración con esos disparadores:
 * `2026_02_10_000021_proteger_cronologia_piezas.php`.
 */
class Pieza extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'piezas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'odontograma_id',
        'paciente_id',
        'numero_fdi',
        'estado',
        'estado_desde',
        'ultima_intervencion_fecha',
        'ultima_intervencion_id',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero_fdi' => 'integer',
            'estado' => EstadoPieza::class,
            'estado_desde' => 'datetime',
            'ultima_intervencion_fecha' => 'datetime',
        ];
    }

    /** @return BelongsTo<Odontograma, $this> */
    public function odontograma(): BelongsTo
    {
        return $this->belongsTo(Odontograma::class, 'odontograma_id');
    }

    /** @return BelongsTo<Paciente, $this> */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    /**
     * Toda la historia de esta pieza, de más antigua a más reciente.
     *
     * @return HasMany<IntervencionPieza, $this>
     */
    public function cronologia(): HasMany
    {
        return $this->hasMany(IntervencionPieza::class, 'pieza_id')
            ->orderBy('fecha')
            ->orderBy('created_at');
    }

    /** Nombre descriptivo de la pieza, para etiquetas e impresos. */
    public function nombre(): string
    {
        return PiezaFdi::nombre($this->numero_fdi);
    }

    /** Días desde el último cambio de estado. */
    public function diasDesdeElUltimoCambio(): ?int
    {
        return $this->estado_desde?->diffInDays(now());
    }
}