<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reglas de la aplicacion
    |--------------------------------------------------------------------------
    |
    | Valores por omision. Se cambian por variable de entorno para no tener que
    | tocar el codigo en cada instalacion. Ver docs/05.
    |
    */

    'duracion_maxima_minutos' => (int) env('VETERINARIA_DURACION_MAXIMA_MINUTOS', 240),

    'margen_entre_citas_minutos' => (int) env('VETERINARIA_MARGEN_ENTRE_CITAS', 0),

    /*
    |--------------------------------------------------------------------------
    | Vacunas
    |--------------------------------------------------------------------------
    |
    | `ventana_por_vencer_dias` NO es una regla sanitaria: es una regla de
    | trabajo. Una dosis que vence manana no se considera atrasada, porque en
    | la practica hay turnos, hay colas y hay gente que llega tarde. Cada
    | clinica la ajusta.
    |
    | `dias_aviso_atraso` es la anticipacion con la que aparece una mascota en
    | el tablero de llamadas.
    |
    | LO QUE NO HAY AQUI, Y POR QUE
    | ----------------------------
    | Ningun intervalo, ninguna edad minima, ningun nombre de vacuna. Eso
    | vive en la tabla `esquemas_vacunacion`, que configura la clinica, y
    | **este proyecto no verifica ninguno de esos intervalos contra ninguna
    | fuente oficial**. Ver docs/02 y docs/07.
    |
    */

    'vacunas' => [
        'ventana_por_vencer_dias' => (int) env('VETERINARIA_VENTANA_POR_VENCER_DIAS', 30),
        'dias_aviso_atraso' => (int) env('VETERINARIA_DIAS_AVISO_ATRASO', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mostrador
    |--------------------------------------------------------------------------
    |
    | `permitir_stock_negativo` esta en `false` y **no debe cambiarse** sin
    | una decision de la clinica. Vender con stock negativo significa que se
    | acepto cobrar por algo que no habia, y eso se descubre en el cierre de
    | caja, cuando ya no se puede arreglar.
    |
    */

    'mostrador' => [
        'permitir_stock_negativo' => (bool) env('VETERINARIA_PERMITIR_STOCK_NEGATIVO', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conservacion
    |--------------------------------------------------------------------------
    |
    | `retencion_bitacora_dias` es el tiempo que se guardan los accesos a la
    | ficha clinica. Es un dato por omision, NO una recomendacion juridica: el
    | plazo que corresponda lo define la ley aplicable y la politica de la
    | institucion. **Este valor no fue verificado contra la normativa.**
    | Ver docs/07.
    |
    */

    'retencion_bitacora_dias' => (int) env('VETERINARIA_RETENCION_BITACORA_DIAS', 3650),

    'alerta_denegados_por_hora' => (int) env('VETERINARIA_ALERTA_DENEGADOS_POR_HORA', 20),

    'alerta_denegados_por_usuario' => (int) env('VETERINARIA_ALERTA_DENEGADOS_POR_USUARIO', 5),

];
