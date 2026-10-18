<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RolDental;
use App\Models\Consultorio;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Crea un consultorio con su primer administrador.
 *
 * POR QUE HACE FALTA UN COMANDO
 * ----------------------------
 * `consultorios` es la unica tabla SIN Row Level Security, porque hay que poder
 * leerla antes de que exista un `app.tenant_id` en la sesion. Eso significa que
 * el alta del primer consultorio no se puede hacer desde la aplicacion: seria un
 * agujero en el aislamiento.
 *
 * La excepcion controlada es esta, y por eso tiene que ser un comando de
 * consola, con una lista de pasos explicitos y no un endpoint. Ver docs/03.
 *
 *     php artisan clinica-dental:crear-consultorio \
 *         --slug=casa-central --nombre="Consultorio Odontologico Casa Central" \
 *         --nombre-corto=CasaCentral \
 *         --administrador="Nombre Apellido" --correo=persona@ejemplo.cl
 *
 * LO QUE NO HACE
 * --------------
 * No crea la identidad: eso es Authentik. La cuenta que crea aci es la copia de
 * trabajo, y solo va a servir cuando en Authentik exista una persona con el
 * mismo correo en el equipo `consultorio-<slug>`. Ver docs/05.
 */
class CrearConsultorioCommand extends Command
{
    protected $signature = 'clinica-dental:crear-consultorio
        {--slug= : Identificador corto y unico. Es lo que va en el grupo de Authentik.}
        {--nombre= : Nombre completo del consultorio.}
        {--nombre-corto= : Nombre abreviado, para la cabecera de la interfaz.}
        {--direccion= : Direccion, opcional.}
        {--telefono= : Telefono, opcional.}
        {--correo= : Correo del consultorio, opcional.}
        {--administrador= : Nombre de la primera persona administradora.}
        {--correo-admin= : Correo de esa persona. Debe coincidir con el de Authentik.}';

    protected $description = 'Crea un consultorio con su primer administrador. No crea la identidad en Authentik.';

    public function handle(ContextoTenant $contexto): int
    {
        $slug = (string) $this->option('slug');
        $nombre = (string) $this->option('nombre');
        $nombreCorto = (string) $this->option('nombre-corto');
        $administrador = (string) $this->option('administrador');
        $correoAdmin = (string) $this->option('correo-admin');

        $faltantes = [];

        foreach (['slug' => $slug, 'nombre' => $nombre, 'nombre-corto' => $nombreCorto, 'administrador' => $administrador, 'correo-admin' => $correoAdmin] as $clave => $valor) {
            if ($valor === '') {
                $faltantes[] = $clave;
            }
        }

        if ($faltantes !== []) {
            $this->components->error('Faltan opciones obligatorias: ' . implode(', ', $faltantes));

            return self::FAILURE;
        }

        if (Consultorio::query()->where('slug', $slug)->exists()) {
            $this->components->error("Ya existe un consultorio con el identificador {$slug}.");

            return self::FAILURE;
        }

        if (! filter_var($correoAdmin, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('El correo del administrador no tiene un formato válido.');

            return self::FAILURE;
        }

        // `consultorios` no tiene Row Level Security: se puede escribir sin
        // contexto. Es la excepcion controlada del patron, y por eso este paso
        // va antes de fijar el tenant.
        $consultorio = Consultorio::query()->create([
            'slug' => $slug,
            'nombre' => $nombre,
            'nombre_corto' => $nombreCorto,
            'direccion' => $this->option('direccion'),
            'telefono' => $this->option('telefono'),
            'correo' => $this->option('correo'),
            'activo' => true,
        ]);

        // A partir de aca si: todo lo que se escribe va con `tenant_id`
        // explicito y dentro del contexto, que es la misma regla que sigue la
        // aplicacion en cada peticion.
        $usuario = $contexto->ejecutarAislado((string) $consultorio->id, fn (): Usuario => Usuario::query()->create([
            'tenant_id' => (string) $consultorio->id,
            'nombre' => $administrador,
            'correo' => $correoAdmin,
            'rol' => RolDental::Administrador->value,
            'activo' => true,
        ]));

        $this->components->info('Consultorio creado.');
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Identificador', $consultorio->slug],
                ['Nombre', $consultorio->nombre],
                ['Identificador del consultorio', (string) $consultorio->id],
                ['Administrador', $usuario->nombre],
                ['Correo', $usuario->correo],
                ['Rol', $usuario->rol->etiqueta()],
            ],
        );

        $this->components->warn('La IDENTIDAD todavia no existe.');
        $this->components->warn("En Authentik hay que crear el equipo 'consultorio-{$slug}' "
            . "y agregar a esa persona a 'Administradores de ClinicaDental'.");
        $this->components->warn('Hasta que eso exista, el ingreso va a fallar con un mensaje claro.');
        $this->components->warn('Ver docs/05.');

        return self::SUCCESS;
    }
}