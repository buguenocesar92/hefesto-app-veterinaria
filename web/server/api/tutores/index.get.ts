/**
 * Un tutor y sus mascotas.
 *
 * RECUERDA QUE ESTA RESPUESTA ES DE IDENTIFICACION
 * -------------------------------------------------
 * No trae ningun dato de salud. El historial de cada mascota se pide por
 * `/api/fichas/:id`, que exige el permiso de ficha clinica.
 *
 * Que la lista de las mascotas de una persona este en una pantalla y el
 * historial en otra no es un capricho de la interfaz: son dos permisos
 * distintos, y juntarlos en una sola llamada obligaria a abrir el historial a
 * quien solo puede ver el padron. Ver docs/03.
 */
import { llamarApi } from '../../utils/api'

export default defineEventHandler(async (evento) => {
    const consulta = getQuery(evento)

    return llamarApi(evento, '/tutores', {
        consulta: { q: consulta.q as string | undefined },
    })
})
