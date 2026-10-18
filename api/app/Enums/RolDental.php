<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol de la persona dentro del consultorio.
 *
 * Mismos tres roles que la clínica general, porque el consultorio se parece a
 * una clínica en su organization: recepción, profesionales y administración.
 * Lo que cambia NO son los roles sino lo que se puede hacer con ellos, y eso
 * está en `App\Services\Permisos\CapacidadesDeUsuario`.
 */
enum RolDental: string
{
    case Recepcion = 'recepcion';
    case Profesional = 'profesional';
    case Administrador = 'administrador';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Recepcion => 'Recepción',
            self::Profesional => 'Profesional',
            self::Administrador => 'Administración',
        };
    }

    /**
     * Equipo en Authentik que otorga este rol.
     *
     * La comparación en el login es insensible a mayúsculas porque Authentik
     * entrega los equipos con el nombre tal como se escribió en la consola.
     */
    public function equipoAuthentik(): string
    {
        return match ($this) {
            self::Recepcion => 'Recepcion de ClinicaDental',
            self::Profesional => 'Profesionales de ClinicaDental',
            self::Administrador => 'Administradores de ClinicaDental',
        };
    }
}