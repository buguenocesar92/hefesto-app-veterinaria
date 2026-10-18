/**
 * El historial clinico de UNA mascota.
 *
 * OJO CON LA FORMA DE ESTA RUTA
 * -----------------------------
 * La ruta lleva el identificador de la MASCOTA, no el del tutor. Es la misma
 * regla que en la API, y por la misma razon: un tutor con tres animales tiene
 * tres historiales, y pedir "el historial del tutor" devolveria los tres.
 *
 * Si quien llama no tiene permiso, la API responde 404 y este endpoint
 * devuelve el 404 tal cual. No inventa una respuesta vacia para que la
 * pantalla no se rompa: en una ficha clinica, una pantalla "bonita" con datos
 * equivocados es el peor error posible. Ver server/utils/api.ts.
 */
import { llamarApi } from '../../utils/api'

export default defineEventHandler(async (evento) => {
    const id = getRouterParam(evento, 'mascota')

    if (!id) {
        throw createError({ statusCode: 400, statusMessage: 'Falta la mascota.' })
    }

    return llamarApi(evento, `/mascotas/${id}/historial`)
})
