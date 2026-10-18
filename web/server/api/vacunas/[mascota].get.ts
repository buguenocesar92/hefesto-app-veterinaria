/**
 * El calendario de vacunas de UNA mascota.
 *
 * DISTINTO DE LA FICHA CLINICA, A PROPOSITO
 * -----------------------------------------
 * Este endpoint es mas abierto que `/api/fichas/:id`. "La polivalente vence en
 * marzo" es una obligacion pendiente de la clinica, no un dato de salud del
 * animal, y quien atiende el telefono tiene que poder verlo para llamar.
 *
 * Lo que NO trae es el historial con sus lotes y el detalle de cada
 * aplicacion: eso sigue estando en la ficha clinica, que exige otro permiso.
 */
import { llamarApi } from '../../utils/api'

export default defineEventHandler(async (evento) => {
    const id = getRouterParam(evento, 'mascota')

    if (!id) {
        throw createError({ statusCode: 400, statusMessage: 'Falta la mascota.' })
    }

    return llamarApi(evento, `/mascotas/${id}/calendario-vacunas`)
})
