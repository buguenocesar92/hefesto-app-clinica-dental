<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Paciente;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * Permisos sobre la ficha de identificacion del paciente (nombre, RUT,
 * contacto, prevision).
 *
 * OJO CON EL NOMBRE: aqui esta la ficha de IDENTIFICACION, no el historial
 * odontologico. Los tres roles pueden verla: recepcion necesita saber a quien
 * agenda. Lo que no pueden ver es la cronologia de las piezas, que vive en
 * OdontogramaPolicy, ni los planes de tratamiento, que viven en
 * PlanTratamientoPolicy.
 *
 * La confusion entre estas dos fichas es justamente el error que este producto
 * evita. Ver docs/03.
 */
class PacientePolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    public function view(Usuario $usuario, Paciente $paciente): bool
    {
        // La RLS ya garantiza que `$paciente` es de este consultorio. Esta es la
        // segunda linea, para el caso de que alguien llame a la Policy a mano
        // fuera del contexto de la peticion.
        return $this->viewAny($usuario)
            && (string) $paciente->tenant_id === (string) $usuario->tenant_id;
    }

    public function create(Usuario $usuario): bool
    {
        return $this->viewAny($usuario);
    }

    public function update(Usuario $usuario, Paciente $paciente): bool
    {
        return $this->view($usuario, $paciente);
    }

    /**
     * Paciente no se borra: se da de baja.
     *
     * Un paciente que attended hace seis meses tiene historia clinica. Sacarlo
     * de la base seria borrar el unico registro de lo que se le hizo. Por eso
     * no hay `delete` en ningun controlador y este metodo devuelve siempre
     * falso: que quede escrito que la ausencia es una decision.
     */
    public function delete(Usuario $usuario, Paciente $paciente): bool
    {
        return false;
    }

    /**
     * Ver el ESTADO ACTUAL del odontograma: el mapa de las 32 piezas.
     *
     * Los tres roles. Recepcion lo ve para agendar con contexto: saber que al
     * paciente le faltan seis piezas es parte de la conversacion del
     * mostrador, y no es informacion cronologica.
     */
    public function verEstadoActual(Usuario $usuario, Paciente $paciente): bool
    {
        if (! $this->view($usuario, $paciente)) {
            return false;
        }

        return (new CapacidadesDeUsuario($usuario))->puedeVerEstadoActual();
    }

    /**
     * Ver la CRONOLOGIA de las piezas: que se le hizo a cada pieza y cuando.
     *
     * Aca esta el limite del producto. Una cronologia es informacion de salud
     * con fecha y con profesional, y la persona que contesta el telefono no
     * tiene ninguna necesidad operativa para leerla. Ver docs/03 y docs/07.
     */
    public function verCronologia(Usuario $usuario, Paciente $paciente): bool
    {
        if (! $this->view($usuario, $paciente)) {
            return false;
        }

        return (new CapacidadesDeUsuario($usuario))->puedeVerCronologia();
    }

    /**
     * Ver las alergias y condiciones activas del paciente.
     *
     * Los tres roles, y a proposito. Si recepcion no las viera, la pantalla del
     * sistema seria PEOR que una hoja de papel: aparentaria que no hay riesgo
     * cuando en realidad no lo esta mostrando. Ver
     * App\Services\Alergias\RevisorDeObstaclos.
     */
    public function verAlergias(Usuario $usuario, Paciente $paciente): bool
    {
        return $this->view($usuario, $paciente);
    }
}