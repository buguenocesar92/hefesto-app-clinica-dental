<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RolDental;
use App\Enums\SeveridadAlergia;
use App\Enums\Sexo;
use App\Models\Alergia;
use App\Models\Condicion;
use App\Models\Consultorio;
use App\Models\DesbloqueoAlergia;
use App\Models\Insumo;
use App\Models\Paciente;
use App\Models\Profesional;
use App\Models\Procedimiento;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Services\Odontograma\ServicioOdontograma;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\Seeder;

/**
 * Un consultorio de ejemplo, para desarrollo y para una demostracion.
 *
 * ---------------------------------------------------------------------
 *  AVISO, EN SERIO: LOS DATOS SON FICTICIOS
 * ---------------------------------------------------------------------
 * Los RUT que aparecen mas abajo son INVENTADOS. No corresponden a ninguna
 * persona real y no se han verificado contra el registro civil: se eligieron
 * para que el sistema tenga algo que mostrar y para que se note de un vistazo
 * que no son datos de verdad.
 *
 * Un RUT con digito verificador valido SIEMPRE pertenece a alguien. Por eso
 * estos numeros estan marcados con `ficticio` y hay que reemplazarlos antes de
 * mostrar esto a alguien de verdad. Ver docs/06.
 *
 * ---------------------------------------------------------------------
 *  ESTO NO ES UNA MIGRACION
 * ---------------------------------------------------------------------
 * No es un despliegue ni un respaldo de una base real. Es un catalogo de
 * ejemplo para/developar la interfaz y para probar la pantalla con algo en la
 * mesa. La orden de carga es la misma que usa la aplicacion: `consultorios`
 * (sin RLS) primero, y todo lo demas dentro del contexto.
 */
class ConsultorioDeEjemploSeeder extends Seeder
{
    /**
     * RUT FICTICIOS, Y HAY QUE ENTENDER POR QUE.
     *
     * Un RUT con digito verificador VALIDO siempre pertenece a una persona
     * real: en Chile el RUT identifica a un contribuyente, y el digito de
     * verificacion se calcula con ese numero. Inventar un RUT "parecido" al de
     * alguien significa escribir el nombre y el RUT de otra persona en una base
     * de datos.
     *
     * Por eso estos NO son RUT con digito verificador correcto. Son ceros, que
     * en Chile no existen: el titular "0.000.000-0" no esta en el registro civil.
     * Se pueden mostrar, copiar y borrar sin riesgo. Aun asi, van marcados como
     * ficticios en cada ficha. Ver docs/06.
     */
    private const RUT_FICTICIO_UNO = '0.000.000-1';

    private const RUT_FICTICIO_DOS = '0.000.000-2';

    private const RUT_FICTICIO_TRES = '0.000.000-3';

    public function run(): void
    {
        $consultorio = Consultorio::query()->firstOrCreate(
            ['slug' => 'consultorio-de-ejemplo'],
            [
                'nombre' => 'Consultorio Odontologico de Ejemplo',
                'nombre_corto' => 'Consultorio Ejemplo',
                'direccion' => 'Direccion de ejemplo 123, Oficina 401',
                'telefono' => '+56 2 2345 6789',
                'correo' => 'contacto@consultorio-ejemplo.invalid',
                'activo' => true,
            ],
        );

        $this->command?->info('Consultorio: ' . $consultorio->nombre . ' (' . $consultorio->slug . ')');

        // Todo lo demas va dentro del contexto, igual que en cada peticion.
        app(ContextoTenant::class)->ejecutarAislado((string) $consultorio->id, function () use ($consultorio): void {
            $administrador = $this->crearAdministrador($consultorio);
            $recepcion = $this->crearRecepcion($consultorio);
            [$profesionalUsuario, $cirujano] = $this->crearProfesionales($consultorio, $administrador);

            $procedimientos = $this->crearCatalogo($consultorio);
            $this->crearTarifas($consultorio, $procedimientos);
            $this->crearInsumos($consultorio);

            $this->crearPacientes($consultorio, $cirujano, $profesionalUsuario);

            $this->command?->info('Pacientes: 3, todos con RUT FICTICIO (0.000.000-1, -2 y -3, que en Chile no existen).');
            $this->command?->warn('Los datos personales de este seeder son INVENTADOS. No son de nadie.');
        });
    }

    private function crearAdministrador(Consultorio $consultorio): Usuario
    {
        return Usuario::query()->firstOrCreate(
            ['tenant_id' => (string) $consultorio->id, 'correo' => 'admin@consultorio-ejemplo.invalid'],
            [
                'nombre' => 'Dirección del Consultorio',
                'rol' => RolDental::Administrador->value,
                'idioma_authentik' => 'es',
                'activo' => true,
            ],
        );
    }

