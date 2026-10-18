<script setup lang="ts">
/**
 * Pantalla de ingreso.
 *
 * NO HAY FORMULARIO DE USUARIO NI CONTRASENA
 * -----------------------------------------
 * No por descuido: la identidad la emite Authentik, que ya resuelve contrasenas,
 * segundo factor, recuperacion y bloqueo de cuentas. Reimplementar eso aca seria
 * peor en todos los sentidos y, sin una revision de seguridad, exactamente el
 * tipo de cosa que no se improvisa. Ver docs/01 y docs/07.
 *
 * Lo unico que hay que hacer es apretar un boton.
 */

const { usuario } = useSesion()

// Si la sesion ya esta abierta, entrar es ruido.
if (usuario.value !== null) {
    await navigateTo('/')
}
</script>

<template>
    <div class="ingreso">
        <div class="ingreso__caja">
            <h1>ClinicaDental</h1>

            <p>
                Sistema de agenda, odontograma y control de alergias para consultorios
                dentales.
            </p>

            <p class="ayuda">
                El ingreso lo hace el proveedor de identidad de Hefesto. Esta aplicacion
                no guarda contrasenas.
            </p>

            <a class="boton" href="/api/entrar">Ingresar</a>

            <p class="ayuda" style="margin-top: 24px;">
                Si tiene una cuenta y le rebota el ingreso, suele ser que en el proveedor de
                identidad no esta en el equipo del consultorio. Consulte al administrador.
            </p>
        </div>
    </div>
</template>