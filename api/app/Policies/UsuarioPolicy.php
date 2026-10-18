<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;
use App\Models\Usuario;

/**
 * Administracion de las cuentas del consultorio.
 *
 * Las cuentas NO se crean aca en el sentido de una identidad: la identidad la emite Authentik. Esta tabla es la copia de trabajo (rol, ficha de profesional, activo), que se sincroniza en cada ingreso. Ver App\Services\Auth.
 *
 * NO SE BORRA: una cuenta no se borra: se desactiva con `activo = false`, y en la sesion siguiente deja de operar. Ver ResolverTenant.
 */
class UsuarioPolicy
{
    use Concerns\PolicyDeConsultorio;
}