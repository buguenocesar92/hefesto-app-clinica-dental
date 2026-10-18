<script setup lang="ts">
/**
 * Agenda del dia, con el riesgo a la vista.
 *
 * ESTA ES LA PANTALLA QUE JUSTIFICA EL SISTEMA
 * --------------------------------------------
 * La lista de citas dice a quien se atiende y cuando. Esta dice, para cada
 * cita, SI HAY ALGO QUE IMPIDA ATENDER.
 *
 * Si recepcion no ve la alergia antes de que el paciente se siente, el sistema
 * es PEOR que una hoja de papel: la hoja al menos se mira. El aviso va pegado a
 * la cita y no en una pantalla aparte, porque un aviso aparte se lee una vez y
 * despues deja de leerse.
 *
 * LO QUE NO SE ABRE ACA
 * ---------------------
 * No se ve la cronologia de las piezas ni el plan de tratamiento. Eso vive en su
 * propia pantalla, con su propio permiso y su propia auditoria. Ver docs/03.
 */
import type { Cita, Paciente, Procedimiento, Profesional } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const fecha = ref(new Date().toISOString().slice(0, 10))
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)
const guardando = ref(false)
const anulando = ref<string | null>(null)

interface RespuestaAgenda {
    fecha: string
    total: number
    con_obstaculo: number
    requiere_revision: boolean
    citas: Cita[]
}

interface RespuestaPacientes {
    datos: Paciente[]
}

const { data: agenda, refresh } = await useAsyncData(
    'agenda',
    () => $fetch<RespuestaAgenda>('/api/agenda', { query: { fecha: fecha.value } }),
    { watch: [fecha] },
)

// Catalogos del formulario. Son datos simples: nombre, especialidad y duracion.
// La duracion se muestra porque de ella sale el largo de la cita, y agendar es
// un dato de agenda, no clinico.
const { data: profesionales } = await useAsyncData('profesionales-agenda', () =>
    $fetch<{ datos: Profesional[] }>('/api/administracion/profesionales/para-agenda'),
)

const { data: catalogo } = await useAsyncData('procedimientos-agenda', () =>
    $fetch<{ datos: Procedimiento[] }>('/api/administracion/procedimientos'),
)

const { data: pacientes } = await useAsyncData<RespuestaPacientes>('pacientes-agenda', () =>
    $fetch<RespuestaPacientes>('/api/pacientes', { query: { activo: true, por_pagina: 100 } }),
)

const nueva = reactive({
    paciente_id: '',
    profesional_id: '',
    procedimiento_id: '',
    fecha_inicio: '',
    motivo: '',
})

/**
 * Por que no se podria agendar, ANTES de apretar el boton.
 *
 * Se consulta mientras se elige el procedimiento, y no despues del error: el
 * error del servidor llega como mensaje plano, y es mucho mas util que la
 * pantalla diga "esto no se puede por esta alergia concreta" antes de que la
 * persona se entere por un rechazo.
 */
const { data: comprobacion, refresh: revisarBloqueo } = await useAsyncData(
    'bloqueo-previo',
    async () => {
        if (!nueva.paciente_id || !nueva.procedimiento_id) {
            return null
        }

        return await $fetch<{
            puede_agendar: boolean
            motivo: string | null
            puede_otorgar_desbloqueo: boolean
        }>(
            `/api/pacientes/${nueva.paciente_id}/desbloqueos/comprobar`,
            { query: { procedimiento_id: nueva.procedimiento_id } },
        )
    },
    { watch: [() => nueva.paciente_id, () => nueva.procedimiento_id] },
)

async function agendar() {
    guardando.value = true
    mensaje.value = null
    error.value = null

    try {
        await $fetch('/api/citas', {
            method: 'POST',
            body: {
                paciente_id: nueva.paciente_id,
                profesional_id: nueva.profesional_id,
                procedimiento_id: nueva.procedimiento_id,
                fecha_inicio: nueva.fecha_inicio,
                motivo: nueva.motivo || null,
            },
        })

        mensaje.value = 'Cita agendada.'
        nueva.fecha_inicio = ''
        nueva.motivo = ''

        await Promise.all([refresh(), revisarBloqueo()])
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo agendar.'
    } finally {
        guardando.value = false
    }
}

/**
 * Anular. NO es borrar: la fila queda con el estado y el motivo.
 *
 * Una cita borrada deja al consultorio sin registro de que ese paciente estuvo
 * agendado, y en un dia con agenda llena esa es justo la informacion que se
 * necesita despues.
 */
async function anular(cita: Cita) {
    const motivo = window.prompt('Motivo de la anulación (queda registrado en la agenda):')

    if (motivo === null || motivo.trim().length < 5) {
        return
    }

    anulando.value = cita.id
    error.value = null

    try {
        await $fetch(`/api/citas/${cita.id}`, {
            method: 'DELETE',
            body: { motivo: motivo.trim() },
        })

        mensaje.value = 'Cita anulada.'
        await refresh()
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo anular.'
    } finally {
        anulando.value = null
    }
}

