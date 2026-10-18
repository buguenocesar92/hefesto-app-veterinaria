# 02 - Modelo de datos

Como esta organizado el dominio, y por que el modelo gira en torno a la
persona y sus animales.

## La diferencia que cambia el modelo

En una ficha humana, el paciente es el titular. En veterinaria **el paciente
casi nunca puede hablar y nunca firma**: quien llega, quien autoriza una
radiografia y quien recibe la receta es una persona, el tutor, y su nombre no
coincide con el del animal.

De ahi salen tres decisiones que no existen en una clinica humana:

1. **El tutor es el sujeto de los datos personales.** Es a el a quien protege
   la Ley 19.628, porque es la persona natural. El animal no es persona y no
   tiene derechos de datos.
2. **Un tutor tiene varias mascotas**, y es la situacion normal, no la
   excepcion.
3. **El dato clinico cuelga de la MASCOTA, nunca del tutor.**

La tercera es la importante, y se explica abajo.

## Las tablas

```
clinicas                    el tenant (sin RLS, con vista publica)
│
├── profesionales           veterinarios, con registro (texto, sin verificar)
├── usuarios                cuentas de acceso, sin contrasena
│
├── tutores                 LA PERSONA. Datos personales (Ley 19.628)
│   │
│   └── mascotas            EL PACIENTE
│       │  ┌──────────── la ficha clinica cuelga de acá, NO del tutor
│       │
│       ├── consultas       atenciones con diagnostico
│       │   ├── controles   seguimiento pendiente
│       │   └── prescripciones
│       │
│       ├── citas           agenda (lleva tutor_id CONGELADO, ver abajo)
│       └── vacunas         aplicaciones, con la proxima dosis GUARDADA
│
├── tipos_atencion          catalogo de la agenda, con el bloque de vacuna
├── esquemas_vacunacion     reglas QUE CONFIGURA LA CLINICA (sin verificar)
├── productos               catalogo del mostrador
├── ventas / venta_items     comprobantes, con precio COPIADO
├── accesos_ficha           bitacora inmutable de accesos
└── sesiones                sesion de Laravel
```

## Las tres reglas estructurales

### 1. El historial cuelga de la mascota

`consultas`, `controles`, `prescripciones` y `vacunas` tienen `mascota_id`.
**Ninguna tiene `tutor_id`.**

Un tutor puede tener tres mascotas. Si el historial se guardara o se consultara
por tutor, las tres historias se mezclarian en una sola linea del tiempo: la
radiografia del perro aparece pegada al diagnóstico de la gata, el peso de una
se compara con la temperatura de la otra, y al recetar se elige el medicamento
que se le dio al otro animal.

**Ninguno de esos animales esta enfermo. Lo que se mezclo son los registros.**

El dano es doble:

- **Clinico**: una dosis calculada sobre el peso equivocado puede dañar al
  animal.
- **De privacidad**: el historial de una mascota identifica a una persona y a
  su domicilio (ver [07](07-SEGURIDAD-Y-LEY-19628.md)).

Hay una prueba dedicada a esto:
`tests/Feature/Aislamiento/HistorialNoSeMezclaEntreMascotasDelMismoTutorTest.php`
comprueba que la ficha de una mascota no trae, ni por identificador ni por
conteo, nada de la otra, y busca el TEXTO del diagnóstico ajeno en la
respuesta completa.

### 2. `citas` si lleva `tutor_id`, y por que

La cita es un hecho del dia: el perro de la Sra. Ruiz, a las diez.

- Sin `tutor_id`, la agenda tendria que hacer un JOIN para llegar al nombre que
  se muestra y para poder llamar.
- Y si el animal se da de baja o cambia de tutor, las citas ya agendadas
  siguen teniendo que decir a quien llamar. El `tutor_id` de la cita es
  **congelado** al agendar, a propósito.

No es una excepcion a la regla 1: en `citas` no hay nada clinico, solo un
compromiso de horario y un telefono.

### 3. Una mascota, un tutor

`tutor_id` no es una tabla puente. Si una familia se separa o se traspasa la
tenencia, lo que se hace es reasignar `tutor_id`, y queda la trazabilidad en la
bitacora, no una historia compartida de acceso.

## Vacunas: lo guardado y lo derivado

Esta es la parte del modelo que mas conviene entender, porque es la que se
puede hacer mal de dos maneras.

### `proxima_dose_en` es un HECHO GUARDADO

Se calcula **una vez**, el dia que se registra la aplicación, leyendo el esquema
que habia en ese momento. Y no se vuelve a tocar.

Ni cuando se edita el esquema. Ni cuando cambia la normativa. Ni cuando pasa el
tiempo.

Por que no se recalcula en cada lectura:

| Si se recalculara... | Que pasaria |
|---|---|
| Contra el esquema actual | Editar una regla moveria hacia atras las fechas de una dosis que ya se aplico, y dejaria desmentido un certificado ya impreso |
| Contra "hoy" | El resultado cambiaria solo con que pase el tiempo, y el dia que mas importa el sistema podria decir una cosa y ayer otra |
| Contra la edad | Y la edad es un valor derivado: entonces el vencimiento dependeria de una estimacion |

