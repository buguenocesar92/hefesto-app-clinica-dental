<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlanTratamiento;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * Permisos sobre los planes de tratamiento.
 *
 * Un plan es un documento clinico: que se le va a hacer, en que orden y por
 * cuanto. Lo escribe el profesional que propone el tratamiento y lo aprueba el
 * paciente.
 *
 * QUIEN VE Y QUIEN ESCRIBE
 * ------------------------
 * Ver: profesional y administrador. Recepcion no: un plan de tratamiento es
 * informacion de salud, y la persona del mostrador no necesita leer el
 * diagnostico ni el presupuesto clinico para agendar.
 *
 * Escribir: solo el profesional con ficha de profesional. Ver
 * CapacidadesDeUsuario::puedeEditarPlanes.
 */
class PlanTratamientoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return (new CapacidadesDeUsuario($usuario))->puedeVerPlanes();
    }

    public function view(Usuario $usuario, PlanTratamiento $plan): bool
    {
        if (! $this->viewAny($usuario)) {
            return false;
        }

        return (string) $plan->tenant_id === (string) $usuario->tenant_id;
    }

    public function create(Usuario $usuario): bool
    {
        return (new CapacidadesDeUsuario($usuario))->puedeEditarPlanes();
    }

    public function update(Usuario $usuario, PlanTratamiento $plan): bool
    {
        if (! $this->create($usuario)) {
            return false;
        }

        return (string) $plan->tenant_id === (string) $usuario->tenant_id;
    }

    /**
     * Un plan no se borra: se suspende o se rechaza.
     *
     * Los dos estados ya existen en `EstadoPlan` y quedan en la cronologia del
     * consultorio. Borrar el documento dejaria al paciente sin registro de que
     * se le propuso algo y de que no se hizo. Ver docs/02.
     */
    public function delete(Usuario $usuario, PlanTratamiento $plan): bool
    {
        return false;
    }

    /**
     * Mover el plan de estado.
     *
     * Separado de `update` a proposito: cambiar el ESTADO de un plan es lo que
     * el paciente aprueba o rechaza, y por eso queda anotado. Editar el texto
     * de una etapa no es lo mismo que aprobar el plan.
     */
    public function cambiarEstado(Usuario $usuario, PlanTratamiento $plan): bool
    {
        return $this->update($usuario, $plan);
    }
}