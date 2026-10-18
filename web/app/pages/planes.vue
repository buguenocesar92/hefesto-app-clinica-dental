<script setup lang="ts">
/**
 * Planes de tratamiento.
 *
 * Un plan es un documento clinico con lo que se propone hacer: que piezas, en
 * que orden, con que procedimiento y por cuanto. Lo propone el profesional y lo
 * aprueba el paciente.
 *
 * POR QUE RECEPCION NO LO VE
 * -------------------------
 * El diagnostico y el presupuesto clinico son informacion de salud. La persona
 * del mostrador necesita saber si el paciente tiene cita, no que le van a sacar
 * seis piezas. Ver docs/03.
 *
 * UN PLAN NO SE BORRA
 * -------------------
 * Se suspende o se rechaza. Los dos estados existen y quedan en el historial del
 * consultorio. Borrar el documento dejaria al paciente sin registro de que se le
 * propuso algo y de que no se hizo.
 */
import type { Plan } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const { puedeVerPlanes, puedeEditarPlanes } = useSesion()

const pacienteId = ref('')
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

const { data: planes, refresh } = await useAsyncData(
    'planes',
    async () => {
        if (pacienteId.value === '') {
            return null
        }

        return await $fetch<{ total: number; planes: Plan[] }>(
            `/api/pacientes/${pacienteId.value}/planes`,
        )
    },
    { watch: [pacienteId] },
)

const { data: pacientes } = await useAsyncData('pacientes-planes', () =>
    $fetch<{ datos: Array<{ id: string; nombre: string }> }>('/api/pacientes', {
        query: { activo: true, por_pagina: 100 },
    }),
)

/**
 * Los estados a los que se puede pasar desde el estado actual.
 *
 * El servidor los vuelve a comprobar: esta lista solo evita ofrecer un boton
 * que va a ser rechazado.
 */
const transiciones: Record<string, Array<{ valor: string; etiqueta: string }>> = {
    borrador: [
        { valor: 'presentado', etiqueta: 'Marcar como presentado' },
        { valor: 'rechazado', etiqueta: 'Marcar como rechazado' },
    ],
    presentado: [
        { valor: 'aprobado', etiqueta: 'El paciente aprobó' },
        { valor: 'rechazado', etiqueta: 'El paciente rechazó' },
    ],
    aprobado: [
        { valor: 'en_curso', etiqueta: 'Comenzar' },
        { valor: 'suspendido', etiqueta: 'Suspender' },
    ],
    en_curso: [
        { valor: 'completado', etiqueta: 'Marcar como completado' },
        { valor: 'suspendido', etiqueta: 'Suspender' },
    ],
    suspendido: [
        { valor: 'en_curso', etiqueta: 'Reanudar' },
        { valor: 'rechazado', etiqueta: 'Dar por rechazado' },
    ],
    rechazado: [{ valor: 'borrador', etiqueta: 'Volver a borrador' }],
    completado: [],
}

async function cambiarEstado(plan: Plan, estado: string) {
    error.value = null
    mensaje.value = null

    try {
        await $fetch(`/api/planes/${plan.id}/estado`, {
            method: 'POST',
            body: { estado },
        })

        mensaje.value = 'Estado del plan actualizado.'
        await refresh()
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo cambiar el estado.'
    }
}

function peso(plan: Plan): string {
    return plan.presupuesto_clp === null
        ? '—'
        : new Intl.NumberFormat('es-CL').format(plan.presupuesto_clp)
}
</script>

<template>
    <section>
        <h1>Planes de tratamiento</h1>

        <div v-if="!puedeVerPlanes" class="panel">
            <div class="vacio">
                Los planes de tratamiento no se abren con su rol. Son información de salud: el
                diagnóstico y el presupuesto clínico no son dato de agenda.
            </div>
        </div>

        <template v-else>
            <div v-if="mensaje" class="aviso">{{ mensaje }}</div>
            <div v-if="error" class="error">{{ error }}</div>

            <div class="panel">
                <div class="campo" style="max-width: 420px;">
                    <label for="paciente">Paciente</label>
                    <select id="paciente" v-model="pacienteId">
                        <option value="">Elija un paciente</option>
                        <option v-for="p in pacientes?.datos ?? []" :key="p.id" :value="p.id">
                            {{ p.nombre }}
                        </option>
                    </select>
                </div>

                <p class="ayuda">
                    Elija un paciente para ver sus planes. Los planes se crean desde la ficha del
                    paciente, y quedan como borrador hasta que se los presenta.
                </p>
            </div>

            <div v-for="plan in planes?.planes ?? []" :key="plan.id" class="panel">
                <h2>
                    {{ plan.nombre }}
                    <span class="etiqueta" style="margin-left: 8px;">{{ plan.estado_etiqueta }}</span>
                </h2>

                <p class="ayuda">
                    {{ plan.profesional }} · desde {{ plan.fecha_inicio }} ·
                    {{ peso(plan) }} pesos
                    <template v-if="plan.avance !== null">
                        · {{ plan.avance }}% de las etapas realizadas
                    </template>
                </p>

                <p v-if="plan.diagnostico"><strong>Diagnóstico:</strong> {{ plan.diagnostico }}</p>

                <table style="margin-top: 12px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Etapa</th>
                            <th>Pieza</th>
                            <th>Procedimiento</th>
                            <th>Prevista</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="etapa in plan.etapas" :key="etapa.id">
                            <td>{{ etapa.orden }}</td>
                            <td>{{ etapa.nombre }}</td>
                            <td>{{ etapa.numero_fdi ?? '—' }}</td>
                            <td>{{ etapa.procedimiento ?? '—' }}</td>
                            <td>{{ etapa.fecha_prevista ?? '—' }}</td>
                            <td>{{ etapa.estado_etiqueta }}</td>
                        </tr>

                        <tr v-if="plan.etapas.length === 0">
                            <td colspan="6" class="vacio">Este plan todavía no tiene etapas.</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="puedeEditarPlanes && (transiciones[plan.estado] ?? []).length > 0" style="margin-top: 12px;">
                    <button
                        v-for="opcion in transiciones[plan.estado]"
                        :key="opcion.valor"
                        type="button"
                        class="boton boton--chico boton--secundario"
                        style="margin-right: 8px;"
                        @click="cambiarEstado(plan, opcion.valor)"
                    >
                        {{ opcion.etiqueta }}
                    </button>
                </div>
            </div>

            <div v-if="pacienteId !== '' && (planes?.planes ?? []).length === 0" class="panel">
                <div class="vacio">Este paciente no tiene planes de tratamiento.</div>
            </div>
        </template>
    </section>
</template>