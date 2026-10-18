<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Condicion;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * Permisos sobre las condiciones de salud del paciente.
 *
 * Una condicion es lo mismo que una alergia en cuanto a quien la ve: las tres
 * lo necesitan para no agendar a alguien contra su estado de salud. Se
 * registra y se cierra igual, por el mismo profesional.
 *
 * LO QUE EL SISTEMA NO HACE
 * --------------------------
 * `bloquea_atencion` la marca el profesional. El sistema no la deduce:
 * adivinar que condiciones bloquean en odontologia seria inventar criterio
 * clinico, y un falso positivo detiene pacientes que si se pueden atender.
 * Ver GuardarCondicionRequest.
 */
class CondicionPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    public function view(Usuario $usuario, Condicion $condicion): bool
    {
        if (! $this->viewAny($usuario)) {
            return false;
        }

        return (string) $condicion->tenant_id === (string) $usuario->tenant_id;
    }

    public function create(Usuario $usuario): bool
    {
        return (new CapacidadesDeUsuario($usuario))->puedeRegistrarIntervencion();
    }

    public function update(Usuario $usuario, Condicion $condicion): bool
    {
        return $this->create($usuario) && $this->view($usuario, $condicion);
    }

    /**
     * Una condicion no se borra: se cierra con fecha y motivo. Mismo motivo que
     * en AlergiaPolicy.
     */
    public function delete(Usuario $usuario, Condicion $condicion): bool
    {
        return false;
    }

    public function cerrar(Usuario $usuario, Condicion $condicion): bool
    {
        return $this->update($usuario, $condicion);
    }
}