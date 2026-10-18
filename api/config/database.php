<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Base de datos
|------------------------------------------------------------------------------
|
| PostgreSQL, y no otra cosa. El aislamiento entre consultorios esta
| implementado con Row Level Security, que es una funcion de PostgreSQL: en
| otro motor no existira, y las pruebas de aislamiento pasarian sin comprobar
| nada. Ver docs/03.
|
| LA CONEXION DE AUDITORIA
| ------------------------
| `auditoria` es la misma base por defecto, pero con su propio juego de
| variables. Es a proposito: la peticion que abre el historial odontologico sin
| autorizacion termina revertida, y el registro del intento tiene que
| sobrevivir a esa reversa. Con la misma conexion se perderia justo el
| registro que mas importa. Ver App\Services\Fichas\BitacoraOdontograma.
|
| Se pueden separar fisicamente con `DB_AUDITORIA_*`: es una de las separaciones
| que suele pedir una auditoria externa. Ver docs/07.
|
*/

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'clinica_dental'),
            'username' => env('DB_USERNAME', 'clinica_dental'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            // La aplicacion no usa SSL contra PostgreSQL porque los contenedores
            // se comunican por la red interna de Compose. Si la base queda
            // afuera, hay que poner `DB_SSLMODE=require` y revisar la cadena
            // de conexion. Ver docs/04.
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'auditoria' => [
            'driver' => 'pgsql',
            'host' => env('DB_AUDITORIA_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_AUDITORIA_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_AUDITORIA_DATABASE', env('DB_DATABASE', 'clinica_dental')),
            'username' => env('DB_AUDITORIA_USERNAME', env('DB_USERNAME', 'clinica_dental')),
            'password' => env('DB_AUDITORIA_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_AUDITORIA_SSLMODE', env('DB_SSLMODE', 'prefer')),
        ],

    ],

    /*
    | Lecturas MySQL y SQLite quedan para que la estructura sea la misma que en
    | las demas aplicaciones de Hefesto. ClinicaDental NO los usa: la RLS es de
    | PostgreSQL y no se negocia. Ver docs/01.
    */
    'mysql' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'clinica_dental'),
        'username' => env('DB_USERNAME', 'clinica_dental'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
    ],

    'sqlite' => [
        'driver' => 'sqlite',
        'database' => env('DB_DATABASE', database_path('database.sqlite')),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),

        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];