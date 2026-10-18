<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Authentik (identidad de Hefesto)
|------------------------------------------------------------------------------
|
| ClinicaDental NO tiene contrasenas propias: la identidad la emite Authentik
| con OpenID Connect. Ver docs/01 y docs/05.
|
| CÓMO SE LEE EL CONSULTORIO
| --------------------------
| De los grupos del `id_token`. El grupo `consultorio-<identificador>` declara
| a qué consultorio pertenece la persona, y el rol se toma del equipo
| correspondiente. Sin grupo de consultorio no hay ingreso: un usuario sin
| consultorio no puede operar. Ver App\Services\Auth\ProveedorOidcAuthentik.
|
| POR QUÉ EL ROL SE RESUELVE EN CADA INGRESO
| ------------------------------------------
| Si en Authentik le quitan el equipo a alguien, en ClinicaDental pierde el
| acceso en la sesion siguiente. Guardar el rol para siempre en la base deja
| ventanas de acceso que nadie cerraba. Por eso `usuarios.rol` es una copia de
| trabajo, no la fuente de verdad: la fuente es Authentik.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Emisor y cliente
    |----------------------------------------------------------------------
    |
    | URL publica del emisor, SIN barra final. El descubrimiento OIDC se lee
    | de `<emisor>/.well-known/openid-configuration`.
    |
    */
    'emisor' => env('AUTHENTIK_ISSUER', ''),

    'cliente_id' => env('AUTHENTIK_CLIENT_ID', ''),

    'cliente_secreto' => env('AUTHENTIK_CLIENT_SECRET', ''),

    /*
    | Debe coincidir exactamente con la URL de retorno configurada en el
    | proveedor de Authentik. Si no coincide, el canje del codigo falla y el
    | error es dificil de entender: es el primer lugar donde mirar cuando el
    | ingreso rebota.
    */
    'redireccion_uri' => env('AUTHENTIK_REDIRECT_URI', ''),

    /*
    |----------------------------------------------------------------------
    | Verificacion del token
    |----------------------------------------------------------------------
    |
    | Socialite no esta en el flujo de esta aplicacion: el canje del codigo y
    | la verificacion de la firma los hace App\Services\Auth\DescubridorOidc y
    | App\Services\Auth\VerificadorIdToken, con `firebase/php-jwt` y las claves
    | publicas que publica el propio emisor.
    |
    | Se verifican `iss`, `aud` y `exp` SIEMPRE, sin opcion de apagarlos: sin
    | eso, un token emitido para otra aplicacion del mismo emisor pasaria por
    | valido.
    |
    */
    'algoritmo' => env('AUTHENTIK_ALGORITMO', 'RS256'),

    'audiencia_esperada' => env('AUTHENTIK_AUDIENCE', env('AUTHENTIK_CLIENT_ID', '')),

    'margen_de_tiempo' => (int) env('AUTHENTIK_LEEWAY', 60),

    'minutos_de_cache_descubrimiento' => (int) env('AUTHENTIK_CACHE_METADATA', 60),

    'minutos_de_cache_claves' => (int) env('AUTHENTIK_CACHE_JWKS', 1440),

    /*
    |----------------------------------------------------------------------
    | Equipos que declaran el rol
    |----------------------------------------------------------------------
    |
    | `equipo_por_rol` mapea el nombre del equipo de Authentik al rol interno.
    | El prefijo se arma con `prefijo_equipo` y el nombre, separado por dos
    | puntos, que es la convencion que ya usan las aplicaciones de Hefesto.
    |
    | Hay que completar esto al instalar. Ver docs/05.
    |
    */
    'prefijo_equipo' => env('AUTHENTIK_GRUPO_POR_CONSULTORIO', 'ClinicaDental'),

    'equipo_por_rol' => [
        'recepcion' => env('AUTHENTIK_EQUIPO_RECEPCION', 'Recepcion de ClinicaDental'),
        'profesional' => env('AUTHENTIK_EQUIPO_PROFESIONAL', 'Profesionales de ClinicaDental'),
        'administrador' => env('AUTHENTIK_EQUIPO_ADMINISTRADOR', 'Administradores de ClinicaDental'),
    ],

];