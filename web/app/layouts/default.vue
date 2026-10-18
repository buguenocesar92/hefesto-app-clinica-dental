<script setup lang="ts">
/**
 * Estructura de la aplicacion: cabecera, contenido y pie.
 *
 * LOS ENLACES DEPENDEN DEL PERMISO, NO DEL ROL
 * ---------------------------------------------
 * Se muestran segun lo que dice la API en `capacidades`. Un profesional no ve
 * "Insumos", y un administrador no ve el boton de registrar una intervencion.
 *
 * Ojo: eso es comodidad, no seguridad. Un enlace que no aparece se puede
 * escribir a mano y llamar a la API: el permiso real lo comprueba el servidor,
 * en la Policy. Ver docs/03.
 *
 * EL PIE DICE SIEMPRE QUE SE AUDITA
 * ---------------------------------
 * No es decoracion. En un consultorio donde las personas del equipo se turnan en
 * el mismo puesto, saber que el sistema registra quien abrio cada ficha es la
 * diferencia entre un sistema confiable y uno en el que hay que confiar.
 */
const { usuario, cargar, cerrarSesion, puedeAdministrar, puedeVerAuditoria } = useSesion()

const ruta = useRoute()

const { error } = await useAsyncData('sesion', async () => {
    await cargar()
    return usuario.value
})

watchEffect(() => {
    if (error.value) {
        navigateTo('/entrar')
    }
})

/**
 * A donde va cada rol al entrar.
 *
 * Recepcion va a la agenda, que es su lugar. El administrador va a la
 * bitacora, que es lo unico que puede ver y nadie mas.
 */
const rutaInicio = computed(() => {
    if (!usuario.value) {
        return '/entrar'
    }

    return usuario.value.capacidades.ver_auditoria ? '/administracion/auditoria' : '/'
})

function salir() {
    // El POST real lo hace el servidor de Nuxt: es el que tiene la cookie.
    $fetch('/api/salir', { method: 'POST' }).finally(() => cerrarSesion())
}
</script>

<template>
    <div class="aplicacion">
        <header v-if="usuario" class="cabecera">
            <div class="cabecera__marca">
                <NuxtLink :to="rutaInicio" class="cabecera__nombre">
                    {{ usuario.consultorio?.nombre ?? 'ClinicaDental' }}
                </NuxtLink>
                <span class="cabecera__subtitulo">ClinicaDental</span>
            </div>

            <nav class="cabecera__menu">
                <NuxtLink to="/" :class="{ activo: ruta.path === '/' }">Panel</NuxtLink>
                <NuxtLink to="/agenda" :class="{ activo: ruta.path.startsWith('/agenda') }">
                    Agenda
                </NuxtLink>
                <NuxtLink to="/pacientes" :class="{ activo: ruta.path.startsWith('/pacientes') }">
                    Pacientes
                </NuxtLink>
                <NuxtLink to="/citas" :class="{ activo: ruta.path.startsWith('/citas') }">
                    Citas
                </NuxtLink>
                <NuxtLink v-if="usuario.capacidades.ver_planes" to="/planes" :class="{ activo: ruta.path.startsWith('/planes') }">
                    Planes
                </NuxtLink>
                <NuxtLink to="/alergias" :class="{ activo: ruta.path.startsWith('/alergias') }">
                    Alergias
                </NuxtLink>
                <NuxtLink
                    v-if="puedeAdministrar"
                    to="/administracion/profesionales"
                    :class="{ activo: ruta.path.startsWith('/administracion') && !ruta.path.includes('auditoria') }"
                >
                    Administración
                </NuxtLink>
                <NuxtLink
                    v-if="puedeVerAuditoria"
                    to="/administracion/auditoria"
                    :class="{ activo: ruta.path.includes('auditoria') }"
                >
                    Bitácora
                </NuxtLink>
            </nav>

            <div class="cabecera__persona">
                <span class="cabecera__rol">{{ usuario.rol_etiqueta }}</span>
                <span>{{ usuario.nombre }}</span>
                <button type="button" class="boton boton--chico" @click="salir">Salir</button>
            </div>
        </header>

        <main class="contenido">
            <slot />
        </main>

        <footer v-if="usuario" class="pie">
            <span>{{ usuario.consultorio?.nombre }}</span>
            <span class="pie__aviso">
                Todo acceso al historial odontológico queda registrado, permitido o denegado.
            </span>
        </footer>
    </div>
</template>