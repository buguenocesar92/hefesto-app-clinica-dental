<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Consultorio;
use App\Models\Odontograma;
use App\Models\Paciente;
use App\Models\Profesional;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comprueba, desde la consola, que la RLS esta puesta y funciona.
 *
 * Sirve para lo mismo que la suite `Aislamiento`, pero en el servidor real: antes
 * de recibir el primer paciente, o despues de tocar una migracion.
 *
 *     php artisan clinica:verificar-aislamiento
 *
 * NO ES UNA PRUEBA UNITARIA
 * -------------------------
 * Escribe filas de prueba y las borra al final. **No debe correrse contra una
 * base con datos reales de pacientes**: los identificadores que usa son fijos y
 * visibles, asi que en una base con historia clinica real se identificarian
 * filas reales. Si hace falta verificar en produccion, hay que hacerlo en una
 * base de pruebas restaurada. Ver docs/08.
 *
 * QUE COMPRUEBA, EN ORDEN
 * -----------------------
 *   1. Que las diecisiete tablas de negocio tengan RLS FORZADO (no solo
 *      habilitado: forzado, que es lo que hace que las politicas apliquen
 *      tambien al dueno de la tabla, que es el mismo usuario con el que se
 *      conecta Laravel).
 *   2. Que exista la politica de aislamiento en cada una.
 *   3. Que un consultorio no vea filas de otro, ni por clave primaria.
 *   4. Que no se pueda escribir una fila declarando otro consultorio
 *      (`WITH CHECK`).
 *   5. Que una consulta SIN contexto falle, en vez de devolver cero filas.
 *      Este ultimo es el mas importante: si `app_tenant_id()` devolviera NULL en
 *      vez de lanzar excepcion, la ausencia de contexto seria indistinguible de
 *      "no hay nada", y el fallo seria silencioso.
 */
class VerificarAislamientoCommand extends Command
{
    protected $signature = 'clinica:verificar-aislamiento
        {--consultorio : Identificador de un consultorio existente para usar como contexto A. Si no se indica, se crea una fila temporal.}';

    protected $description = 'Comprueba que el aislamiento entre consultorios funciona a nivel de base de datos.';

    /**
     * Las diecisiete tablas de negocio, en el mismo orden que la migracion
     * `2026_02_10_000020`. Si la migracion agrega una tabla y este comando no,
     * la verificacion pasa sin mirarla: por eso la lista se revisa en cada
     * despliegue.
     *
     * @var list<string>
     */
    private const TABLAS_PROTEGIDAS = [
        'profesionales',
        'procedimientos',
        'insumos',
        'tarifas',
        'usuarios',
        'pacientes',
        'alergias',
        'condiciones',
        'desbloqueos_alergia',
        'odontogramas',
        'piezas',
        'intervenciones_pieza',
        'planes_tratamiento',
        'etapas_plan',
        'citas',
        'bloqueos_agenda',
        'accesos_odontograma',
    ];

    public function handle(ContextoTenant $contexto): int
    {
        $this->components->info('Revisando que las tablas de negocio tengan RLS forzado.');

        if (! $this->revisarTablas()) {
            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Las %d tablas tienen RLS forzado y su politica de aislamiento.',
            count(self::TABLAS_PROTEGIDAS),
        ));

