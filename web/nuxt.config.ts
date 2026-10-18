// Configuracion de la interfaz de VeterinariaHefesto (Nuxt 4 con render en el
// servidor).
//
// POR QUE SSR EN ESTA APLICACION Y NO UNA SPA
// ------------------------------------------
// La agenda y la ficha clinica no deben quedar en el HTML de una pagina
// publica, ni en el historial del navegador de quien la abrio en un computador
// compartido. Una pantalla que se dibuja en el servidor no depende de que el
// JavaScript haya cargado para no filtrar nada.
//
// Ademas, el render en el servidor es lo que hace posible que la cookie de
// sesion nunca salga de este servidor: el navegador habla con Nuxt, y Nuxt
// habla con la API reenviando la cookie. Ver docs/01.

export default defineNuxtConfig({
    ssr: true,

    compatibilityDate: '2026-10-17',

    devtools: { enabled: false },

    runtimeConfig: {
        // URL interna de la API de Laravel. Solo la usa el servidor de Nuxt:
        // nunca viaja al navegador.
        apiBase: process.env.NUXT_API_BASE || 'http://api:8000',

        public: {
            // Dias que muestra la agenda por omision.
            diasAgenda: Number(process.env.NUXT_DIAS_AGENDA || 1),
        },
    },

    css: ['~/assets/css/veterinaria.css'],

    app: {
        head: {
            htmlAttrs: { lang: 'es-CL' },
            title: 'VeterinariaHefesto',
            meta: [
                { charset: 'utf-8' },
                { name: 'viewport', content: 'width=device-width, initial-scale=1' },
                // La aplicacion no se indexa: no es un sitio publico, y las
                // URLs contienen identificadores de personas y de animales.
                { name: 'robots', content: 'noindex, nofollow' },
                { name: 'referrer', content: 'same-origin' },
            ],
        },
    },

    nitro: {
        compressPublicAssets: true,
    },

    routeRules: {
        /*
         * NADA QUE DEPENDA DE UNA SESION SE CACHEA EN UN PROXY.
         *
         * Una agenda cacheada por un proxy es una agenda que se le muestra a
         * quien no es de esa clinica. Es el mismo error que se evita en la
         * cache de Redis de la API, y la misma razon.
         */
        '/**': { headers: { 'cache-control': 'no-store, private' } },
        '/login': { ssr: true },
    },
})
