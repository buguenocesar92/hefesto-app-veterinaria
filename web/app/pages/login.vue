/**
 * Entrada a la aplicacion.
 *
 * La API redirige a Authentik, y Authentik vuelve a `/api/v1/auth/callback`,
 * que a su vez responde `302` a `/agenda`. Este endpoint solo persigue esa
 * redireccion: no recibe datos y no decide nada.
 *
 * No hay ningun token en el navegador. La sesion se maneja enteramente por
 * cookie: el navegador la recibe de la API y Nuxt la reenvia. Ver docs/01.
 */
export default defineEventHandler(async (evento) => {
    return sendRedirect(evento, '/api/entrar')
})