Guardada, la fecha es un hecho, igual que la fecha impressa en el certificado
que se le entrega al tutor. Si despues hay que corregirla, se corrige a mano
y queda registro de eso.

### La vigencia si se DERIVA

Si la dosis esta al dia o atrasada **si se calcula en cada lectura**, porque
depende de la fecha de hoy, que es justamente el dato que tiene que cambiar.

La separacion:

```
GUARDADO:  cuando cae la proxima dosis    (un hecho, ya estaba decidido)
DERIVADO:  si esa fecha ya paso           (cambia con cada dia)
```

Si se guardara también el estado, se escribiria `estado = 'vigente'` el dia de
la aplicación, nadie lo actualizaria nunca, y dos anos despues el sistema
seguiria diciendo que la mascota esta al dia. Por eso
`Vacuna::$fillable` no tiene ningun estado de vigencia: `EstadoVacuna` solo
tiene `aplicada` y `anulada`, que SI son hechos.

Implementacion:

- `App\Services\Vacunas\CalculadorDeProximaDosis`: la fecha. Clase pura, sin
  base de datos, con las fechas como argumento.
- `App\Services\Vacunas\CalculadorDeVigencia`: si ya paso. Deriva, con "hoy"
  como parámetro para poder probar los casos limite con la fecha fijada.

### Los intervalos NO son una verdad del sistema

Los intervalos viven en `esquemas_vacunacion`, que **configura la clinica**.
No hay ningun calendario escrito en el código.

Un esquema depende de la especie, de la edad, del fabricante del producto y de
la normativa sanitaria de la jurisdiction. **Este proyecto no verifico ninguna
de esas cuatro cosas contra ninguna fuente oficial.**

Por eso cada fila trae `verificado` y `fuente`, y la semilla carga sus filas
con `verificado = false` y la fuente escrita. Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

## El bloque de vacunacion en la agenda

`tipos_atencion.bloque_vacunacion` es la bandera que hace que este sistema sea
distinto de una agenda medica comun.

En una clinica veterinaria tipo, la atención mas frecuente del dia no es una
consulta: es una vacuna. Es rapida, se hace en cadena y es la que da la plata.

Mezclarlas produce el problema de siempre: la gente va por una vacuna, espera
veinte minutos a que se desocupe el consultorio de al lado, y la clinica pierde
al cliente. Con el bloque separado, la agenda de vacunas es rapida y masiva, y
se puede abrir un turno de vacunas un domingo sin tocar la agenda de
consultas.

`duracion_minutos` la declara el catalogo, no quien agenda: así dos personas
que agenden la misma atención ocupan siempre el mismo rato.

## La agenda no se puede pisar

`citas` tiene una constraint que PostgreSQL impone:

```sql
EXCLUDE USING gist (
    profesional_id WITH =,
    tstzrange(inicio, fin, '[)') WITH &&
)
WHERE (estado <> 'cancelada')
```

`EXCLUDE` es lo único que puede expresar "estas dos cosas no pueden solaparse" en
una constraint; un `UNIQUE` normal compara valores, no rangos.

Por que en la base y no en la aplicación: porque dos personas pueden agendar a
la vez (dos recepciones, dos pestanas). Si la comprobacion estuviera en PHP, las
dos pasarian el `if` y las dos guardarían.

Las citas canceladas quedan fuera de la predicacion: una cita cancelada libera
el horario y tiene que poder volver a ocuparse.

## El mostrador

Dos decisiones que sostienen la contabilidad:

1. **`venta_items.precio_unitario` se COPIA al vender.** Si guardara "el
   producto X, que hoy vale N", al cambiar el precio los comprobantes viejos
   cambiarian de valor y la caja no cerraria. El comprobante de marzo dice lo
   que cobro en marzo.

2. **El stock se descuenta en la MISMA TRANSACCIÓN que la venta**, con
   `lockForUpdate` sobre el producto. Si el comprobante se guardara primero y el
   stock se descontara despues, una falla en el medio dejaria una venta sin
   descuento, que es el peor error posible en un mostrador: no se nota hasta el
   cierre de caja.

`stock_minimo` es una alarma. **No bloquea la venta**: un animal necesita su
alimento aunque se haya acabado.

## La bitacora

`accesos_ficha` registra los accesos **permitidos y denegados**.

Un denegado es información: puede ser un error de permisos, un profesional
trabajando con cuentas de otra clinica, o alguien probando.

Es inmutable: `App\Models\AccesoFicha` lanza `LogicException` si alguien intenta
modificar o borrar una fila, y se escribe por una conexión aparte porque la
peticion que deniega un acceso suele terminar revertida y el registro tiene que
sobrevivir a esa reversa.

## Lo que NO esta en el modelo

| No esta | Por que |
|---|---|
| Historial por tutor | Romperia el punto 1 |
| Estado de vigencia guardado | Se quedaria viejo solo |
| Calendarios de vacunacion en el código | Dependen de cuatro cosas que no verificamos |
| Diagnóstico en `citas` | Una cita es un hecho del dia, no una consulta |
| Historial de movimientos de inventario | **No implementado.** Ver [10](10-PRUEBAS-Y-ESTADO.md) |
| Facturacion electrónica | No implementada. El total es un dato |
