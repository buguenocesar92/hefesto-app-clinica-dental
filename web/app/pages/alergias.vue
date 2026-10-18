<script setup lang="ts">
/**
 * Vista global de alergias y desbloqueos del consultorio.
 *
 * POR QUE ESTA PANTALLA EXISTE Y NO SÓLO LA FICHA DEL PACIENTE
 * ------------------------------------------------------------
 * Porque la pregunta que se hace en un sillón no es "qué alergias tiene este
 * paciente" sino "a quién de los que tengo hoy hay que mirar antes de que se
 * siente". Es una pregunta de agenda, y se responde con una lista.
 *
 * No se abre ninguna historia clinica: los datos que aparecen son sustancia,
 * reaccion y gravedad. La cronologia de las piezas no esta aca.
 */
import type { Alergia, Desbloqueo } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const buscar = ref('')
const soloGraves = ref(false)

interface PacienteMinimo {
    id: string
    nombre: string
}

interface Entrada {
    alergia: Alergia
    paciente: PacienteMinimo
}

const { data: pacientes } = await useAsyncData('pacientes-alergias', () =>
    $fetch<{ datos: Array<PacienteMinimo & { activo: boolean }> }>('/api/pacientes', {
        query: { activo: true, por_pagina: 100 },
    }),
)

const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

/**
 * Alergias activas de cada paciente con antecedentes.
 *
 * Se recorre la lista de pacientes y se pide la de cada uno, en vez de hacer
 * una consulta gigante en el cliente: la API decide qué se puede ver, y la
 * lista de un consultorio chico cabe de sobra en una pantalla.
 */
const { data: entradas, pending } = await useAsyncData(
    'alergias-consultorio',
    async () => {
        const resultado: Entrada[] = []

        for (const paciente of pacientes.value?.datos ?? []) {
            try {
                const respuesta = await $fetch<{ datos: Alergia[] }>(
                    `/api/pacientes/${paciente.id}/alergias`,
                )

                for (const alergia of respuesta.datos.filter((a) => a.activa)) {
                    resultado.push({ alergia, paciente })
                }
            } catch {
                // Si una ficha no se puede abrir, se sigue con las demas. Una
                // pantalla que se cae entera porque un paciente dio error no
                // sirve en un mostrador.
            }
        }

        return resultado
    },
    { watch: [pacientes] },
)

const visibles = computed(() => {
    const texto = buscar.value.trim().toLowerCase()

    return (entradas.value ?? []).filter((entrada) => {
        if (soloGraves.value && !entrada.alergia.puede_ser_fatal) {
            return false
        }

        if (texto === '') {
            return true
        }

        return (
            entrada.paciente.nombre.toLowerCase().includes(texto) ||
            entrada.alergia.sustancia.toLowerCase().includes(texto)
        )
    })
})

/** Desbloqueos vigentes de todo el consultorio. */
const { data: desbloqueos } = await useAsyncData('desbloqueos-consultorio', async () => {
    const resultado: Array<Desbloqueo & { paciente: PacienteMinimo }> = []

    for (const paciente of pacientes.value?.datos ?? []) {
        try {
            const respuesta = await $fetch<{ datos: Desbloqueo[] }>(
                `/api/pacientes/${paciente.id}/desbloqueos`,
            )

            for (const desbloqueo of respuesta.datos.filter((d) => d.vigente_en_hoy !== false)) {
                resultado.push({ ...desbloqueo, paciente })
            }
        } catch {
            // Ver la nota de arriba.
        }
    }

    return resultado
})

const { data: escala } = await useAsyncData('escala-alergias', () =>
    $fetch<{
        escala: Array<{ valor: string; etiqueta: string; puede_ser_fatal: boolean; urgencia: number }>
        nota: string
    }>('/api/escala-alergias'),
)

async function cerrar(entrada: Entrada) {
    const motivo = window.prompt('Motivo del cierre (queda registrado):')

    if (motivo === null || motivo.trim().length < 10) {
        return
    }

    error.value = null

    try {
        await $fetch(`/api/pacientes/alergias/${entrada.alergia.id}/cerrar`, {
            method: 'POST',
            body: { motivo_cierre: motivo.trim() },
        })

        mensaje.value = 'Alergia cerrada. El registro se conserva.'
        await refreshNuxtData('alergias-consultorio')
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo cerrar.'
    }
}
</script>

