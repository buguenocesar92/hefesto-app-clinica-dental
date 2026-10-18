<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DesbloqueoAlergia;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * Permisos sobre los desbloqueos de alergia.
 *
 * ESTA ES LA POLICY MAS SENSIBLE DE LA APLICACION
 * -----------------------------------------------
 * Un desbloqueo es la unica via por la que una atencion que el sistema
 * considera prohibida se puede hacer igual. Es decir: es la unica forma que
 * tiene el software de decir "aqui hay riesgo y alguien lo firmo".
 *
 * Por eso:
 *
 *   - Solo un profesional con ficha de profesional lo concede. Recepcion no,
 *     y el administrador tampoco: conceder un desbloqueo es una decision
 *     clinica firmada, no una gestion de consultorio.
 *   - Sin `motivo` no hay desbloqueo. El motivo queda en la historia clinica y
 *     es lo que va a explicar la decision dentro de tres años.
 *   - Un desbloqueo nunca se borra ni se edita. Se deja de aplicar cerrandolo,
 *     y el registro original permanece.
 *
 * Ver docs/07.
 */
class DesbloqueoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        // Ver los desbloqueos que existen: los tres roles. Si recepcion no los
        // viera, veria una cita agendada sobre una alergia sin entender por
        // que se permitio.
        return $usuario->activo;
    }

    public function view(Usuario $usuario, DesbloqueoAlergia $desbloqueo): bool
    {
        if (! $this->viewAny($usuario)) {
            return false;
        }

        return (string) $desbloqueo->tenant_id === (string) $usuario->tenant_id;
    }

    /**
     * Conceder un desbloqueo.
     *
     * Exige rol `profesional` con ficha de profesional asociada. Ver
     * CapacidadesDeUsuario::puedeDesbloquearAlergias.
     */
    public function create(Usuario $usuario): bool
    {
        return (new CapacidadesDeUsuario($usuario))->puedeDesbloquearAlergias();
    }

    /**
     * No existe editar un desbloqueo.
     *
     * Si el motivo estaba mal escrito, se cierra este y se concede otro con el
     * motivo correcto. Un desbloqueo que se puede editar deja de ser la
     * evidencia de por que se hizo lo que se hizo.
     */
    public function update(Usuario $usuario, DesbloqueoAlergia $desbloqueo): bool
    {
        return false;
    }

    public function delete(Usuario $usuario, DesbloqueoAlergia $desbloqueo): bool
    {
        return false;
    }

    /**
     * Cerrar un desbloqueo.
     *
     * Lo que realmente se hace al "dar de baja" un desbloqueo: poner
     * `vigente_hasta` en el dia de hoy, de modo que deje de cubrir citas
     * futuras sin perder el registro de que existio.
     */
    public function cerrar(Usuario $usuario, DesbloqueoAlergia $desbloqueo): bool
    {
        if (! $this->create($usuario)) {
            return false;
        }

        return (string) $desbloqueo->tenant_id === (string) $usuario->tenant_id;
    }
}