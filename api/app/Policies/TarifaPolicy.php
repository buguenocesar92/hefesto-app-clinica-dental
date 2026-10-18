<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tarifa;
use App\Models\Usuario;

/**
 * Administracion del tarifario del consultorio.
 *
 * La tarifa es un dato economico: lo que se cobra por un procedimiento. La ve y la edita el administrador.
 *
 * NO SE BORRA: una tarifa borrada deja las citas ya cobradas sin el respaldo de
 * lo que se cobro. Se da de baja con `activo = false` y el historico queda.
 */
class TarifaPolicy
{
    use Concerns\PolicyDeConsultorio;
}