<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Profesional;
use App\Models\Usuario;

/**
 * Administracion de los profesionales del consultorio.
 */
class ProfesionalPolicy
{
    use Concerns\PolicyDeConsultorio;

    /**
     * La lista de profesionales la necesita TODA la agenda, no solo la
     * administracion: al agendar hay que elegir a quien se agenda.
     *
     * Ojo: este permiso abre el NOMBRE y la ESPECIALIDAD del profesional.
     * No abre su informacion de salud: la que importa es la de los pacientes
     * que atiende, y esa se protege en OdontogramaPolicy.
     */
    public function verParaAgenda(Usuario $usuario): bool
    {
        return $usuario->activo;
    }
}