<template>
    <section>
        <h1>Alergias y desbloqueos</h1>

        <div v-if="mensaje" class="aviso">{{ mensaje }}</div>
        <div v-if="error" class="error">{{ error }}</div>

        <p class="ayuda">
            Una alergia no se borra: se cierra con fecha y motivo. El paciente sigue siendo
            alérgico dentro de tres años y va a volver, y el motivo del cierre es lo que va a
            necesitar el próximo profesional.
        </p>

        <div class="panel">
            <div class="campo--linea">
                <div class="campo">
                    <label for="buscar">Buscar por paciente o sustancia</label>
                    <input id="buscar" v-model="buscar" />
                </div>

                <div class="campo">
                    <label for="graves">Filtro</label>
                    <select id="graves" v-model="soloGraves">
                        <option :value="false">Todas las alergias</option>
                        <option :value="true">Solo las graves</option>
                    </select>
                </div>
            </div>

            <p v-if="pending" class="ayuda">Cargando…</p>

            <table v-else>
                <thead>
                    <tr>
                        <th>Paciente</th>
                        <th>Sustancia</th>
                        <th>Reacción</th>
                        <th>Gravedad</th>
                        <th>Desde</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entrada in visibles" :key="entrada.alergia.id">
                        <td>
                            <NuxtLink :to="`/pacientes/${entrada.paciente.id}`">
                                {{ entrada.paciente.nombre }}
                            </NuxtLink>
                        </td>
                        <td><strong>{{ entrada.alergia.sustancia }}</strong></td>
                        <td>{{ entrada.alergia.reaccion }}</td>
                        <td>
                            <span
                                class="etiqueta"
                                :class="
                                    entrada.alergia.puede_ser_fatal
                                        ? 'etiqueta--riesgo'
                                        : 'etiqueta--alerta'
                                "
                            >
                                {{ entrada.alergia.severidad_etiqueta }}
                            </span>
                        </td>
                        <td>{{ entrada.alergia.fecha_registro ?? '—' }}</td>
                        <td>
                            <button type="button" class="boton boton--chico boton--secundario" @click="cerrar(entrada)">
                                Cerrar
                            </button>
                        </td>
                    </tr>

                    <tr v-if="visibles.length === 0">
                        <td colspan="6" class="vacio">
                            No hay alergias activas que coincidan.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h2>Desbloqueos vigentes</h2>

            <p class="ayuda">
                Cada desbloqueo es la autorización de una atención que el sistema considera
                prohibida. Queda con el nombre de quien la firmó y con su motivo, y se liga a la
                cita que se agendó con él.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Paciente</th>
                        <th>Sobre</th>
                        <th>Motivo</th>
                        <th>Firmado por</th>
                        <th>Vigente hasta</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="desbloqueo in desbloqueos ?? []" :key="desbloqueo.id">
                        <td>
                            <NuxtLink :to="`/pacientes/${desbloqueo.paciente.id}`">
                                {{ desbloqueo.paciente.nombre }}
                            </NuxtLink>
                        </td>
                        <td>{{ desbloqueo.alergia?.sustancia ?? desbloqueo.condicion?.condicion ?? '—' }}</td>
                        <td>{{ desbloqueo.motivo }}</td>
                        <td>{{ desbloqueo.concedido_por ?? '—' }}</td>
                        <td>
                            <span v-if="desbloqueo.vigente_hasta === null" class="etiqueta etiqueta--alerta">
                                Sin vencimiento
                            </span>
                            <span v-else>{{ desbloqueo.vigente_hasta }}</span>
                        </td>
                    </tr>

                    <tr v-if="(desbloqueos ?? []).length === 0">
                        <td colspan="5" class="vacio">No hay desbloqueos vigentes.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="escala" class="panel">
            <h2>Escala de gravedad</h2>

            <p class="ayuda">{{ escala.nota }}</p>

            <table>
                <thead>
                    <tr>
                        <th>Gravedad</th>
                        <th>Puede ser fatal</th>
                        <th>Urgencia</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="nivel in escala.escala" :key="nivel.valor">
                        <td>{{ nivel.etiqueta }}</td>
                        <td>{{ nivel.puede_ser_fatal ? 'Sí' : 'No' }}</td>
                        <td>{{ nivel.urgencia }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>