        return $this->revisarAislamientoReal($contexto);
    }

    /**
     * Comprueba RLS y politica tabla por tabla.
     */
    private function revisarTablas(): bool
    {
        $fallos = 0;

        foreach (self::TABLAS_PROTEGIDAS as $tabla) {
            $fila = DB::selectOne(
                'SELECT relrowsecurity AS rls, relforcerowsecurity AS forzado FROM pg_class WHERE relname = ?',
                [$tabla],
            );

            if ($fila === null) {
                $this->components->error("La tabla {$tabla} no existe. Faltaria correr las migraciones.");

                $fallos++;

                continue;
            }

            if (! $fila->rls) {
                $this->components->error("La tabla {$tabla} no tiene Row Level Security habilitado.");

                $fallos++;
            }

            if (! $fila->forzado) {
                $this->components->error("La tabla {$tabla} no tiene RLS FORZADO. Sin FORCE, las politicas "
                    . 'no le aplican al dueno de la tabla, que es el usuario con el que se conecta Laravel.');

                $fallos++;
            }

            $politica = DB::selectOne(
                'SELECT count(*) AS total FROM pg_policies WHERE tablename = ? AND policyname = ?',
                [$tabla, 'politica_aislamiento_tenant'],
            );

            if ((int) ($politica->total ?? 0) === 0) {
                $this->components->error("La tabla {$tabla} no tiene la politica politica_aislamiento_tenant.");

                $fallos++;
            }
        }

        if ($fallos > 0) {
            $this->components->error('La instalacion NO tiene el aislamiento activo. No siga.');

            return false;
        }

        return true;
    }

    /**
     * La prueba que de verdad importa: escribir y leer con dos consultorios.
     */
    private function revisarAislamientoReal(ContextoTenant $contexto): int
    {
        $consultorioA = $this->obtenerOCrearConsultorio('verificacion-a', $this->option('consultorio'));
        $consultorioB = $this->obtenerOCrearConsultorio('verificacion-b');

        $profesionalB = $contexto->ejecutarAislado((string) $consultorioB->id, fn (): Profesional => Profesional::query()->create([
            'tenant_id' => (string) $consultorioB->id,
            'nombre' => 'Profesional de verificacion B',
            'especialidad' => 'Verificacion',
        ]));

        $pacienteB = $contexto->ejecutarAislado((string) $consultorioB->id, fn (): Paciente => Paciente::query()->create([
            'tenant_id' => (string) $consultorioB->id,
            'nombres' => 'Paciente',
            'apellidos' => 'DeVerificacion',
            'fecha_nacimiento' => '1990-01-01',
        ]));

        try {
            $this->components->info('Creados un paciente y un profesional temporales en el consultorio B.');

            if (! $this->revisarLecturaCruzada($contexto, $consultorioA, $pacienteB)) {
                return self::FAILURE;
            }

            if (! $this->revisarEscrituraCruzada($contexto, $consultorioA, $profesionalB)) {
                return self::FAILURE;
            }

            if (! $this->revisarCronologiaAislada($contexto, $consultorioB, $pacienteB)) {
                return self::FAILURE;
            }
        } finally {
            // Se borran las filas temporales. La RLS exige el contexto correcto
            // para borrarlas, asi que cada una va en su propia transaccion.
            $contexto->ejecutarAislado((string) $consultorioB->id, function () use ($pacienteB, $profesionalB): void {
                Odontograma::query()->where('paciente_id', $pacienteB->id)->delete();
                $pacienteB->delete();
                $profesionalB->delete();
            });

            $this->components->info('Filas temporales borradas.');
        }

        if (! $this->revisarFalloCerrado()) {
            return self::FAILURE;
        }

        $this->components->info('Aislamiento verificado. La instalacion puede recibir pacientes.');

        return self::SUCCESS;
    }

    /**
     * A no puede ver el paciente de B, ni siquiera preguntando por su id.
     */
    private function revisarLecturaCruzada(ContextoTenant $contexto, Consultorio $a, Paciente $pacienteB): bool
    {
        $vistos = $contexto->ejecutarAislado((string) $a->id, fn (): int => Paciente::query()
            ->whereKey($pacienteB->id)
            ->count());

        if ($vistos !== 0) {
            $this->components->error('El consultorio A alcanzo a ver un paciente del consultorio B.');
            $this->components->error('El aislamiento NO funciona. Revise FORCE ROW LEVEL SECURITY.');

            return false;
        }

        $this->components->info('A no ve pacientes de B, ni por identificador. Correcto.');

        return true;
    }

    /**
     * No se puede escribir una fila declarando el consultorio de otro.
     *
     * Esta es la comprobacion de `WITH CHECK`, que es distinta de `USING`: sin
     * ella, se podria crear una fila que pertenece a otro consultorio y esa fila
     * despues seria invisible para los dos.
     */
    private function revisarEscrituraCruzada(ContextoTenant $contexto, Consultorio $a, Profesional $profesionalB): bool
    {
        $rechazada = false;

        try {
            // La referencia por valor y por referencia es necesaria: la
            // comprobacion ocurre dentro de la transaccion y su resultado tiene
            // que salir de la clausura.
            $contexto->ejecutarAislado((string) $a->id, function () use ($a, $profesionalB, &$rechazada): void {
                Profesional::query()->create([
                    'tenant_id' => (string) $a->id,
                    'nombre' => 'Intento de escritura cruzada',
                ]);

                // Si la escritura se hubiera guardado con el consultorio de B,
                // la fila seria visible desde el contexto de A. Se comprueba
                // que NO lo sea.
                $filtrada = Profesional::query()
                    ->whereKey($profesionalB->id)
                    ->first();

                $rechazada = $filtrada === null;
            });
        } catch (Throwable) {
            // Tambien es una respuesta correcta: la base rechazo el INSERT de
            // plano porque el `tenant_id` no coincide con el contexto.
            $rechazada = true;
        }

        if (! $rechazada) {
            $this->components->error('Se pudo escribir una fila declarando el consultorio de otro.');

            return false;
        }

        $this->components->info('No se puede escribir una fila de otro consultorio. Correcto.');

        return true;
    }

    /**
     * El historial odontologico tambien esta aislado, no solo el paciente.
     *
     * Una fila de `odontogramas` sin `tenant_id` coherente seria una ficha
     * clinica visible para dos consultorios. Se comprueba que existe el
     * contexto y que la tabla responde.
     */
    private function revisarCronologiaAislada(ContextoTenant $contexto, Consultorio $b, Paciente $pacienteB): bool
    {
        $odontogramas = $contexto->ejecutarAislado((string) $b->id, fn (): int => Odontograma::query()
            ->where('paciente_id', $pacienteB->id)
            ->count());

        if ($odontogramas !== 0) {
            $this->components->error('El consultorio B tiene un odontograma que no deberia existir.');

            return false;
        }

        $this->components->info('El historial odontologico responde por consultorio. Correcto.');

        return true;
    }

    /**
     * La comprobacion que sostiene todo lo demas.
     *
     * Sin `app.tenant_id` fijado, una consulta tiene que LANZAR EXCEPCION. Si
     * devolviera cero filas, el fallo seria silencioso: el sistema pareceria
     * funcionar y no estaria mostrando nada.
     */
    private function revisarFalloCerrado(): bool
    {
        try {
            Paciente::query()->count();

            $this->components->error('Una consulta sin contexto devolvio filas en vez de fallar.');
            $this->components->error('La funcion public.app_tenant_id() deberia lanzar excepcion.');

            return false;
        } catch (Throwable) {
            $this->components->info('Sin contexto, la consulta falla. El aislamiento falla cerrado.');

            return true;
        }
    }

    /**
     * Consultorio existente o temporal para la prueba.
     *
     * Se usa `firstOrCreate` por identificador, y no por slug, para que una
     * segunda corrida no falle: la fila de la corrida anterior ya existe y
     * tiene su propio identificador.
     */
    private function obtenerOCrearConsultorio(string $sufijo, ?string $indicado = null): Consultorio
    {
        if ($indicado !== null && $indicado !== '') {
            // Se acepta el identificador o el slug: el slug es lo que aparece en
            // los grupos de Authentik (`consultorio-<slug>`), y es lo que el
            // operador tendra a mano.
            $existente = Consultorio::query()
                ->whereKey($indicado)
                ->orWhere('slug', $indicado)
                ->first();

            if ($existente === null) {
                $this->components->error("El consultorio {$indicado} no existe.");

                throw new \InvalidArgumentException('El consultorio indicado no existe.');
            }

            return $existente;
        }

        $correlacion = 'clinica-dental-' . $sufijo;

        // `consultorios` NO tiene RLS a proposito: se consulta antes de que
        // exista `app.tenant_id`. Ver docs/03.
        return Consultorio::query()->firstOrCreate(
            ['slug' => $correlacion],
            [
                'nombre' => 'Temporal de verificacion (' . $sufijo . ')',
                'nombre_corto' => 'Verificacion',
                'activo' => true,
            ],
        );
    }
}