    private function crearRecepcion(Consultorio $consultorio): Usuario
    {
        return Usuario::query()->firstOrCreate(
            ['tenant_id' => (string) $consultorio->id, 'correo' => 'recepcion@consultorio-ejemplo.invalid'],
            [
                'nombre' => 'Recepción',
                'rol' => RolDental::Recepcion->value,
                'idioma_authentik' => 'es',
                'activo' => true,
            ],
        );
    }

    /**
     * Dos profesionales, porque un consultorio chico tiene mas de uno y el
     * ejemplo sirve para ver la agenda con columnas separadas.
     *
     * @return array{0: Usuario, 1: Profesional}
     */
    private function crearProfesionales(Consultorio $consultorio, Usuario $administrador): array
    {
        $cirujano = Profesional::query()->create([
            'tenant_id' => (string) $consultorio->id,
            'nombre' => 'Odontóloga General de Ejemplo',
            'especialidad' => 'Odontologia general',
            'registro_profesional' => null,
            'correo' => 'profesional@consultorio-ejemplo.invalid',
            'color_agenda' => '#2563eb',
            'activo' => true,
        ]);

        // La cuenta va CON ficha de profesional. Sin ficha no puede registrar
        // intervenciones ni desbloquear alergias: ver CapacidadesDeUsuario.
        $cirujanoUsuario = Usuario::query()->create([
            'tenant_id' => (string) $consultorio->id,
            'nombre' => 'Odontóloga General de Ejemplo',
            'correo' => 'profesional@consultorio-ejemplo.invalid',
            'rol' => RolDental::Profesional->value,
            'profesional_id' => $cirujano->id,
            'idioma_authentik' => 'es',
            'activo' => true,
        ]);

        // El segundo profesional existe como ficha, sin cuenta asociada: es el
        // caso de alguien que atiende pero no usa el sistema.
        Profesional::query()->create([
            'tenant_id' => (string) $consultorio->id,
            'nombre' => 'Cirujano Dental de Ejemplo',
            'especialidad' => 'Cirugia dental',
            'registro_profesional' => null,
            'color_agenda' => '#16a34a',
            'activo' => true,
        ]);

        return [$cirujanoUsuario, $cirujano];
    }

    /**
     * Catalogo de procedimientos.
     *
     * Las duraciones son las que declara el consultorio, no un estandar
     * verificado: de ellas sale la duracion de cada cita. Ver ServicioAgenda.
     *
     * @return array<string, Procedimiento>
     */
    private function crearCatalogo(Consultorio $consultorio): array
    {
        $catalogo = [
            'D1' => ['Profilaxis', 'preventiva', 30, false],
            'D2' => ['Destartraje', 'preventiva', 45, false],
            'R1' => ['Obturacion simple', 'restauradora', 45, false],
            'R2' => ['Obturacion compuesta', 'restauradora', 60, false],
            'E1' => ['Endodoncia unirradicular', 'endodoncica', 90, false],
            'C1' => ['Corona de porcelana', 'protesis', 120, false],
            'X1' => ['Extraccion simple', 'quirurgica', 40, true],
            'X2' => ['Extraccion quirurgica', 'quirurgica', 75, true],
            'P1' => ['Implante dental', 'quirurgica', 90, true],
            'A1' => ['Sellante de fosas y fisuras', 'preventiva', 20, false],
        ];

        $creados = [];

        foreach ($catalogo as $codigo => [$nombre, $categoria, $duracion, $quirurgico]) {
            $creados[$codigo] = Procedimiento::query()->firstOrCreate(
                ['tenant_id' => (string) $consultorio->id, 'codigo' => $codigo],
                [
                    'nombre' => $nombre,
                    'categoria' => $categoria,
                    'duracion_minutos' => $duracion,
                    'es_quirurgico' => $quirurgico,
                    'requiere_anamnesis' => ! $quirurgico,
                    'activo' => true,
                ],
            );
        }

        $this->command?->info('Procedimientos: ' . count($creados));

        return $creados;
    }

    /**
     * Tarifario. Los precios son de ejemplo y no corresponden a nada real.
     *
     * @param  array<string, Procedimiento>  $procedimientos
     */
    private function crearTarifas(Consultorio $consultorio, array $procedimientos): void
    {
        $precios = [
            'D1' => 25000,
            'D2' => 35000,
            'R1' => 45000,
            'R2' => 65000,
            'E1' => 180000,
            'C1' => 320000,
            'X1' => 55000,
            'X2' => 95000,
            'P1' => 650000,
            'A1' => 18000,
        ];

        $vigenteDesde = now()->startOfYear()->toDateString();

        foreach ($precios as $codigo => $precio) {
            Tarifa::query()->firstOrCreate(
                [
                    'tenant_id' => (string) $consultorio->id,
                    'procedimiento_id' => $procedimientos[$codigo]->id,
                    'vigente_desde' => $vigenteDesde,
                ],
                [
                    'nombre' => $procedimientos[$codigo]->nombre,
                    'precio_clp' => $precio,
                    'activo' => true,
                ],
            );
        }

        $this->command?->info('Tarifas: ' . count($precios) . ' (precios de ejemplo, no reales)');
    }

