/**
 * Ingreso: lleva al proveedor de identidad.
 *
 * No hay formulario de usuario ni contrasena en esta aplicacion. La
 * autenticacion la resuelve Authentik con OpenID Connect: el navegador va alla,
 * vuelve con un codigo y la API abre la sesion. Ver docs/01.
 *
 * POR QUE NO SE USA EL PROXY GENERAL
 * ----------------------------------
 * `GET /auth/login` de la API responde con una redireccion (302) al emisor de
 * Authentik. El proxy general devuelve el CUERPO de la respuesta, y al seguir
 * la redireccion por dentro se traeria la pagina del proveedor, no su
 * direccion. Este archivo hace la llamada sin seguir redirecciones, lee la
 * cabecera `Location` y manda al navegador alla.
 *
 * Por que hace falta: la URL del emisor solo la conoce la API, que la lee de su
 * configuracion. El navegador no puede construirla, y no debe: si la construyera
 * el cliente, el emisor pasaria a ser un dato del navegador.
 */
export default defineEventHandler(async (evento) => {
    const config = useRuntimeConfig(evento)
    const consulta = getQuery(evento)

    // Se reenvia el identificador del consultorio si viene. La API lo usa como
    // pista para mostrar una fuente concreta en Authentik, que es lo que hace
    // falta en una instalacion con mas de una fuente.
    const consultar = typeof consulta.consultorio === 'string' && consulta.consultorio !== ''
        ? `/${encodeURIComponent(consulta.consultorio)}`
        : ''

    const cabeceras: Record<string, string> = { Accept: 'text/html' }

    const cookies = getRequestHeader(evento, 'cookie')

    if (cookies) {
        cabeceras.Cookie = cookies
    }

    const respuesta = await $fetch.raw(`${config.apiBase}/api/v1/auth/login${consultar}`, {
        headers: cabeceras,
        // La clave de todo este archivo: sin esto, `$fetch` seguiria la
        // redireccion por dentro y devolveria el HTML del proveedor.
        redirect: 'manual',
        ignoreResponseError: true,
    })

    const destino = respuesta.headers.get('location')

    if (!destino) {
        throw createError({
            statusCode: 502,
            statusMessage:
                'La API no devolvio una redireccion de ingreso. Revise la configuracion de Authentik.',
        })
    }

    return sendRedirect(evento, destino)
})