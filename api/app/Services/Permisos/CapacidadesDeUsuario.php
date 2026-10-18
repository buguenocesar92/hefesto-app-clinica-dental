<?php

declare(strict_types=1);

namespace App\Services\Permisos;

use App\Enums\AccionOdontograma;
use App\Enums\RolDental;
use App\Models\Usuario;
use LogicException;

/**
 * Qué puede hacer cada persona dentro del consultorio.
 *
 * ESTA ES LA SEGUNDA CAPA DEL AISLAMIENTO
 * --------------------------------------
 * La primera es la base: la RLS impide que un consultorio vea a otro. Esta
 * segunda capa, dentro del consultorio, decide qué puede hacer una persona.
 * Son cosas distintas y ninguna reemplaza a la otra.
 *
 * LA DECISIÓN QUE IMPORTA
 * ----------------------
 * Recepción agenda citas, y para eso tiene que ver las alergias activas: si
 * no las viera, la pantalla del sistema sería peor que una hoja de papel,
 * porque aparentaría que no hay riesgo.
 *
 * Pero recepción NO ve la cronología de las piezas ni los planes de
 * tratamiento. Esos son datos de salud con fecha, con profesional y con lo que
 * el paciente hizo el año pasado, y no hay ninguna necesidad operativa para
 * que la persona que contesta el teléfono lo lea.
 *
 * Lo que hace recepción sí queda registrado: cada intento de abrir la
 * cronología, permitido o denegado, va a `accesos_odontograma`. Así que un
 * profesional puede ver quién estuvo mirando su historia clínica.
 */
class CapacidadesDeUsuario
{
    public function __construct(private readonly Usuario $usuario) {}

    /** Ve el estado actual del odontograma (el mapa). */
    public function puedeVerEstadoActual(): bool
    {
        // Los tres roles. Recepción lo ve para agendar con contexto.
        return true;
    }

    /** Ve la cronología de una pieza: qué se le hizo y cuándo. */
    public function puedeVerCronologia(): bool
    {
        return $this->usuario->rol === RolDental::Profesional
            || $this->usuario->rol === RolDental::Administrador;
    }

    /** Registra una intervención sobre una pieza. */
    public function puedeRegistrarIntervencion(): bool
    {
        // Además del rol, hace falta un profesional asociado: es el
        // profesional el que queda como tratante en la cronología, y una
        // intervención sin tratante es un hecho que no se puede firmar.
        return $this->usuario->rol === RolDental::Profesional
            && $this->usuario->profesional_id !== null
            && $this->usuario->activo;
    }

    /** Ve los planes de tratamiento. */
    public function puedeVerPlanes(): bool
    {
        return $this->usuario->rol === RolDental::Profesional
            || $this->usuario->rol === RolDental::Administrador;
    }

    /** Crea o modifica un plan. Solo el profesional tratante. */
    public function puedeEditarPlanes(): bool
    {
        return $this->usuario->rol === RolDental::Profesional
            && $this->usuario->profesional_id !== null;
    }

    /** Concede o cierra un desbloqueo de alergia. */
    public function puedeDesbloquearAlergias(): bool
    {
        return $this->usuario->rol === RolDental::Profesional
            && $this->usuario->profesional_id !== null;
    }

    /** Administra profesionales, insumos, tarifas y usuarios. */
    public function administraConsultorio(): bool
    {
        return $this->usuario->rol === RolDental::Administrador && $this->usuario->activo;
    }

    /** Lee la bitácora de accesos. */
    public function puedeVerAuditoria(): bool
    {
        return $this->usuario->rol === RolDental::Administrador;
    }

    /**
     * Traduce una acción de bitácora a la capacidad que la autoriza.
     *
     * Lo usa el middleware de auditoría para decidir si va a pasar, sin
     * duplicar la matriz de permisos en dos lugares.
     */
    public function admite(AccionOdontograma $accion): bool
    {
        return match ($accion) {
            AccionOdontograma::VerEstadoActual => $this->puedeVerEstadoActual(),
            AccionOdontograma::VerCronologia => $this->puedeVerCronologia(),
            AccionOdontograma::RegistrarIntervencion => $this->puedeRegistrarIntervencion(),
            AccionOdontograma::VerPlan => $this->puedeVerPlanes(),
            AccionOdontograma::EditarPlan => $this->puedeEditarPlanes(),
        };
    }

    /**
     * Lanza si la capacidad no está. Se usa donde no hay Policy: un permiso
     * comprobado a medias es peor que uno comprobado entero.
     *
     * @throws LogicException
     */
    public function exigir(bool $capacidad, string $mensaje): void
    {
        if (! $capacidad) {
            throw new LogicException($mensaje);
        }
    }
}