<script setup lang="ts">
/**
 * Ficha de un paciente: identificacion, mapa dental y antecedentes.
 *
 * TRES NIVELES DE PERMISO EN UNA SOLA PANTALLA
 * ---------------------------------------------
 *
 *   1. Ficha de identificacion: los tres roles.
 *   2. Mapa dental (estado actual de las 32 piezas): los tres roles.
 *   3. Cronologia de las piezas y plan de tratamiento: profesional o
 *      administrador. Para recepcion, los enlaces ni siquiera aparecen.
 *
 * El nivel 3 se resuelve en el SERVIDOR, no escondiendo botones aca. Cada una de
 * esas pantallas tiene su propia ruta y queda anotada en la bitacora de
 * accesos. Ver docs/03.
 */
import type {
    Alergia,
    Condicion,
    Desbloqueo,
    Odontograma as TipoOdontograma,
    PacienteCompleto,
    Pieza,
    Procedimiento,
} from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const ruta = useRoute()
const id = String(ruta.params.id)

const { puedeVerCronologia, puedeRegistrarIntervencion, puedeDesbloquearAlergias } = useSesion()

const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

interface RespuestaFicha {
    paciente: PacienteCompleto
}

interface RespuestaMapa {
    paciente: { id: string; nombre: string }
    odontograma: TipoOdontograma
    incluye_notas: boolean
    piezas: Pieza[]
    leyenda: Array<{ valor: string; etiqueta: string; abreviatura: string; grupo: string }>
}

interface RespuestaAlergias {
    activas: number
    datos: Alergia[]
}

interface RespuestaCondiciones {
    bloqueantes_activas: number
    datos: Condicion[]
}

interface RespuestaDesbloqueos {
    vigentes: number
    datos: Desbloqueo[]
}

const { data: ficha } = await useAsyncData(`paciente-${id}`, () =>
    $fetch<RespuestaFicha>(`/api/pacientes/${id}`),
)

const { data: mapa, refresh: refrescarMapa } = await useAsyncData(`mapa-${id}`, () =>
    $fetch<RespuestaMapa>(`/api/pacientes/${id}/odontograma`),
)

const { data: alergias } = await useAsyncData(`alergias-${id}`, () =>
    $fetch<RespuestaAlergias>(`/api/pacientes/${id}/alergias`),
)

const { data: condiciones } = await useAsyncData(`condiciones-${id}`, () =>
    $fetch<RespuestaCondiciones>(`/api/pacientes/${id}/condiciones`),
)

const { data: desbloqueos, refresh: refrescarDesbloqueos } = await useAsyncData(
    `desbloqueos-${id}`,
    () => $fetch<RespuestaDesbloqueos>(`/api/pacientes/${id}/desbloqueos`),
)

/*
 * EL MAPA, EN ORDEN FDI
 * ---------------------
 * La notacion FDI pone el cuadrante 1 (superior derecho del paciente) arriba a
 * la izquierda de la pantalla. El mapa se dibuja al reves que el paciente a
 * proposito, para que coincida con la norma y con los impresos.
 *
 * Arcada superior, de izquierda a derecha en pantalla: 18, 17, ... 11.
 * Arcada inferior, de izquierda a derecha en pantalla: 41, 42, ... 48.
 */
const arcadaSuperior = computed(() =>
    (mapa.value?.piezas ?? [])
        .filter((p) => p.arcada === 'superior')
        .sort((a, b) => b.numero_fdi - a.numero_fdi),
)

const arcadaInferior = computed(() =>
    (mapa.value?.piezas ?? [])
        .filter((p) => p.arcada === 'inferior')
        .sort((a, b) => a.numero_fdi - b.numero_fdi),
)

const piezaSeleccionada = ref<Pieza | null>(null)

/**
 * Color de una pieza, segun su estado.
 *
 * Tres clases y no quince: sano, fuera de la boca y reemplazo. Un mapa con
 * quince colores deja de leerse, y el color que importa es "esta en riesgo".
 */
function clasePieza(pieza: Pieza): string {
    if (pieza.estado === 'sano') {
        return 'pieza pieza--sana'
    }

    const estado = mapa.value?.leyenda.find((e) => e.valor === pieza.estado)

    if (estado?.grupo === 'fuera de la boca') {
        return 'pieza pieza--fuera'
    }

    if (estado?.grupo === 'reemplazo') {
        return 'pieza pieza--reemplazo'
    }

    if (pieza.estado === 'caries' || pieza.estado === 'fractura') {
        return 'pieza pieza--riesgo'
    }

    return 'pieza'
}

/* -----------------------------------------------------------------------------
 * Registrar una intervencion
 * -------------------------------------------------------------------------- */

