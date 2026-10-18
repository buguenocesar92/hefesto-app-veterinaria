# 07 - Seguridad y Ley 19.628

Los controles que estan, los que no estan, y **el aviso**.

## EL AVISO

> **Esto NO esta listo para atender animales reales.**
>
> Este sistema registra y muestra historiales medicos de animales y datos
> personales de sus tutores. Usarlo en producción sin las garantias que se
> describen mas abajo es un riesgo para el animal, para el cliente de la
> clinica y para Hefesto Studio.
>
> Las prescripciones veterinarias y los esquemas de vacunacion tienen
> implicancias que **el sistema no reemplaza**: no valida una prescripcion, no
> verifica un intervalo de vacunacion, y no comprueba un registro profesional.

Lo que si esta hecho y lo que no, esta sin adornos en
[10](10-PRUEBAS-Y-ESTADO.md).

## Ley 19.628

### Lo que esta verificado y lo que no

| | Estado |
|---|---|
| Que la Ley 19.628 regula el tratamiento de datos personales | **No verificado en este entorno.** El sitio del BCN carga con JavaScript y no se pudo leer |
| Que existe deber de confidencialidad y deber de seguridad | **No verificado** |
| Los plazos de conservacion que se aplican | **No verificados** |

**Ninguna afirmacion sobre el texto de la ley en este documento debe leerse como
una opinion juridica.** Lo que se aplico son prácticas generales de Hefesto
(acotar el acceso, auditar, no filtrar en silencio), que son sensatas
independientemente de como lo diga la norma.

**Hace falta revision juridica antes de poner esto en producción.** Ver
[10](10-PRUEBAS-Y-ESTADO.md).

### Lo que si se hizo, en el código

| Práctica | Donde |
|---|---|
| Separar identificacion de salud | `MascotaPolicy` y la ability `ver-ficha-clinica` |
| Acotar el acceso por rol | Cuatro roles, y el mostrador no ve fichas |
| Auditar accesos, incluidos los denegados | `accesos_ficha`, bitacora inmutable |
| No filtrar en silencio | La RLS **lanza excepcion** sin contexto |
| Sesión corta | 120 minutos por omision |
| Sin contrasenas propias | La identidad la emite Authentik, con PKCE |
| Cookie `HttpOnly` y `SameSite` | `config/session.php` |
| Renovacion de sesión al ingresar | `AuthController::callback` |
| Registro sin datos en las respuestas de error | El detalle va al log, nunca al cliente |

### Lo que NO se hizo

| Falta | Por que importa |
|---|---|
| Doble factor de autenticación | **Es lo mas urgente.** Quien entre a una ficha clinica esta viendo datos de salud |
| Aviso de privacidad al tutor | No hay pantalla ni texto de aviso |
| Consentimiento registrado | No hay registro de que el tutor fue informado |
| Procedimiento de incidentes | No hay documentacion de que hacer ante una filtracion |
| Control fisico del acceso | No es software, y es la mitad del problema |
| Cifrado de los respaldos | Ver [08](08-RESPALDOS.md) |

## La consideracion etica y comercial

Este punto no es legal, y conviene decirlo igual.

**Un historial medico de mascota identifica a una persona y a su domicilio.**

El sistema no guarda el historial bajo el nombre del animal. Guarda: el nombre
del tutor, su telefono, su direccion, y los diagnosticos de los animales que
viven en esa casa.

Eso significa que:

1. Un diagnóstico de un animal es información sobre una familia. Tratarlo como
   un dato de la mascota y no como un dato de la persona es un error de criterio
   que se paga caro.
2. Por eso la direccion del tutor no aparece en la agenda de la pantalla de
   llamada.
3. Por eso el mostrador no abre fichas clinicas: ver el padron de mascotas ya
   expone el peso de cada animal, y cruzado con las visitas dice que animal
   esta bajo de peso cronicamente.
4. Por eso mezclar los historiales de dos mascotas del mismo tutor no es solo un
   error clinico: es una fuga de información de una familia a si misma por la
   via equivocada.

