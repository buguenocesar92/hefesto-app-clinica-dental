<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * Permisos de administracion del consultorio.
 *
 * Profesionales, insumos, tarifas, procedimientos y usuarios se administran
 * igual: solo el administrador del consultorio. Se repite en cada Policy en vez
 * de inventar un rol superusuario, porque "puede administrar" es una capacidad
 * concreta, no un atajo que salta todas las comprobaciones.
 *
 * LA SEGUNDA LINEA
 * ----------------
 * `view` compara ademas `$usuario->tenant_id` con el de la fila. La Row Level
 * Security ya garantiza que la fila es de este consultorio, pero esa garantia
 * vive en la base y esta comprobacion vive en PHP: son capas distintas, y una
 * Policy que se apoyara solo en la base dejaria de proteger cuando alguien la
 * llame a mano desde una prueba o desde la consola.
 */
trait PolicyDeConsultorio
{
    public function viewAny(Usuario $usuario): bool
    {
        return (new CapacidadesDeUsuario($usuario))->administraConsultorio();
    }

    public function view(Usuario $usuario, mixed $modelo): bool
    {
        return $this->viewAny($usuario)
            && (string) $modelo->tenant_id === (string) $usuario->tenant_id;
    }

    public function create(Usuario $usuario): bool
    {
        return $this->viewAny($usuario);
    }

    public function update(Usuario $usuario, mixed $modelo): bool
    {
        return $this->view($usuario, $modelo);
    }

    /**
     * Borrar NO es una operacion de administracion.
     *
     * Un catalogo de procedimientos o de tarifas no se borra: se da de baja. Un
     * procedimiento borrado deja citas huerfanas apuntando a algo que ya no
     * existe, y el odontograma pierde el nombre de lo que se le hizo a una
     * pieza. Ningun controlador de esta aplicacion llama a este metodo, y esta
     * es la razon de que exista: que quede escrito que la ausencia es
     * deliberada y no un olvido.
     */
    public function delete(Usuario $usuario, mixed $modelo): bool
    {
        return false;
    }
}