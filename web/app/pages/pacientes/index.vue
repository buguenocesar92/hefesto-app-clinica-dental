<script setup lang="ts">
/**
 * Listado de pacientes: la ficha de IDENTIFICACION.
 *
 * Lo que se ve aca es dato personal: nombre, RUT, teléfono, prevision. No es
 * dato de salud, y por eso lo ven los tres roles: recepción tiene que saber a
 * quién agenda.
 *
 * El historial odontológico de estos mismos pacientes vive en otras pantallas,
 * que exigen rol profesional y quedan auditadas. Ver docs/03.
 */
import type { Paciente } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const buscar = ref('')
const soloActivos = ref(true)

interface RespuestaPacientes {
    total: number
    pagina_actual: number
    por_pagina: number
    datos: Paciente[]
}

const { data: pacientes, refresh } = await useAsyncData(
    'pacientes',
    () =>
        $fetch<RespuestaPacientes>('/api/pacientes', {
            query: {
                buscar: buscar.value || undefined,
                activo: soloActivos.value ? true : undefined,
                por_pagina: 100,
            },
        }),
    { watch: [buscar, soloActivos] },
)
</script>

<template>
    <section>
        <h1>Pacientes</h1>

        <div class="panel">
            <div class="campo--linea">
                <div class="campo">
                    <label for="buscar">Buscar por nombre o RUT</label>
                    <input id="buscar" v-model="buscar" placeholder="Nombre, apellido o RUT" />
                </div>

                <div class="campo">
                    <label for="activos">Estado</label>
                    <select id="activos" v-model="soloActivos">
                        <option :value="true">Solo activos</option>
                        <option :value="false">Todos</option>
                    </select>
                </div>
            </div>

            <p class="ayuda">
                Los datos de baja no se borran: quedan con su historial. Darse de baja deja de
                aparecer en el buscador de agenda y conserva todo lo demás.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>RUT</th>
                        <th>Edad</th>
                        <th>Teléfono</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="paciente in pacientes?.datos ?? []" :key="paciente.id">
                        <td>
                            <NuxtLink :to="`/pacientes/${paciente.id}`">{{ paciente.nombre }}</NuxtLink>
                        </td>
                        <td>{{ paciente.rut ?? '—' }}</td>
                        <td>{{ paciente.edad ?? '—' }}</td>
                        <td>{{ paciente.telefono ?? '—' }}</td>
                        <td>
                            <span class="etiqueta" :class="paciente.activo ? 'etiqueta--exito' : ''">
                                {{ paciente.activo ? 'Activo' : 'De baja' }}
                            </span>
                        </td>
                    </tr>

                    <tr v-if="(pacientes?.datos ?? []).length === 0">
                        <td colspan="5" class="vacio">No hay pacientes que coincidan.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>