function hora(iso: string | null): string {
    if (!iso) {
        return '—'
    }

    return new Date(iso).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
    <section>
        <h1>Agenda</h1>

        <div v-if="mensaje" class="aviso">{{ mensaje }}</div>

        <div v-if="error" class="error">
            {{ error }}
            <div class="error__detalle">
                Si el motivo es una alergia o una condición bloqueante, no es una falla: es el
                sistema haciendo lo que debe. Para atender igual, un profesional tiene que
                registrar un desbloqueo con su motivo escrito.
            </div>
        </div>

        <div v-if="agenda?.requiere_revision" class="error" style="margin-bottom: 12px;">
            Hoy hay {{ agenda.con_obstaculo }} cita(s) con un antecedente que impide atender.
        </div>

        <div class="panel">
            <div class="campo" style="max-width: 220px;">
                <label for="fecha">Día</label>
                <input id="fecha" v-model="fecha" type="date" />
            </div>

            <div v-if="(agenda?.citas ?? []).length === 0" class="vacio">
                No hay citas para este día.
            </div>

            <div
                v-for="cita in agenda?.citas ?? []"
                :key="cita.id"
                class="cita"
                :class="{
                    'cita--riesgo': cita.puede_atenderse === false,
                    'cita--anulada': cita.ocupa_agenda === false,
                }"
                :style="
                    cita.profesional?.color_agenda
                        ? { borderLeftColor: cita.profesional.color_agenda }
                        : undefined
                "
            >
                <div class="cita__hora">
                    {{ hora(cita.fecha_inicio) }}<br />
                    <span class="cita__detalle">{{ cita.duracion_minutos }} min</span>
                </div>

                <div class="cita__cuerpo">
                    <div class="cita__paciente">{{ cita.paciente?.nombre ?? 'Paciente' }}</div>

                    <div class="cita__detalle">
                        {{ cita.profesional?.nombre }}
                        <template v-if="cita.procedimiento">
                            · {{ cita.procedimiento.nombre }}
                        </template>
                        <template v-if="cita.silla">· Silla {{ cita.silla }}</template>
                    </div>

                    <div v-if="cita.motivo" class="cita__detalle">{{ cita.motivo }}</div>

                    <div v-if="cita.puede_atenderse === false" class="obstaculo">
                        NO SE PUEDE ATENDER
                        <ul style="margin: 4px 0 0; padding-left: 18px; font-weight: 400;">
                            <li v-for="o in cita.obstaculos ?? []" :key="o.registro_id">
                                {{ o.descripcion }}
                            </li>
                        </ul>
                    </div>

                    <div v-else-if="cita.sobre_desbloqueo" class="cita__detalle">
                        <span class="etiqueta etiqueta--alerta">Sobre desbloqueo</span>
                        Esta cita se agendó con una alergia activa y un desbloqueo registrado.
                    </div>
                </div>

                <div>
                    <span class="etiqueta">{{ cita.estado_etiqueta }}</span>

                    <button
                        v-if="cita.ocupa_agenda !== false"
                        type="button"
                        class="boton boton--chico boton--peligro"
                        style="margin-left: 8px;"
                        :disabled="anulando === cita.id"
                        @click="anular(cita)"
                    >
                        {{ anulando === cita.id ? 'Anulando…' : 'Anular' }}
                    </button>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2>Agendar</h2>

            <p class="ayuda">
                Si el paciente tiene una alergia o una condición bloqueante, el sistema no deja
                agendar. Es a propósito. La única forma de atender igual es que un profesional
                registre un desbloqueo con su motivo.
            </p>

            <form @submit.prevent="agendar">
                <div class="campo--linea">
                    <div class="campo">
                        <label for="paciente">Paciente</label>
                        <select id="paciente" v-model="nueva.paciente_id" required>
                            <option value="">Elija un paciente</option>
                            <option v-for="p in pacientes?.datos ?? []" :key="p.id" :value="p.id">
                                {{ p.nombre }}<template v-if="p.rut"> · {{ p.rut }}</template>
                            </option>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="profesional">Profesional</label>
                        <select id="profesional" v-model="nueva.profesional_id" required>
                            <option value="">Elija un profesional</option>
                            <option v-for="p in profesionales?.datos ?? []" :key="p.id" :value="p.id">
                                {{ p.nombre }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="campo--linea">
                    <div class="campo">
                        <label for="procedimiento">Procedimiento</label>
                        <select id="procedimiento" v-model="nueva.procedimiento_id" required>
                            <option value="">Elija un procedimiento</option>
                            <option
                                v-for="p in catalogo?.datos ?? []"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.nombre }} ({{ p.duracion_minutos }} min)
                            </option>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="inicio">Inicio</label>
                        <input id="inicio" v-model="nueva.fecha_inicio" type="datetime-local" required />
                    </div>
                </div>

                <div v-if="comprobacion && !comprobacion.puede_agendar" class="error">
                    <strong>Este procedimiento no se puede agendar.</strong>
                    <div class="error__detalle">{{ comprobacion.motivo }}</div>
                    <div v-if="comprobacion.puede_otorgar_desbloqueo" class="error__detalle">
                        Su rol permite registrar el desbloqueo. Vaya a la ficha del paciente,
                        sección de alergias.
                    </div>
                </div>

                <div class="campo">
                    <label for="motivo">Motivo (queda en la agenda)</label>
                    <input id="motivo" v-model="nueva.motivo" maxlength="240" />
                </div>

                <button
                    type="submit"
                    class="boton"
                    :disabled="guardando || (comprobacion !== null && !comprobacion.puede_agendar)"
                >
                    {{ guardando ? 'Agendando…' : 'Agendar' }}
                </button>
            </form>
        </div>
    </section>
</template>