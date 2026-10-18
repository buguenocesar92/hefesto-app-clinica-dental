<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Consultorio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comportamiento común de toda tabla de negocio.
 *
 * Todas las tablas con datos de un consultorio llevan `tenant_id`, porque la
 * Row Level Security de PostgreSQL compara esa columna con el contexto de la
 * petición. El trait no agrega ninguna otra lógica a propósito: lo único que
 * hace es exponer la relación y un alcance de consulta.
 *
 * Lo que NO está acá, y por qué: ningún método que "ponga" el tenant. El
 * contexto lo fija exclusivamente `ContextoTenant::establecer()`, dentro de
 * una transacción. Un modelo que escribiera `app.tenant_id` por su cuenta
 * rompería el alcance de transacción y el worker de colas arrastraría el
 * consultorio equivocado.
 */
trait PerteneceAlConsultorio
{
    /** @return BelongsTo<Consultorio, $this> */
    public function consultorio(): BelongsTo
    {
        return $this->belongsTo(Consultorio::class, 'tenant_id');
    }
}