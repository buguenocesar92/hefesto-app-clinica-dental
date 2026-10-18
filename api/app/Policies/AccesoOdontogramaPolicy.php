<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccesoOdontograma;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * Permisos sobre la bitacora de accesos al historial odontologico.
 *
 * SOLO EL ADMINISTRADOR DEL CONSULTORIO
 * -------------------------------------
 * Y hay una razon que no es jerarquia: la bitacora dice quien abrio el historial
 * de quien. Eso es informacion sobre las personas de la institucion tanto como
 * sobre los pacientes. Si la pudiera leer un profesional, la bitacora dejaria de
 * ser un control y pasaria a ser un instrumento para vigilar al personal.
 *
 * Y el administrador no puede leer la historia clinica, pero si puede leer el
 * CUENTO de quien la abrio. Son cosas distintas: una es informacion de salud
 * del paciente, la otra es informacion sobre el uso del sistema.
 *
 * Ver docs/07.
 */
class AccesoOdontogramaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return (new CapacidadesDeUsuario($usuario))->puedeVerAuditoria();
    }

    /**
     * El detalle fila por fila de la bitacora NO se expone por la API.
     *
     * Devolver `false` a proposito: existe en la tabla y existe para una
     * revision formal, pero una pantalla con el detalle es una pantalla que se
     * puede dejar abierta, y esa pantalla revela informacion de salud de
     * pacientes. Que quede escrito que la ausencia es una decision.
     */
    public function view(Usuario $usuario, AccesoOdontograma $acceso): bool
    {
        return false;
    }
}