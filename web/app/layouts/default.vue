<script setup lang="ts">
/**
 * Marco general de la aplicacion.
 *
 * QUE SE MUESTRA SEGUN EL ROL
 * ---------------------------
 * El menu lateral se arma con las capacidades que declara el servidor. Un rol
 * que no puede abrir fichas no ve un boton que las abra.
 *
 * RECORDATORIO IMPORTANTE
 * -----------------------
 * Ocultar botones NO es seguridad. Cada pantalla vuelve a preguntar al
 * servidor, y el servidor es el que responde 404. Si alguien abre una URL a
 * mano, ve "No encontrado", no una ficha.
 */
const { sesion, cargar, puede, salir } = useSesion()

await cargar()

const menu = computed(() => {
    const entradas: { texto: string; ruta: string; visible: boolean }[] = [
        { texto: 'Agenda', ruta: '/agenda', visible: puede('agenda') },
        { texto: 'Tutores', ruta: '/tutores', visible: true },
        { texto: 'Mostrador', ruta: '/mostrador', visible: puede('mostrador') },
        { texto: 'Administración', ruta: '/admin', visible: puede('administracion') },
    ]

    return entradas.filter((e) => e.visible)
})
</script>

<template>
    <div class="marco">
        <aside v-if="sesion" class="lateral">
            <p class="marca">VeterinariaHefesto</p>
            <p class="clinica">{{ sesion.clinica?.nombre ?? 'Clínica' }}</p>

            <nav>
                <NuxtLink v-for="entrada in menu" :key="entrada.ruta" :to="entrada.ruta">
                    {{ entrada.texto }}
                </NuxtLink>
            </nav>

            <div class="pie">
                <p class="nombre">{{ sesion.nombre }}</p>
                <p class="rol">{{ sesion.rol_etiqueta }}</p>
                <button type="button" @click="salir">Salir</button>
            </div>
        </aside>

        <main class="contenido">
            <slot />
        </main>
    </div>
</template>

<style scoped>
.marco {
    display: flex;
    min-height: 100vh;
}

.lateral {
    width: 15rem;
    flex-shrink: 0;
    background: #1f3a2e;
    color: #f2f5f3;
    padding: 1.25rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.marca {
    font-weight: 700;
    font-size: 1.05rem;
}

.clinica {
    font-size: 0.85rem;
    opacity: 0.8;
}

nav {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    flex: 1;
}

nav a {
    color: inherit;
    text-decoration: none;
    padding: 0.5rem 0.6rem;
    border-radius: 0.35rem;
}

nav a:hover,
nav a.router-link-active {
    background: #2f5544;
}

.pie {
    font-size: 0.85rem;
    border-top: 1px solid #3a5a4a;
    padding-top: 0.75rem;
}

.pie .rol {
    opacity: 0.75;
}

.contenido {
    flex: 1;
    padding: 1.5rem 2rem;
    overflow-x: auto;
}
</style>
