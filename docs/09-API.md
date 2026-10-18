# 09 - API

Rutas, convenciones y limites.

## Convenciones

| | |
|---|---|
| Prefijo | `/api/v1`, en una sola linea de `routes/web.php` |
| Versión | En la ruta, no en cabeceras ni en el cuerpo |
| Autenticación | Cookie de sesión, no token |
| CSRF | Token de Laravel, reenviado por el BFF como `X-XSRF-TOKEN` |
| Formatos | JSON en las dos direcciones |
| Fechas | ISO 8601 con zona horaria; las fechas de calendario, `YYYY-MM-DD` |
| Decimales | Pesos en kilos, precios en pesos enteros de Chile |
| Errores | `{ "mensaje": "..." }`, en espanol, sin detalles internos |

### El navegador no habla con la API

Las rutas de `web/server/api` son las que usa el navegador. El servidor de Nuxt
reenvia la cookie de sesión a la API.

**Por que:** para que la cookie de sesión quede acotada a un solo origen y no
tenga que ser `Domain=.clinica.cl`.

### Un rechazo responde 404, no 403

Un 403 confirmaria que el recurso existe. En una ficha clinica eso importa mas
que en un portal: no se le dice a quien pregunto si esa mascota existe en esta
clinica.

La RLS ya impide que el recurso exista a ojos de la otra clinica, así que 404 es
ademas la respuesta correcta en el caso habitual.

### `capacidades` no es seguridad

`/auth/usuario` devuelve `capacidades`, y la interfaz las usa para mostrar u
ocultar botones. Cada ruta vuelve a preguntar al servidor.

## Identidad

| Metodo | Ruta | Que hace |
|---|---|---|
| `GET` | `/api/v1/auth/login` | Redirige a Authentik |
| `GET` | `/api/v1/auth/callback` | Canjea el código y abre la sesión |
| `POST` | `/api/v1/auth/logout` | Cierra la sesión, local y en Authentik |
| `GET` | `/api/v1/auth/usuario` | Quien soy, que rol, que puedo hacer |
| `GET` | `/api/v1/salud` | Comprobacion de salud, sin registro |

## Tutores y mascotas (identificacion)

| Metodo | Ruta | Quien |
|---|---|---|
| `GET` | `/api/v1/tutores` | quien opere la clinica |
| `POST` | `/api/v1/tutores` | quien opere la clinica |
| `GET` | `/api/v1/tutores/{tutor}` | quien opere la clinica |
| `PATCH` | `/api/v1/tutores/{tutor}` | quien opere la clinica |
| `GET` | `/api/v1/tutores/{tutor}/mascotas` | quien opere la clinica |
| `POST` | `/api/v1/tutores/{tutor}/mascotas` | quien opere la clinica |
| `GET` | `/api/v1/tutores/{tutor}/mascotas/{mascota}` | quien opere la clinica |
| `PATCH` | `/api/v1/tutores/{tutor}/mascotas/{mascota}` | recepción o profesional |

`GET /tutores/{tutor}` devuelve las mascotas del tutor con **identificacion
solamente**: nombre, especie, raza, edad, peso. Ni diagnóstico, ni historial,
ni estado de vacunas.

## Agenda

| Metodo | Ruta | Quien |
|---|---|---|
| `GET` | `/api/v1/agenda` | recepción, profesional, administración |
| `GET` | `/api/v1/citas` | quien use la agenda |
| `POST` | `/api/v1/citas` | quien use la agenda |
| `PATCH` | `/api/v1/citas/{cita}` | quien use la agenda |

Parámetros de `GET /agenda`: `fecha`, `profesional_id`, `bloque`
(`todos` \| `vacunacion` \| `consultas`).

Al crear una cita, el `fin` **lo calcula el sistema** a partir de
`tipos_atencion.duracion_minutos`. No se acepta un `fin` del cliente.

## Ficha clinica

**Todas estas rutas exigen ser profesional** y pasan por el middleware
`ficha.auditada`, que anota cada intento en `accesos_ficha`.

Todas llevan `{mascota}` en la ruta. **Ninguna lleva `{tutor}`**, y esa es la
razon por la que los historiales no se mezclan.

| Metodo | Ruta |
|---|---|
| `GET` | `/api/v1/mascotas/{mascota}/historial` |
| `GET` | `/api/v1/mascotas/{mascota}/consultas` |
| `POST` | `/api/v1/mascotas/{mascota}/consultas` |
| `GET` | `/api/v1/mascotas/{mascota}/controles` |
| `POST` | `/api/v1/mascotas/{mascota}/controles` |
| `PATCH` | `/api/v1/mascotas/{mascota}/controles/{control}` |
| `GET` | `/api/v1/mascotas/{mascota}/prescripciones` |
| `POST` | `/api/v1/mascotas/{mascota}/prescripciones` |
| `POST` | `/api/v1/mascotas/{mascota}/prescripciones/{prescripcion}/anular` |

