<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Procedimiento;
use App\Models\Usuario;

/**
 * Administracion del catalogo de procedimientos.
 *
 * El procedimiento es lo que define la duracion de una cita. Lo administra el administrador.
 *
 * NO SE BORRA: un procedimiento borrado deja citas apuntando a algo que ya no existe (`citas.procedimiento_id` es `restrictOnDelete`), y sobre todo deja el odontograma sin el nombre de lo que se le hizo a una pieza.
 */
class ProcedimientoPolicy
{
    use Concerns\PolicyDeConsultorio;
}