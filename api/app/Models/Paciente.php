<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sexo;
use App\Models\Concerns\PerteneceAlConsultorio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Paciente del consultorio.
 *
 * Lo que vive acá es IDENTIFICACIÓN. Recepción la ve entera, porque necesita
 * saber a quién agenda.
 *
 * Lo que NO vive acá es nada del historial odontológico: ni el estado de las
 * piezas, ni su cronología, ni los planes. Eso está en `odontogramas`,
 * `piezas`, `intervenciones_pieza` y `planes_tratamiento`, con permisos y
 * bitácora propias. La razón está en `docs/03-MULTITENANCY-Y-ROLES.md`.
 *
 * Un detalle: el tiempo sin control NO es una columna. Se calcula sobre las
 * citas atendidas y sobre la última intervención registrada, en
 * `App\Services\Pacientes\TiempoSinControl`. Una columna con esa fecha queda
 * mala al día siguiente y nadie se avisa.
 */
class Paciente extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlConsultorio;

    protected $table = 'pacientes';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'rut',
        'nombres',
        'apellidos',
        'fecha_nacimiento',
        'sexo',
        'telefono',
        'correo',
        'direccion',
        'prevision',
        'notas_administrativas',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'sexo' => Sexo::class,
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Alergia, $this> */
    public function alergias(): HasMany
    {
        return $this->hasMany(Alergia::class, 'paciente_id');
    }

    /** @return HasMany<Condicion, $this> */
    public function condiciones(): HasMany
    {
        return $this->hasMany(Condicion::class, 'paciente_id');
    }

    /** @return HasOne<Odontograma, $this> */
    public function odontograma(): HasOne
    {
        return $this->hasOne(Odontograma::class, 'paciente_id');
    }

    /** @return HasMany<Cita, $this> */
    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'paciente_id');
    }

    /** @return HasMany<DesbloqueoAlergia, $this> */
    public function desbloqueos(): HasMany
    {
        return $this->hasMany(DesbloqueoAlergia::class, 'paciente_id');
    }

    /** @return HasMany<PlanTratamiento, $this> */
    public function planes(): HasMany
    {
        return $this->hasMany(PlanTratamiento::class, 'paciente_id');
    }

    /**
     * Alergias activas, de la más grave a la menos grave.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Alergia>
     */
    public function alergiasActivas()
    {
        return $this->alergias()
            ->where('activa', true)
            ->get()
            ->sortByDesc(fn (Alergia $alergia): int => $alergia->severidad->urgencia())
            ->values();
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }

    /**
     * Edad al día de hoy. Se calcula, no se guarda.
     */
    public function edad(): ?int
    {
        return $this->fecha_nacimiento?->age;
    }
}