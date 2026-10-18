<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Alergia;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * Permisos sobre las alergias del paciente.
 *
 * QUIEN VE LA ALERGIA
 * -------------------
 * Los tres roles, y es una decision consciente. Una alergia no tratada es la
 * causa de una buena parte de los accidentes en el sillón. Si recepcion no la
 * viera al agendar, el sistema seria PEOR que una hoja de papel, porque
 * aparentaria que no hay riesgo cuando en realidad lo esta ocultando. Ver
 * PacientePolicy::verAlergias.
 *
 * QUIEN LA REGISTRA
 * -----------------
 * Un profesional con ficha de profesional. La severidad la escribe quien
 * evaluo al paciente; el sistema no la deduce ni la calcula, porque una escala
 * propia seria inventar criterio clinico.
 */
class AlergiaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    public function view(Usuario $usuario, Alergia $alergia): bool
    {
        if (! $this->viewAny($usuario)) {
            return false;
        }

        return (string) $alergia->tenant_id === (string) $usuario->tenant_id;
    }

    public function create(Usuario $usuario): bool
    {
        return (new CapacidadesDeUsuario($usuario))->puedeRegistrarIntervencion();
    }

    public function update(Usuario $usuario, Alergia $alergia): bool
    {
        return $this->create($usuario) && $this->view($usuario, $alergia);
    }

    /**
     * Una alergia no se borra: se CIERRA con fecha y motivo.
     *
     * Una alergia cerrada sigue en la ficha, porque el paciente sigue siendo
     * alergico y dentro de tres años va a volver. Borrarla seria perder el
     * motivo del cierre, que es la parte que el proximo profesional necesita.
     * Ver AlergiaController::cerrar.
     */
    public function delete(Usuario $usuario, Alergia $alergia): bool
    {
        return false;
    }

    /**
     * Cerrar una alergia activa.
     *
     * Exige el motivo de cierre. Un cierre sin motivo escrito no dice nada
     * util dentro de dos años.
     */
    public function cerrar(Usuario $usuario, Alergia $alergia): bool
    {
        return $this->update($usuario, $alergia);
    }

    /**
     * Comprobacion de apoyo para las rutas que llegan con el paciente en la
     * ruta en vez de con la alergia.
     */
    public function verDelPaciente(Usuario $usuario, Paciente $paciente): bool
    {
        if ((string) $paciente->tenant_id !== (string) $usuario->tenant_id) {
            return false;
        }

        return $this->viewAny($usuario);
    }
}