<script setup lang="ts">
/**
 * Profesionales del consultorio.
 *
 * UNA FICHA CORTA A PROPOSITO
 * ---------------------------
 * Nombre, especialidad y registro profesional. No hay informacion de salud de un
 * profesional en este sistema: la que importa es la de los pacientes que
 * atiende, y esa vive en el odontograma del paciente, con su propia auditoria.
 *
 * `registro_verificado` sale SIEMPRE en falso. El campo es texto libre y no se
 * valida contra ninguna fuente oficial. Que la pantalla lo diga evita que alguien
 * lo tome por un registro validado. Ver docs/07.
 */
import type { Profesional } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const { puedeAdministrar } = useSesion()

const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)
const guardando = ref(false)

const { data: profesionales, refresh } = await useAsyncData('profesionales', () =>
    $fetch<{ total: number; datos: Profesional[] }>('/api/administracion/profesionales'),
)

const nuevo = reactive({
    nombre: '',
    especialidad: '',
    registro_profesional: '',
    correo: '',
    telefono: '',
    color_agenda: '#2563eb',
})

async function crear() {
    guardando.value = true
    mensaje.value = null
    error.value = null

    try {
        await $fetch('/api/administracion/profesionales', {
            method: 'POST',
            body: {
                nombre: nuevo.nombre,
                especialidad: nuevo.especialidad || null,
                registro_profesional: nuevo.registro_profesional || null,
                correo: nuevo.correo || null,
                telefono: nuevo.telefono || null,
                color_agenda: nuevo.color_agenda,
            },
        })

        mensaje.value =
            'Profesional registrado. Para que pueda entrar al sistema, en Authentik tiene que existir una persona con su correo en el equipo del consultorio.'

        nuevo.nombre = ''
        nuevo.especialidad = ''
        nuevo.registro_profesional = ''
        nuevo.correo = ''
        nuevo.telefono = ''
        nuevo.color_agenda = '#2563eb'

        await refresh()
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo registrar.'
    } finally {
        guardando.value = false
    }
}

async function alternar(profesional: Profesional) {
    error.value = null

    try {
        await $fetch(`/api/administracion/profesionales/${profesional.id}`, {
            method: 'PATCH',
            body: { activo: !profesional.activo },
        })

        mensaje.value = profesional.activo
            ? 'Profesional desactivado. Sus citas e intervenciones quedan con su nombre.'
            : 'Profesional reactivado.'

        await refresh()
    } catch (fallo) {
        error.value = (fallo as { statusMessage?: string }).statusMessage ?? 'No se pudo cambiar.'
    }
}
</script>

<template>
    <section>
        <h1>Profesionales</h1>

        <p class="ayuda">
            <NuxtLink to="/administracion/insumos">Insumos</NuxtLink> ·
            <NuxtLink to="/administracion/tarifas">Tarifario</NuxtLink> ·
            <NuxtLink to="/administracion/auditoria">Bitácora</NuxtLink>
        </p>

        <div v-if="!puedeAdministrar" class="panel">
            <div class="vacio">
                La administración del consultorio es del rol administrador.
            </div>
        </div>

        <template v-else>
            <div v-if="mensaje" class="aviso">{{ mensaje }}</div>
            <div v-if="error" class="error">{{ error }}</div>

            <div class="panel">
                <h2>Registrados ({{ profesionales?.total ?? 0 }})</h2>

                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Especialidad</th>
                            <th>Registro profesional</th>
                            <th>Cuentas</th>
                            <th>Citas</th>
                            <th>Estado</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in profesionales?.datos ?? []" :key="p.id">
                            <td>
                                <span
                                    class="etiqueta"
                                    :style="{ background: p.color_agenda, color: '#ffffff', borderColor: p.color_agenda }"
                                >
                                    {{ p.nombre.slice(0, 2).toUpperCase() }}
                                </span>
                                {{ p.nombre }}
                            </td>
                            <td>{{ p.especialidad ?? '—' }}</td>
                            <td>
                                <template v-if="p.registro_profesional">
                                    {{ p.registro_profesional }}
                                    <span class="etiqueta etiqueta--alerta">sin verificar</span>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td>
                                <span v-if="p.tiene_cuentas" class="etiqueta etiqueta--exito">Sí</span>
                                <span v-else class="etiqueta">No</span>
                            </td>
                            <td>{{ p.citas_totales ?? 0 }}</td>
                            <td>
                                <span class="etiqueta" :class="p.activo ? 'etiqueta--exito' : ''">
                                    {{ p.activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="boton boton--chico boton--secundario"
                                    @click="alternar(p)"
                                >
                                    {{ p.activo ? 'Desactivar' : 'Activar' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="ayuda">
                    Un profesional no se borra: sus citas e intervenciones quedan firmadas con su
                    nombre. Desactivarlo impide que se le agende.
                </p>
            </div>

            <div class="panel">
                <h2>Registrar un profesional</h2>

                <form @submit.prevent="crear">
                    <div class="campo--linea">
                        <div class="campo">
                            <label for="nombre">Nombre completo</label>
                            <input id="nombre" v-model="nuevo.nombre" required minlength="3" />
                        </div>

                        <div class="campo">
                            <label for="especialidad">Especialidad</label>
                            <input id="especialidad" v-model="nuevo.especialidad" />
                        </div>
                    </div>

                    <div class="campo--linea">
                        <div class="campo">
                            <label for="registro">Registro profesional (sin verificar)</label>
                            <input id="registro" v-model="nuevo.registro_profesional" />
                        </div>

                        <div class="campo">
                            <label for="correo">Correo</label>
                            <input id="correo" v-model="nuevo.correo" type="email" />
                        </div>
                    </div>

                    <div class="campo--linea">
                        <div class="campo">
                            <label for="telefono">Teléfono</label>
                            <input id="telefono" v-model="nuevo.telefono" />
                        </div>

                        <div class="campo">
                            <label for="color">Color en la agenda</label>
                            <input id="color" v-model="nuevo.color_agenda" type="color" />
                        </div>
                    </div>

                    <button type="submit" class="boton" :disabled="guardando">
                        {{ guardando ? 'Registrando…' : 'Registrar' }}
                    </button>
                </form>
            </div>
        </template>
    </section>
</template>