`GET /historial` devuelve consultas, controles, prescripciones y vacunas, **todo
filtrado por `mascota_id`**, mas el tutor (que hace falta para saber a quien
entregar la receta).

**No devuelve las mascotas hermanas del mismo tutor.** Mostrarlas seria comodo
para la recepción, pero es información sobre animales que no se esta atendiendo.

Anular una prescripcion tiene su propia ruta: anular no es editar, es un cambio
de estado con consecuencia clinica, y una anulada no vuelve.

## Vacunas

| Metodo | Ruta | Quien |
|---|---|---|
| `GET` | `/api/v1/mascotas/{mascota}/calendario-vacunas` | quien opere la clinica |
| `POST` | `/api/v1/mascotas/{mascota}/vacunas` | **solo profesional** |
| `POST` | `/api/v1/mascotas/{mascota}/vacunas/{vacuna}/anular` | **solo profesional** |

El calendario es **mas abierto** que la ficha clinica, a propósito: "la
polivalente vence en marzo" es una obligacion pendiente de la clinica, no un
dato de salud del animal, y quien atiende el telefono tiene que poder verlo
para llamar.

Al registrar una vacuna:

- el `esquema_id` es opcional (puede haberse aplicado algo que la clinica todavia
  no tiene cargado);
- si se manda un `esquema_id`, la especie tiene que calzar con la de la mascota;
- **la edad NO bloquea**: hay motivos legitimos para aplicar una dosis antes de
  la edad de la regla, y quien decide es el profesional;
- `fecha_aplicacion` no puede ser futura;
- la respuesta trae `proxima_dose_en` **calculada y guardada**, y un arreglo
  `avisos` si el esquema no esta verificado.

Los avisos **no bloquean**. Dejan escrito lo que hay que tener presente.

## Mostrador

| Metodo | Ruta | Quien |
|---|---|---|
| `GET` | `/api/v1/mostrador/productos` | mostrador, recepción, administración |
| `GET` | `/api/v1/mostrador/productos/{producto}` | mostrador, recepción, administración |
| `GET` | `/api/v1/mostrador/ventas` | mostrador, recepción, administración |
| `POST` | `/api/v1/mostrador/ventas` | mostrador, recepción, administración |

Cuerpo de `POST /ventas`:

```json
{
    "tutor_id": "uuid-o-null",
    "medio_pago": "efectivo | tarjeta | transferencia",
    "items": [
        { "producto_id": "uuid", "cantidad": 2 }
    ]
}
```

Comportamiento:

- el stock se descuenta **en la misma transacción**, con `lockForUpdate`;
- si no hay stock suficiente, la venta entera se rechaza;
- el precio y el nombre se **copian** al renglone;
- `medio_pago` es lo que la persona **declara**. **No hay integración con
  pasarela de pago ni con terminal.**

## Administración

| Metodo | Ruta | Quien |
|---|---|---|
| `GET` | `/api/v1/admin/profesionales` | quien use la agenda |
| `POST` | `/api/v1/admin/profesionales` | administración |
| `GET` | `/api/v1/admin/tipos-atencion` | quien use la agenda |
| `POST` | `/api/v1/admin/tipos-atencion` | administración |
| `GET` | `/api/v1/admin/productos` | administración |
| `POST` | `/api/v1/admin/productos` | administración |
| `GET` | `/api/v1/admin/esquemas-vacunacion` | administración |
| `POST` | `/api/v1/admin/esquemas-vacunacion` | administración |
| `GET` | `/api/v1/admin/usuarios` | administración |
| `GET` | `/api/v1/admin/auditoria` | administración |

El listado de profesionales y de tipos de atención **abre para quien agenda**:
sin catalogo no se puede agendar. Crear y modificar, no.

Todas las respuestas de `esquemas-vacunacion` traen un campo `aviso` que dice
que los intervalos los configura la clinica y **que el sistema no los verifica
contra ninguna fuente oficial**.

## Comandos de consola

```bash
php artisan clinica:verificar-aislamiento   # antes de recibir animales reales
php artisan clinica:olvidar-cache-oidc      # si Authentik roto sus claves
```

## Limites conocidos

| Limite | Detalle |
|---|---|
| Sin paginacion en la agenda | Se limita a 100 dias |
| Sin busqueda difusa | `ILIKE` con comodin |
| Sin carga de archivos | No hay imagenes ni documentos |
| Sin correo saliente | Los avisos de vacunas por vencer **no se envian** |
| Sin notificaciones | El tablero es una pantalla, no un correo |
| Sin factura electrónica | El total es un dato |
| Sin integración de registro profesional | `registro_profesional` es texto |
| Sin validación de receta retenida | `requiere_receta` es un aviso |
| Sin auditoria de inventario | Editar stock a mano no deja rastro |

Ver [10](10-PRUEBAS-Y-ESTADO.md).
