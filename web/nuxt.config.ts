// Configuracion de la interfaz de ClinicaDental (Nuxt 4 con render en el
// servidor).
//
// Las versiones estan fijadas por rango mayor, no exacto, a proposito: una
// correccion de seguridad dentro de la rama compatible no deberia exigir
// rehacer la imagen. El digesto exacto queda en deploy/web/Dockerfile.

export default defineNuxtConfig({
    // La interfaz se renderiza en el servidor. Motivo concreto: el mapa dental
    // y la agenda no deben quedar en el HTML de una pagina publica, y una
    // pantalla que se dibuja en el servidor no depende de que el JavaScript
    // haya cargado para no filtrar nada.
    ssr: true,

    compatibilityDate: '2026-10-17',

    devtools: { enabled: false },

    runtimeConfig: {
        // URL interna de la API de Laravel. Solo la usa el servidor de Nuxt:
        // nunca viaja al navegador.
        apiBase: process.env.NUXT_API_BASE || 'http://api:8000',

        public: {
            // Rango de agenda por omision, en dias.
            diasAgenda: Number(process.env.NUXT_DIAS_AGENDA || 1),
            // La cabecera de la tabla del mapa: 32 piezas permanentes.
            piezasPorFila: 8,
        },
    },

    css: ['~/assets/css/main.css'],

    app: {
        head: {
            htmlAttrs: { lang: 'es-CL' },
            title: 'ClinicaDental',
            meta: [
                { charset: 'utf-8' },
                { name: 'viewport', content: 'width=device-width, initial-scale=1' },
                // La aplicacion no se indexa: no es un sitio publico.
                { name: 'robots', content: 'noindex, nofollow' },
                { name: 'referrer', content: 'same-origin' },
            ],
        },
    },

    nitro: {
        // El BFF reenvia la cookie de sesion de Laravel. Por eso la aplicacion
        // de Nuxt NO necesita ningun token propio.
        compressPublicAssets: true,
    },

    routeRules: {
        // Nada que dependa de una sesion se cachea en un proxy.
        '/**': { headers: { 'cache-control': 'no-store' } },
        '/entrar': { ssr: true },
    },
})