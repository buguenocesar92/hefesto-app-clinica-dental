<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Insumo;
use App\Models\Usuario;

/**
 * Administracion de los insumos del consultorio.
 *
 * Un insumo es material que se consume en un procedimiento: el stock baja con cada atencion. El control es del administrador porque es una tarea de la administracion, no del profesional.
 *
 * NO SE BORRA: un insumo borrado deja el stock de los meses siguientes sin una linea que explique por que bajo. Se da de baja con `activo = false`.
 */
class InsumoPolicy
{
    use Concerns\PolicyDeConsultorio;
}