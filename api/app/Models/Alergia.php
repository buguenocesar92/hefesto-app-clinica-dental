<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeveridadAlergia;
use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alergia de un paciente.
 *
 * Una alergia activa BLOQUEA la atención. No avisa: bloquea. La decisión y su
 * justificación están en `docs/07-SEGURIDAD-Y-LEY-19628.md`, y el mecanismo en
 * `App\Services\Alergias\RevisorDeObstaclos`.
 *
 * Una alergia no se borra: se cierra con `activa = false`, anotando por qué y
 * cuándo. Borrarla haría perder el hecho de que el paciente tuvo esa alergia
 * y quién la evaluó.
 */
class Alergia extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'alergias';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'paciente_id',
        'profesional_id',
        'sustancia',
        'reaccion',
        'severidad',
        'activa',
        'fecha_registro',
        'fecha_cierre',
        'motivo_cierre',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severidad' => SeveridadAlergia::class,
            'activa' => 'boolean',
            'fecha_registro' => 'date',
            'fecha_cierre' => 'date',
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

    /**
     * Texto de una línea para la pantalla de bloqueo.
     *
     * Es lo que recepción ve antes de intentar agendar, así que va completo:
     * sustancia, reacción y gravedad. Un "alergia" sin más no sirve para
     * decidir nada.
     */
    public function lineaDeBloqueo(): string
    {
        return sprintf(
            '%s: %s (reacción: %s, gravedad: %s)',
            $this->sustancia,
            $this->reaccion,
            $this->severidad->etiqueta(),
        );
    }
}