    private function crearInsumos(Consultorio $consultorio): void
    {
        $insumos = [
            ['GUANTE-N', 'Guantes de nitrilo talla M', 'caja', 40, 10],
            ['MASC-CIR', 'Mascarilla quirurgica', 'unidad', 120, 50],
            ['ANEST-CAR', 'Anestesia carbocaina', 'frasco', 6, 3],
            ['ALGOD', 'Algodon estructurado', 'rollo', 15, 5],
            ['RESINA-COMP', 'Resina compuesta', 'jeringa', 8, 12],
        ];

        foreach ($insumos as [$codigo, $nombre, $unidad, $stock, $minimo]) {
            Insumo::query()->firstOrCreate(
                ['tenant_id' => (string) $consultorio->id, 'codigo' => $codigo],
                [
                    'nombre' => $nombre,
                    'unidad' => $unidad,
                    'stock_actual' => $stock,
                    'stock_minimo' => $minimo,
                    'activo' => true,
                ],
            );
        }

        $this->command?->info('Insumos: ' . count($insumos));
    }

    /**
     * Tres pacientes, con lo justo para que las pantallas tengan algo que
     * mostrar: uno sano, uno con alergia grave y uno con una condicion que
     * bloquea.
     *
     * Los tres tienen RUT FICTICIO.
     */
    private function crearPacientes(Consultorio $consultorio, Profesional $profesional, Usuario $usuario): void
    {
        $servicio = app(ServicioOdontograma::class);

        // 1. Paciente sin antecedentes.
        $sano = $this->crearPaciente(
            $consultorio,
            'Paciente',
            'Sin Antecedentes',
            self::RUT_FICTICIO_UNO,
            '1988-03-14',
        );

        $servicio->crearOdontograma($sano);

        // 2. Paciente con alergia grave: el caso que de verdad importa.
        $alergico = $this->crearPaciente(
            $consultorio,
            'Paciente',
            'Con Alergia',
            self::RUT_FICTICIO_DOS,
            '1979-11-02',
        );

        $servicio->crearOdontograma($alergico);

        $alergia = Alergia::query()->create([
            'tenant_id' => (string) $consultorio->id,
            'paciente_id' => $alergico->id,
            'profesional_id' => $profesional->id,
            'sustancia' => 'Látex',
            'reaccion' => 'Reacción anafiláctica documentada en una atención anterior.',
            'severidad' => SeveridadAlergia::Anafilactica->value,
            'activa' => true,
            'fecha_registro' => now()->subYear()->toDateString(),
            'notas' => 'Dato de ejemplo. No corresponde a ningún paciente real.',
        ]);

        // Un desbloqueo YA VIGENTE, para que se vea una cita agendada sobre una
        // alergia con autorizacion. El motivo tambien es ficticio.
        DesbloqueoAlergia::query()->create([
            'tenant_id' => (string) $consultorio->id,
            'paciente_id' => $alergico->id,
            'alergia_id' => $alergia->id,
            'profesional_id' => $profesional->id,
            'motivo' => 'Dato de ejemplo: el paciente llega con testigo informado y con la medicacion de emergencia a la mano.',
            'concedido' => now(),
            'vigente_hasta' => null,
        ]);

        // 3. Paciente con una condicion que bloquea la atencion.
        $condicionPaciente = $this->crearPaciente(
            $consultorio,
            'Paciente',
            'Con Condición',
            self::RUT_FICTICIO_TRES,
            '1995-07-21',
        );

        $servicio->crearOdontograma($condicionPaciente);

        Condicion::query()->create([
            'tenant_id' => (string) $consultorio->id,
            'paciente_id' => $condicionPaciente->id,
            'profesional_id' => $profesional->id,
            'condicion' => 'Hipertensión no controlada',
            'detalle' => 'Dato de ejemplo. Requiere autorización del profesional tratante antes de atender.',
            'bloquea_atencion' => true,
            'requiere_autorizacion_especial' => true,
            'activa' => true,
            'fecha_registro' => now()->subMonths(6)->toDateString(),
        ]);

        $this->command?->info('Pacientes: 3, todos con RUT FICTICIO.');
    }

    private function crearPaciente(
        Consultorio $consultorio,
        string $nombres,
        string $apellidos,
        string $rut,
        string $fechaNacimiento,
    ): Paciente {
        return Paciente::query()->create([
            'tenant_id' => (string) $consultorio->id,
            // Los tres RUT son inventados. Ver el aviso de esta clase.
            'rut' => $rut,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'fecha_nacimiento' => $fechaNacimiento,
            'sexo' => Sexo::NoInformado->value,
            'telefono' => '+56 9 8765 4321',
            'prevision' => 'FONASA',
            'notas_administrativas' => 'Paciente de ejemplo. Sus datos son inventados.',
            'activo' => true,
        ]);
    }
}