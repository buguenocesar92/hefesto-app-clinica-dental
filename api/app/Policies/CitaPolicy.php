<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolDental;
use App\Models\Cita;
use App\Models\Usuario;

/**
 * Permisos sobre las citas.
 *
 * LA LISTA DE LA AGENDA NO ESTA AUDITADA
 * -------------------------------------
 * Una cita es un dato de agenda: nombre, hora, profesional. No es informacion
 * clinica, y por eso no pasa por el middleware `odontograma.auditado`.
 *
 * Donde SI aparece informacion clinica es en el motivo y en las notas, y esos
 * campos son los que el profesional escribe. Ver CitaController.
 *
 * `DELETE` NO ES BORRAR
 * ---------------------
 * Una cita no se borra: se anula. Una cita borrada deja al consultorio sin
 * registro de que ese paciente estuvo agendado, y en un dia con agenda llena
 * esa es exactamente la informacion que se necesita despues. El estado
 * `anulada` con su motivo queda en la fila. Ver EstadoCita::esHecho.
 */
class CitaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    public function view(Usuario $usuario, Cita $cita): bool
    {
        if (! $this->viewAny($usuario)) {
            return false;
        }

        return (string) $cita->tenant_id === (string) $usuario->tenant_id;
    }

    public function create(Usuario $usuario): bool
    {
        return $this->viewAny($usuario);
    }

    /**
     * Mover una cita de horario.
     *
     * Los tres roles. Mover un horario es una operacion de mostrador; lo que
     * la vuelve peligrosa no es quien la hace, sino que no se acepte si hay un
     * choque, y eso lo comprueba `ServicioAgenda`.
     */
    public function update(Usuario $usuario, Cita $cita): bool
    {
        return $this->view($usuario, $cita);
    }

    /**
     * Anular. No borrar.
     *
     * Los tres roles, incluida recepcion: anular una cita es lo que pasa todos
     * los dias, y obligar a que llame a un profesional para deixar de ver una
     * cita que ya no va a ocurrir hace que la gente aprenda a mentirle al
     * sistema. Lo que no se permite es dejarlo en un estado que confunda:
     * el motivo de anulacion es obligatorio.
     */
    public function anular(Usuario $usuario, Cita $cita): bool
    {
        return $this->view($usuario, $cita);
    }

    /**
     * Marcar el desenlace de la cita: atendida, no asistio.
     *
     * Solo el profesional. Que un paciente haya sido atendido o no es un hecho
     * clinico, no un dato de agenda, y se escribe desde el sillon.
     */
    public function registrarDesenlace(Usuario $usuario, Cita $cita): bool
    {
        if ($usuario->rol !== RolDental::Profesional) {
            return false;
        }

        return $this->view($usuario, $cita);
    }

    public function delete(Usuario $usuario, Cita $cita): bool
    {
        return false;
    }
}