## Los controles técnicos

### Aislamiento entre clinicas

Row Level Security de PostgreSQL, con `FORCE`, `WITH CHECK` y contexto de
alcance de transacción. Ver [03](03-MULTITENANCY-Y-ROLES.md).

El aislamiento no lo implementa solo la aplicación: **lo impone la base**. Un
error en PHP no basta para filtrar datos.

### Aislamiento entre mascotas

El historial cuelga de `mascota_id` y **nunca de `tutor_id`**. Ninguna tabla
clinica tiene esa columna, justamente para que la mezcla no sea posible por
escritura.

### Aislamiento de la cache

La clave de Redis empieza siempre por el `tenant_id`, y la interfaz pone
`cache-control: no-store` en todo. Una agenda cacheada por un proxy es una
agenda que se le muestra a quien no es de esa clinica.

### CSRF

Todas las rutas cuelgan del grupo `web` de Laravel, que valida el token CSRF. El
BFF lee la cookie `XSRF-TOKEN` y la reenvia como `X-XSRF-TOKEN`; sin eso, todos
los POST devuelven 419.

### Autorización

Dos capas: middleware para las zonas, Policies y abilities para los recursos.

Un rechazo responde **404, no 403**: un 403 confirmaria que el recurso existe.

### La sesión antes del contexto

La pieza mas delicada del sistema. `sesiones` tiene RLS y hay que leerla antes
de saber la clinica. Se resuelve con una conexión sin RLS que lee **solo por
identificador de sesión**, un valor aleatorio de 40 caracteres generado por el
servidor.

Ver [03](03-MULTITENANCY-Y-ROLES.md), seccion 5.

### Validación

Todos los controladores que escriben datos usan `validate()` o un `FormRequest`,
con mensajes en espanol que dicen que hacer, no solo que fallo.

## Lo que el sistema NO valida, y por que esta bien que no lo haga

| No valida | Quien lo decide |
|---|---|
| Que una prescripcion sea correcta | El profesional que la firma |
| Que un intervalo de vacunacion sea el correcto | La clinica, con un profesional que sepa |
| Que un registro profesional sea valido | Hoy nadie. Ver [10](10-PRUEBAS-Y-ESTADO.md) |
| Que un producto no exija receta retenida | La clinica y la normativa sanitaria |
| Que un diagnóstico sea el correcto | El profesional que atiende |

La razon es una sola: **un sistema que "validara" las decisiones clinicas les
daria a los profesionales una sensacion de aval que no tiene**, y a la clinica
una sensacion de cumplimiento que tampoco. Prefiere no hacerlo y decirlo.

Donde el sistema **si** advierte, advierte sin bloquear: el esquema de
vacunacion sin verificar sale marcado en la API y en la interfaz, y la
prescripcion sale con el profesional y su registro a la vista.

## Verificación

En el servidor, antes de recibir animales reales:

```bash
php artisan clinica:verificar-aislamiento
```

Comprueba el rol (superusuario, `BYPASSRLS`), que las 16 tablas tengan RLS
forzado, que la lectura cruzada devuelva cero filas, que no se pueda escribir en
nombre de otra clinica, y que una consulta sin contexto falle.

Si falla, no sigas.

## Orden de pendientes para producción

1. **Doble factor de autenticación** en Authentik.
2. **Revision juridica**: textos, aviso de privacidad, marco de la prescripcion
   veterinaria (Ley 20.584).
3. **Confirmar los esquemas de vacunacion** con un profesional veterinario.
4. **Validación del registro profesional** contra una fuente oficial.
5. **Cifrado de los respaldos**, con una restauración probada.
6. **Prueba de penetracion** por terceros.
7. **Procedimiento de incidentes** documentado y ensayado.
8. **Capacitacion** del personal de la clinica.
9. **Revision del tiempo de retencion** de la bitacora, que hoy es un dato por
   omision sin verificar.

Ninguno de los nueve se resuelve con software.
