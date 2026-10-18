<script setup lang="ts">
/**
 * Citas de un paciente: su historial de agenda.
 *
 * POR QUE ESTA PAGINA ES DISTINTA DE LA AGENDA
 * ---------------------------------------------
 * La agenda es "hoy, para todos". Esta es "este paciente, en el tiempo". La
 * pregunta es distinta y por eso tiene su propia pantalla: "cuando fue la
 * ultima vez que lo vimos, y que le hicimos".
 *
 * LO QUE NO SE MUESTRA ACA
 * ------------------------
 * El desenlace de la cita ("atendida", "no asistio") sí es dato clínico, porque
 * queda en la historia del paciente, pero NO se muestra el contenido de las
 * notas de la intervencion. Eso vive en el historial odontologico, que se abre
 * en otra pantalla, con otro permiso y con su auditoria. Ver docs/03.
 */
import type { Cita } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const ruta = useRoute()
const id = String(ruta.params.id)

const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)
const guardando = ref<string | null>(null)

const { data: citas, refresh } = await useAsyncData(
    `citas-paciente-${id}`,
    () => $fetch<{ total: number; citas: Cita[] }>('/api/citas', { query: { paciente_id: id } }),
)

/**
 * El desenlace de una cita se escribe desde el sillón, no desde recepción.
 *
 * Que un paciente haya sido atendido es un hecho clínico. Por eso la ruta que
 * lo registra exige rol profesional, aunque anular una cita no lo exija:
 * anular es una decisión de agenda y esto no.
 */
const { usuario } = useSesion()

async function registrarDesenlace(cita: Cita, estado: 'atendida' | 'no_asistio') {
    guardando.value = cita.id
    error.value = null
    mensaje.value = null

    try {
        await $fetch(`/api/citas/${cita.id}/desenlace`, {
            method: 'POST',
            body: { estado },
        })

        mensaje.value = estado === 'atendida' ? 'Cita marcada como atendida.' : 'Cita marcada como no asistió.'
        await refresh()
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo registrar.'
    } finally {
        guardando.value = null
    }
}

function fecha(iso: string | null): string {
    if (!iso) {
        return '—'
    }

    return new Date(iso).toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function hora(iso: string | null): string {
    if (!iso) {
        return ''
    }

    return new Date(iso).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
    <section>
        <p class="ayuda">
            <NuxtLink to="/agenda">← Volver a la agenda</NuxtLink>
        </p>

        <h1>Todas las citas</h1>

        <p class="ayuda">
            Historial de agenda del consultorio. Para el historial de un paciente en
            particular, vaya a su ficha.
        </p>

        <div v-if="mensaje" class="aviso">{{ mensaje }}</div>
        <div v-if="error" class="error">{{ error }}</div>

        <div class="panel">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Profesional</th>
                        <th>Procedimiento</th>
                        <th>Motivo</th>
                        <th>Estado</th>
                        <th v-if="usuario?.rol === 'profesional'">Desenlace</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="cita in citas?.citas ?? []" :key="cita.id">
                        <td>{{ fecha(cita.fecha_inicio) }}</td>
                        <td>{{ hora(cita.fecha_inicio) }}</td>
                        <td>{{ cita.profesional?.nombre ?? '—' }}</td>
                        <td>{{ cita.procedimiento?.nombre ?? '—' }}</td>
                        <td>{{ cita.motivo ?? '—' }}</td>
                        <td>
                            <span
                                class="etiqueta"
                                :class="
                                    cita.ocupa_agenda === false ? '' : cita.sobre_desbloqueo ? 'etiqueta--alerta' : ''
                                "
                            >
                                {{ cita.estado_etiqueta }}
                            </span>
                        </td>
                        <td v-if="usuario?.rol === 'profesional'">
                            <template v-if="cita.ocupa_agenda !== false">
                                <button
                                    type="button"
                                    class="boton boton--chico boton--secundario"
                                    style="margin-right: 6px;"
                                    :disabled="guardando === cita.id"
                                    @click="registrarDesenlace(cita, 'atendida')"
                                >
                                    Atendida
                                </button>
                                <button
                                    type="button"
                                    class="boton boton--chico boton--secundario"
                                    :disabled="guardando === cita.id"
                                    @click="registrarDesenlace(cita, 'no_asistio')"
                                >
                                    No asistió
                                </button>
                            </template>
                            <template v-else>—</template>
                        </td>
                    </tr>

                    <tr v-if="(citas?.citas ?? []).length === 0">
                        <td colspan="7" class="vacio">Este paciente no tiene citas registradas.</td>
                    </tr>
                </tbody>
            </table>

            <p class="ayuda">
                Las citas no se borran: se anulan con su motivo. Una cita borrada dejaría al
                consultorio sin registro de que el paciente estuvo agendado.
            </p>
        </div>
    </section>
</template>