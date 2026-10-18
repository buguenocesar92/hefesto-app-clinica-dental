<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Depuracion
    |--------------------------------------------------------------------------
    |
    | Con `LOG_LEVEL=info`, el registro de la peticion NO guarda el cuerpo: en
    | un consultorio el cuerpo de la peticion puede ser la descripcion de una
    | intervencion sobre una pieza. El nivel `info` mas abajo ya guarda ruta,
    | metodo y duracion, que es lo justo para diagnosticar sin copiar
    | informacion clinica al registro.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single,stderr')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/clinica-dental.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'replace_placeholders' => true,
        ],

        // Salida a consola: es la que leen los contenedores.
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [Monolog\Processor\PsrLogMessageProcessor::class],
        ],

        /*
        | Bitacora de intentos de acceso al historial odontologico que no
        | llegaron a la base de datos. Ocurre cuando la peticion ni siquiera
        | tiene consultorio (no hay sesion, o el usuario no tiene consultorio
        | asociado): en ese momento no hay tenant con el que escribir, asi que
        | el intento queda aca. Ver App\Services\Fichas\BitacoraOdontograma.
        */
        'accesos_rechazados' => [
            'driver' => 'single',
            'path' => storage_path('logs/accesos-rechazados.log'),
            'level' => 'warning',
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/clinica-dental.log'),
        ],

    ],

];