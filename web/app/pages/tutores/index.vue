<script setup lang="ts">
/**
 * El padron de tutores y sus mascotas.
 *
 * ESTA PANTALLA ES DE IDENTIFICACIÓN, NO DE SALUD
 * ----------------------------------------------
 * De cada mascota se ve el nombre, la especie, la raza y la edad. No se ve el
 * diagnostico ni el historial.
 *
 * Y eso no es una limitacion de la pantalla: son dos permisos distintos. La
 * ficha clinica se abre en otra pagina, que es la que exige ser profesional y
 * que deja rastro en la bitacora de accesos.
 *
 * Un tutor con tres mascotas aparece con sus tres animales. Sus historiales
 * son tres, y no se ven aqui ninguno. Ver docs/03.
 */
interface Tutor {
    id: string
    rut: string
    nombre: string
    telefono: string | null
    comuna: string | null
    activo: boolean
    mascotas_cantidad: number
}

const busqueda = ref('')

const { data, error } = await useFetch<{ tutores: Tutor[] }>('/api/tutores', {
    query: { q: busqueda },
})

const tutores = computed(() => data.value?.tutores ?? [])
</script>

<template>
    <div>
        <h1>Tutores</h1>

        <div class="controles">
            <input
                v-model="busqueda"
                type="search"
                placeholder="Buscar por nombre, RUT o teléfono"
                class="buscador"
            />
        </div>

        <p v-if="error" class="aviso">
            No se pudo cargar el padrón. {{ error.message }}
        </p>

        <p v-else-if="tutores.length === 0" class="vacio">
            No hay tutores que coincidan con la búsqueda.
        </p>

        <table v-else>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>RUT</th>
                    <th>Teléfono</th>
                    <th>Comuna</th>
                    <th>Mascotas</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="tutor in tutores" :key="tutor.id">
                    <td>
                        <NuxtLink :to="`/tutores/${tutor.id}`">
                            {{ tutor.nombre }}
                        </NuxtLink>
                    </td>
                    <td>{{ tutor.rut }}</td>
                    <td>{{ tutor.telefono ?? '—' }}</td>
                    <td>{{ tutor.comuna ?? '—' }}</td>
                    <td>{{ tutor.mascotas_cantidad }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.controles {
    margin-bottom: 1rem;
}

.buscador {
    width: min(28rem, 100%);
}
</style>
