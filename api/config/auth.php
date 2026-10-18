<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Guard web
    |--------------------------------------------------------------------------
    |
    | ClinicaDental no tiene usuarios ni contrasenas propias: la identidad la
    | emite Authentik con OpenID Connect y Laravel la recibe como cookie de
    | sesion (ver App\Services\Auth). El guard `web` es el unico que existe.
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'usuarios',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'usuarios',
        ],
    ],

    'providers' => [
        'usuarios' => [
            'driver' => 'eloquent',
            'model' => App\Models\Usuario::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reinicio de contrasena
    |--------------------------------------------------------------------------
    |
    | Se deja el bloque porque Laravel lo espera, pero esta aplicacion NO tiene
    | contrasenas: el restablecimiento se hace en Authentik. No se debe
    | habilitar esta via sin una decision de seguridad previa. Ver docs/07.
    |
    */

    'passwords' => [
        'usuarios' => [
            'provider' => 'usuarios',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tiempo de expiracion de la sesion
    |--------------------------------------------------------------------------
    |
    | En un consultorio una sesion abierta mucho rato es una sesion abierta al
    | historial odontologico. El valor por defecto de Laravel es 120 minutos.
    |
    */

    'expiration' => (int) env('CLINICA_DENTAL_EXPIRACION_SESION', 120),

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 10800),

];