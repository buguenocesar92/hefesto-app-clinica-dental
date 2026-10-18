<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccionOdontograma;
use App\Enums\ResultadoAcceso;
use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de accesos al historial odontológico.
 *
 * Solo lectura desde la aplicación: no hay ruta para escribir a mano ni para
 * borrar. La escribe `App\Services\Fichas\BitacoraOdontograma` en una conexión
 * aparte, para que el registro sobreviva a la reversión de la transacción que
 * produjo un acceso denegado.
 */
class AccesoOdontograma extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'accesos_odontograma';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'usuario_id',
        'paciente_id',
        'numero_fdi',
        'accion',
        'resultado',
        'metodo_http',
        'ruta',
        'nombre_ruta',
        'direccion_ip',
        'agente_usuario',
        'codigo_respuesta',
        'momento',
    ];

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accion' => AccionOdontograma::class,
            'resultado' => ResultadoAcceso::class,
            'momento' => 'datetime',
        ];
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /** @return BelongsTo<Paciente, $this> */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }
}