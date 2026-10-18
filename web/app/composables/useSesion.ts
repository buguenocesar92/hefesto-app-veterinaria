/**
 * Quien esta entrando y que puede hacer.
 *
 * PARA QUE EXISTE ESTE COMPOSABLE
 * -------------------------------
 * La API devuelve `capacidades` en `/auth/usuario`: un objeto de banderas
 * (agenda, mostrador, ficha_clinica, prescribir, administracion).
 *
 * Esto sirve para MOSTRAR U OCULTAR BOTONES. No es una defensa: cada ruta
 * vuelve a preguntar al servidor, y es el servidor el que decide. Ocultar un
 * boton es cortesia, no seguridad.
 *
 * La razon de tenerlo en el servidor y no esconderlo con CSS es que, si la
 * lista de capacidades viviera en el HTML, un usuario que sabe leer el
 * codigo veria exactamente que buttons existen y en que pantallas. No es que
 * pueda pulsarlos, pero le dira donde mirar.
 */

export interface Capacidades {
    agenda: boolean
    mostrador: boolean
    ficha_clinica: boolean
    prescribir: boolean
    administracion: boolean
}

export interface Sesion {
    id: string
    nombre: string
    correo: string
    rol: string
    rol_etiqueta: string
    clinica: {
        id: string | null
        nombre: string | null
        zona_horaria: string | null
    } | null
    capacidades: Capacidades
}

export function useSesion() {
    const sesion = useState<Sesion | null>('sesion', () => null)

    /**
     * Carga la sesion desde la API, una sola vez por peticion.
     */
    async function cargar(): Promise<Sesion | null> {
        if (sesion.value !== null) {
            return sesion.value
        }

        try {
            sesion.value = await $fetch<Sesion>('/api/usuario')
        } catch {
            // Sin sesion es una situacion normal, no un error: simplemente no
            // hay nadie adentro. La pagina de entrada lo maneja.
            sesion.value = null
        }

        return sesion.value
    }

    /**
     * Si la persona puede hacer algo, segun lo que dice el servidor.
     */
    function puede(capacidad: keyof Capacidades): boolean {
        return sesion.value?.capacidades?.[capacidad] === true
    }

    async function salir(): Promise<void> {
        await $fetch('/api/salir', { method: 'POST' })
        sesion.value = null
        await navigateTo('/login')
    }

    return { sesion, cargar, puede, salir }
}