const { data: catalogo } = await useAsyncData('procedimientos-paciente', () =>
    $fetch<{ datos: Procedimiento[] }>('/api/administracion/procedimientos'),
)

const intervencion = reactive({
    abierta: false,
    numero_fdi: 0,
    estado_resultante: 'obturado',
    descripcion: '',
    procedimiento_id: '',
    fecha: '',
})

const transiciones = ref<Array<{ valor: string; etiqueta: string }>>([])

async function abrirIntervencion(pieza: Pieza) {
    piezaSeleccionada.value = pieza
    intervencion.abierta = false
    intervencion.numero_fdi = pieza.numero_fdi
    intervencion.estado_resultante = pieza.estado
    intervencion.descripcion = ''

    try {
        const respuesta = await $fetch<{
            estado_actual: string
            transiciones_permitidas: Array<{ valor: string; etiqueta: string }>
        }>(`/api/pacientes/${id}/odontograma/${mapa.value?.odontograma.id}/transiciones/${pieza.numero_fdi}`)

        transiciones.value = respuesta.transiciones_permitidas
    } catch {
        transiciones.value = []
    }
}

async function registrar() {
    error.value = null
    mensaje.value = null

    try {
        await $fetch(`/api/pacientes/${id}/intervenciones`, {
            method: 'POST',
            body: {
                numero_fdi: intervencion.numero_fdi,
                estado_resultante: intervencion.estado_resultante,
                descripcion: intervencion.descripcion,
                procedimiento_id: intervencion.procedimiento_id || null,
                fecha: intervencion.fecha || null,
            },
        })

        mensaje.value =
            'Intervención registrada. La pieza se actualizó y el hecho quedó en la cronología.'

        intervencion.abierta = false
        await refrescarMapa()
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo registrar.'
    }
}

/* -----------------------------------------------------------------------------
 * Desbloquear una alergia
 * -------------------------------------------------------------------------- */

const alergiaAAutorizar = ref<Alergia | null>(null)
const motivoDesbloqueo = ref('')

async function registrarDesbloqueo() {
    if (alergiaAAutorizar.value === null || motivoDesbloqueo.value.trim().length < 15) {
        return
    }

    error.value = null

    try {
        await $fetch(`/api/pacientes/${id}/desbloqueos`, {
            method: 'POST',
            body: {
                alergia_id: alergiaAAutorizar.value.id,
                motivo: motivoDesbloqueo.value.trim(),
            },
        })

        mensaje.value =
            'Desbloqueo registrado. Queda anotado en la historia del paciente y ligado a la próxima cita.'

        motivoDesbloqueo.value = ''
        alergiaAAutorizar.value = null
        await refrescarDesbloqueos()
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo desbloquear.'
    }
}
</script>

