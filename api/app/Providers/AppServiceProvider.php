<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\DescubridorOidc;
use App\Services\Auth\ProveedorOidcAuthentik;
use App\Services\Auth\VerificadorIdToken;
use Illuminate\Support\ServiceProvider;

/**
 * Enlaces del contenedor de Laravel.
 *
 * POR QUÉ HACE FALTA ESTE ARCHIVO
 * ------------------------------
 * `DescubridorOidc`, `VerificadorIdToken` y `ProveedorOidcAuthentik` reciben
 * escalares en el constructor (la URL del emisor, el identificador del
 * cliente, el secreto). El contenedor de Laravel no puede adivinar de dónde
 * sale un `string`, así que hay que decirselo explícitamente una vez. Esa es
 * toda la función de este archivo.
 *
 * NO HAY NADA MÁS ACÁ
 * -------------------
 * Los permisos no se registran como Gates: cada ruta vuelve a preguntar a
 * `App\Policies`, y es la Policy la que decide. Un Gate global seria un atajo
 * que salta las comprobaciones. Ver docs/03.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DescubridorOidc::class, static fn (): DescubridorOidc => new DescubridorOidc(
            emisor: (string) config('authentik.emisor'),
            clienteId: (string) config('authentik.cliente_id'),
            secretoCliente: (string) config('authentik.cliente_secreto'),
            redireccionUri: (string) config('authentik.redireccion_uri'),
            minutosCache: (int) config('authentik.minutos_de_cache_descubrimiento', 60),
        ));

        /*
         * El verificador recibe las claves publicas del emisor. Se resuelven
         * acá, y no dentro del verificador, para que el trabajo de red (descargar
         * el conjunto de JWKS y cachearlo) quede en un solo lugar.
         *
         * Es perezoso a proposito: esta instancia solo se construye cuando
         * alguien entra, no cuando arranca el contenedor. Si el emisor esta
         * caido, las rutas que no son de autenticacion siguen respondiendo.
         */
        $this->app->singleton(VerificadorIdToken::class, static fn ($contenedor): VerificadorIdToken => new VerificadorIdToken(
            clavesPublicas: $contenedor->make(DescubridorOidc::class)->clavesPublicas(),
            emisorEsperado: (string) config('authentik.emisor'),
            audienciaEsperada: (string) config('authentik.audiencia_esperada'),
        ));

        $this->app->singleton(ProveedorOidcAuthentik::class, static fn ($contenedor): ProveedorOidcAuthentik => new ProveedorOidcAuthentik(
            descubridor: $contenedor->make(DescubridorOidc::class),
            emisor: (string) config('authentik.emisor'),
            clienteId: (string) config('authentik.cliente_id'),
        ));
    }

    public function boot(): void
    {
        // Sin policies registradas a mano: Laravel descubre `App\Policies` por
        // convencion, asi que cada Policy escrita aca queda disponible por su
        // nombre de modelo.
    }
}