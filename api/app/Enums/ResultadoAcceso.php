<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Resultado de un intento de acceso al historial odontológico.
 */
enum ResultadoAcceso: string
{
    case Permitido = 'permitido';
    case Denegado = 'denegado';
    case SinSesion = 'sin_sesion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Permitido => 'Permitido',
            self::Denegado => 'Denegado',
            self::SinSesion => 'Intento sin sesión',
        };
    }
}