<template>
    <section>
        <p class="ayuda">
            <NuxtLink to="/pacientes">← Volver al listado</NuxtLink>
        </p>

        <div v-if="mensaje" class="aviso">{{ mensaje }}</div>
        <div v-if="error" class="error">{{ error }}</div>

        <!-- Identificacion. Los tres roles. -->
        <div v-if="ficha" class="panel">
            <h1>{{ ficha.paciente.nombre }}</h1>

            <table>
                <tbody>
                    <tr>
                        <th>RUT</th>
                        <td>{{ ficha.paciente.rut ?? 'Sin RUT registrado' }}</td>
                        <th>Fecha de nacimiento</th>
                        <td>{{ ficha.paciente.fecha_nacimiento ?? '—' }} ({{ ficha.paciente.edad ?? '?' }} años)</td>
                    </tr>
                    <tr>
                        <th>Sexo</th>
                        <td>{{ ficha.paciente.sexo_etiqueta }}</td>
                        <th>Previsión</th>
                        <td>{{ ficha.paciente.prevision ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th>Teléfono</th>
                        <td>{{ ficha.paciente.telefono ?? '—' }}</td>
                        <th>Correo</th>
                        <td>{{ ficha.paciente.correo ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th>Última atención</th>
                        <td>{{ ficha.paciente.ultima_atencion }}</td>
                        <th>Citas registradas</th>
                        <td>{{ ficha.paciente.citas_totales }}</td>
                    </tr>
                </tbody>
            </table>

            <p class="ayuda">
                Los datos de identificación son de los tres roles. La cronología de las piezas,
                más abajo, no: es información de salud y se audita.
            </p>
        </div>

        <!-- Alergias y condiciones. Los tres roles, y a propósito. -->
        <div class="panel">
            <h2>Alergias y condiciones</h2>

            <p class="ayuda">
                Recepción ve esto. Si no lo viera, el sistema sería peor que una hoja de papel:
                aparentaría que no hay riesgo cuando en realidad lo está ocultando.
            </p>

            <h3>Alergias ({{ alergias?.activas ?? 0 }} activas)</h3>

            <table>
                <thead>
                    <tr>
                        <th>Sustancia</th>
                        <th>Reacción</th>
                        <th>Gravedad</th>
                        <th>Estado</th>
                        <th v-if="puedeDesbloquearAlergias">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in alergias?.datos ?? []" :key="a.id">
                        <td>{{ a.sustancia }}</td>
                        <td>{{ a.reaccion }}</td>
                        <td>
                            <span
                                class="etiqueta"
                                :class="a.puede_ser_fatal ? 'etiqueta--riesgo' : 'etiqueta--alerta'"
                            >
                                {{ a.severidad_etiqueta }}
                            </span>
                        </td>
                        <td>
                            {{ a.activa ? 'Activa' : `Cerrada el ${a.fecha_cierre ?? '—'}` }}
                        </td>
                        <td v-if="puedeDesbloquearAlergias">
                            <button
                                v-if="a.activa"
                                type="button"
                                class="boton boton--chico boton--peligro"
                                @click="alergiaAAutorizar = a"
                            >
                                Autorizar
                            </button>
                        </td>
                    </tr>

                    <tr v-if="(alergias?.datos ?? []).length === 0">
                        <td colspan="5" class="vacio">Sin alergias registradas.</td>
                    </tr>
                </tbody>
            </table>

            <h3 style="margin-top: 16px;">
                Condiciones de salud ({{ condiciones?.bloqueantes_activas ?? 0 }} bloqueantes)
            </h3>

            <table>
                <thead>
                    <tr>
                        <th>Condición</th>
                        <th>Detalle</th>
                        <th>Bloquea</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in condiciones?.datos ?? []" :key="c.id">
                        <td>{{ c.condicion }}</td>
                        <td>{{ c.detalle ?? '—' }}</td>
                        <td>
                            <span v-if="c.bloquea_atencion" class="etiqueta etiqueta--riesgo">
                                Sí
                            </span>
                            <span v-else class="etiqueta">No</span>
                        </td>
                    </tr>

                    <tr v-if="(condiciones?.datos ?? []).length === 0">
                        <td colspan="3" class="vacio">Sin condiciones registradas.</td>
                    </tr>
                </tbody>
            </table>

            <h3 style="margin-top: 16px;">Desbloqueos vigentes ({{ desbloqueos?.vigentes ?? 0 }})</h3>

            <table>
                <thead>
                    <tr>
                        <th>Sobre</th>
                        <th>Motivo</th>
                        <th>Concedido por</th>
                        <th>Vigente hasta</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in desbloqueos?.datos ?? []" :key="d.id">
                        <td>{{ d.alergia?.sustancia ?? d.condicion?.condicion ?? '—' }}</td>
                        <td>{{ d.motivo }}</td>
                        <td>{{ d.concedido_por ?? '—' }}</td>
                        <td>
                            <span v-if="d.vigente_hasta === null" class="etiqueta etiqueta--alerta">
                                Sin vencimiento
                            </span>
                            <span v-else>{{ d.vigente_hasta }}</span>
                        </td>
                    </tr>

                    <tr v-if="(desbloqueos?.datos ?? []).length === 0">
                        <td colspan="4" class="vacio">No hay desbloqueos registrados.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- El mapa. Los tres roles. -->
        <div class="panel">
            <h2>Odontograma</h2>

            <p class="ayuda">
                Estado actual de las 32 piezas permanentes, en notación FDI. Este mapa no se
                edita: para cambiar una pieza hay que registrar una intervención, que escribe
                un hecho con fecha y con profesional.
            </p>

            <p class="mapa__arcada">Arcada superior ({{ mapa?.odontograma.origen ?? 'nuevo' }})</p>
            <div class="mapa">
                <button
                    v-for="pieza in arcadaSuperior"
                    :key="pieza.numero_fdi"
                    type="button"
                    :class="clasePieza(pieza)"
                    @click="abrirIntervencion(pieza)"
                >
                    <span class="pieza__numero">{{ pieza.numero_fdi }}</span>
                    <span class="pieza__estado">{{ pieza.abreviatura }}</span>
                </button>
            </div>

            <p class="mapa__arcada">Arcada inferior</p>
            <div class="mapa">
                <button
                    v-for="pieza in arcadaInferior"
                    :key="pieza.numero_fdi"
                    type="button"
                    :class="clasePieza(pieza)"
                    @click="abrirIntervencion(pieza)"
                >
                    <span class="pieza__numero">{{ pieza.numero_fdi }}</span>
                    <span class="pieza__estado">{{ pieza.abreviatura }}</span>
                </button>
            </div>

            <div class="mapa__leyenda">
                <span><span class="etiqueta etiqueta--exito">S</span> sano</span>
                <span><span class="etiqueta etiqueta--alerta">R</span> reemplazo</span>
                <span><span class="etiqueta">F</span> fuera de la boca</span>
                <span v-if="!mapa?.incluye_notas">
                    Las notas de las piezas no se muestran con su rol.
                </span>
            </div>

            <!-- Registrar intervencion: solo quien puede firmar un hecho clinico. -->
            <div v-if="piezaSeleccionada && puedeRegistrarIntervencion" class="panel" style="margin-top: 16px;">
                <h3>
                    Pieza {{ piezaSeleccionada.nombre }} — estado actual:
                    {{ piezaSeleccionada.estado_etiqueta }}
                </h3>

                <p class="ayuda">
                    Hace {{ piezaSeleccionada.dias_en_estado ?? '?' }} días que está así.
                    La intervención queda firmada por usted, con su nombre y la fecha.
                </p>

                <form @submit.prevent="registrar">
                    <div class="campo--linea">
                        <div class="campo">
                            <label for="estado">Estado resultante</label>
                            <select id="estado" v-model="intervencion.estado_resultante" required>
                                <option v-for="t in transiciones" :key="t.valor" :value="t.valor">
                                    {{ t.etiqueta }}
                                </option>
                            </select>
                        </div>

                        <div class="campo">
                            <label for="fecha-intervencion">Fecha de la atención</label>
                            <input
                                id="fecha-intervencion"
                                v-model="intervencion.fecha"
                                type="date"
                            />
                        </div>
                    </div>

                    <div class="campo">
                        <label for="descripcion">Qué se hizo (queda escrito para siempre)</label>
                        <textarea
                            id="descripcion"
                            v-model="intervencion.descripcion"
                            rows="3"
                            maxlength="2000"
                            required
                        />
                    </div>

                    <div class="campo">
                        <label for="procedimiento-intervencion">Procedimiento del catálogo (opcional)</label>
                        <select id="procedimiento-intervencion" v-model="intervencion.procedimiento_id">
                            <option value="">Sin procedimiento</option>
                            <option
                                v-for="p in catalogo?.datos ?? []"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.nombre }}
                            </option>
                        </select>
                    </div>

                    <button type="submit" class="boton">Registrar intervención</button>
                </form>
            </div>

            <p v-if="piezaSeleccionada && !puedeRegistrarIntervencion" class="ayuda" style="margin-top: 12px;">
                Puede ver el mapa, pero registrar una intervención requiere rol profesional con
                ficha de profesional. Una intervención queda firmada por quien la hace: sin tratante
                no se puede sostener.
            </p>
        </div>

        <!-- Historial: solo profesional o administrador. -->
        <div v-if="puedeVerCronologia" class="panel">
            <h2>Historial por fecha</h2>

            <p class="ayuda">
                Agrupado por día, que es como se pregunta de verdad: qué le hicimos en esa
                visita. Cada apertura queda registrada en la bitácora de accesos.
            </p>

            <NuxtLink class="boton boton--secundario" :to="`/odontograma/${id}`">
                Ver el mapa y la cronología completa
            </NuxtLink>
        </div>

        <div v-else class="panel">
            <h2>Historial</h2>
            <p class="ayuda">
                La cronología de las piezas y los planes de tratamiento no se abren con su rol.
                No es una falla del sistema ni una decisión de pantalla: es información de salud
                y el acceso depende del rol, no del puesto que alguien ocupa.
            </p>
        </div>

        <!-- Formulario de desbloqueo. -->
        <div v-if="alergiaAAutorizar" class="panel">
            <h2>Autorizar una atención sobre una alergia</h2>

            <p class="ayuda">
                Alergia a <strong>{{ alergiaAAutorizar.sustancia }}</strong>:
                {{ alergiaAAutorizar.reaccion }}
            </p>

            <p class="ayuda">
                Esta autorización queda en la historia del paciente con su nombre, la fecha y el
                motivo. No se puede editar ni borrar después. El motivo es obligatorio y tiene al
                menos quince caracteres: una decisión clínica sin explicación no sirve para nada.
            </p>

            <div class="campo">
                <label for="motivo-desbloqueo">Motivo de la autorización</label>
                <textarea id="motivo-desbloqueo" v-model="motivoDesbloqueo" rows="3" maxlength="400" />
            </div>

            <button
                type="button"
                class="boton"
                :disabled="motivoDesbloqueo.trim().length < 15"
                @click="registrarDesbloqueo"
            >
                Registrar desbloqueo
            </button>
        </div>
    </section>
</template>