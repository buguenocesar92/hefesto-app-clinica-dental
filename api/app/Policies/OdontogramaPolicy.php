<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Odontograma;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * Permisos sobre el odontograma: el mapa de las 32 piezas y su cronologia.
 *
 * EL ODONTOGRAMA ES HISTORIA CLÍNICA INMUTABLE
 * --------------------------------------------
 * No hay `update` ni `delete`, y no es un olvido: el estado de una pieza se
 * cambia registrando una INTERVENCION nueva, que escribe un hecho y mueve la
 * pieza en la misma transaccion. La migracion `2026_02_10_000021` lo impone
 * tambien en la base, con disparadores que rechazan borrar una intervencion y
 * rechaza mover una pieza sin cronologia que lo justifique.
 *
 * Una correccion se hace con `ServicioOdontograma::registrar()` y con la
 * maquina de estados, que rechaza las transiciones que no existen en odontologia.
 * Editar el pasado seria mas facil de implementar yeria una historia clinica
 * que se puede reescribir. Ver docs/02 y docs/07.
 *
 * Además, cada apertura del mapa o de una cronologia queda anotada en
 * `accesos_odontograma`, permitido o denegado, por el middleware
 * `odontograma.auditado`.
 */
class OdontogramaPolicy
{
    /**
     * Crear el mapa de un paciente nuevo.
     *
     * Lo hacen los tres roles: el alta del mapa no revela nada, son 32 piezas
     * en estado `sano` con fecha de alta.
     */
    public function create(Usuario $usuario, Paciente $paciente): bool
    {
        if ((string) $paciente->tenant_id !== (string) $usuario->tenant_id) {
            return false;
        }

        return (new CapacidadesDeUsuario($usuario))->puedeVerEstadoActual();
    }

    /**
     * Ver el mapa: estado actual de las 32 piezas.
     *
     * Sin lista de piezas ya intervenidas y sin las notas. El estado actual es
     * informacion de ahora; lo que se hizo y cuando es cronologia, y eso lo
     * abre `verCronologia`.
     */
    public function view(Usuario $usuario, Odontograma $odontograma): bool
    {
        $capacidades = new CapacidadesDeUsuario($usuario);

        if (! $capacidades->puedeVerEstadoActual()) {
            return false;
        }

        return (string) $odontograma->tenant_id === (string) $usuario->tenant_id;
    }

    /**
     * Ver el mapa CON las notas de cada pieza.
     *
     * Las notas de una pieza son texto del profesional sobre lo que encontro.
     * No es el estado actual: es el relato. Por eso hay un permiso aparte.
     */
    public function verNotas(Usuario $usuario, Odontograma $odontograma): bool
    {
        return $this->verCronologia($usuario, $odontograma);
    }

    /**
     * Ver la cronologia: las intervenciones, con fecha y profesional.
     *
     * Profesional y administrador. Recepcion no, y no hay ninguna bandera que
     * se le pueda poner para darsela.
     */
    public function verCronologia(Usuario $usuario, Odontograma $odontograma): bool
    {
        $capacidades = new CapacidadesDeUsuario($usuario);

        if (! $capacidades->puedeVerCronologia()) {
            return false;
        }

        return (string) $odontograma->tenant_id === (string) $usuario->tenant_id;
    }

    /**
     * Registrar una intervencion sobre una pieza.
     *
     * Exige rol `profesional` Y ficha de profesional: la intervencion queda
     * firmada por quien la hizo, y una intervencion sin tratante es un hecho
     * que no se puede sostener. Ver CapacidadesDeUsuario.
     */
    public function registrarIntervencion(Usuario $usuario, Odontograma $odontograma): bool
    {
        $capacidades = new CapacidadesDeUsuario($usuario);

        if (! $capacidades->puedeRegistrarIntervencion()) {
            return false;
        }

        return (string) $odontograma->tenant_id === (string) $usuario->tenant_id;
    }

    /**
     * Corregir el pasado.
     *
     * No existe corregir el pasado: se registra una intervencion nueva que
     * explica el cambio. Este metodo devuelve siempre `false` para que quede
     * escrito, y ningun controlador lo llama. Si alguna vez se necesita una
     * correccion (porque el odontograma estaba mal), es una intervencion con
     * fecha y descripcion, no un `UPDATE`.
     */
    public function update(Usuario $usuario, Odontograma $odontograma): bool
    {
        return false;
    }

    public function delete(Usuario $usuario, Odontograma $odontograma): bool
    {
        return false;
    }
}