# 06 - Primer paciente

La sesión completa, paso a paso: del ingreso hasta el comprobante de mostrador.

> "Primer paciente" aquí es el primer **animal** con su tutor. En esta
> aplicación el animal casi nunca puede hablar y nunca firma: quien autoriza
> es una persona, el tutor. Ver [02](02-MODELO-DE-DATOS.md).

## Que datos toca esta sesión

| Paso | Que crea | Quien lo hace |
|---|---|---|
| 1 | Ingreso | Cualquiera de los cuatro roles |
| 2 | Ficha del tutor | Recepción |
| 3 | Ficha de la mascota | Recepción |
| 4 | Tipo de atención | Administración |
| 5 | Cita | Recepción |
| 6 | Consulta | Profesional |
| 7 | Prescripcion | Profesional |
| 8 | Vacuna y proxima dosis | Profesional |
| 9 | Venta de mostrador | Mostrador |

Los pasos 6 a 8 son **ficha clinica**: solo un profesional. Y cada uno queda
registrado en `accesos_ficha`, permitido o denegado.

---

## Paso 1 - Ingreso

En `/login`, el sistema manda a Authentik y vuelve con una sesión de Laravel.

Quien entra lo decide el **equipo de Authentik** al que pertenece, no un
formulario de esta aplicación. Sin un equipo que declare la clinica, no hay
ingreso.

Al volver, la pantalla `usuario` trae el nombre, el rol y las **capacidades**.
La interfaz usa esas capacidades para mostrar u ocultar botones, pero **eso no es
una defensa**: cada pantalla vuelve a preguntar al servidor.

---

## Paso 2 - Ficha del tutor

Como recepción, en **Tutores**.

Nombre, RUT, telefono, correo y direccion. El RUT es obligatorio: es la persona
responsable del animal.

Por que la direccion y el telefono estan en esta ficha y no en la de la
mascota: **son datos de una persona**, y la Ley 19.628 protege a la persona. Un
historial medico de mascota identifica a una persona y su domicilio, así que se
trata con el mismo cuidado. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Paso 3 - Ficha de la mascota

Dentro del tutor, **Nueva mascota**.

Nombre, especie, raza, sexo, fecha de nacimiento, peso y microchip.

Tres detalles que hacen la diferencia:

- **La especie importa de verdad.** El esquema de vacunacion depende de ella: el
  de un perro no es el de un gato.
- **El sexo tiene "no informado".** Si el formulario obliga a elegir macho o
  hembra, alguien marca lo que no sabe y esa invencion queda en la ficha para
  siempre.
- **El microchip puede quedar en blanco.** Muchos animales domesticos no lo
  tienen, y es preferible un dato en blanco a uno inventado.

### El momento de mayor riesgo del sistema

Registrar la segunda mascota del mismo tutor.

Si al abrir la ficha de Firulais apareciera el historial de Michi, el sistema
estaria mal. **No deberia pasar**, y hay una prueba que lo comprueba:
`HistorialNoSeMezclaEntreMascotasDelMismoTutorTest`.

Si ocurre, lo mas probable es que se haya escrito una consulta por
`tutor_id`. **Ninguna tabla clinica tiene esa columna**, justamente para que no
sea posible.

---

## Paso 4 - Tipo de atención

Como administración, en **Administración → Tipos de atención**.

Nombre, duracion y precio. Y el interruptor **bloque de vacunación**.

Es la bandera que hace que esta aplicación sea distinta de una agenda medica
comun: en una clinica veterinaria tipo, la atención mas frecuente del dia no es
una consulta, es una vacuna.

La duracion la declara el catalogo, no quien agenda: así dos personas que
agenden la misma atención ocupan siempre el mismo rato.

---

## Paso 5 - Agenda

Como recepción, en **Agenda**.

Se elige la mascota, el profesional y el tipo de atención. **El fin lo calcula
el sistema** a partir de la duracion del tipo.

### Si el horario esta ocupado

PostgreSQL lo rechaza. La tabla `citas` tiene una constraint `EXCLUDE` que
prohibe que dos citas del mismo profesional se solapen.

No es una comprobacion de la aplicación: es de la base, porque dos personas
pueden agendar **a la vez** y las dos pasarían un `if` en PHP.

Para liberar un horario: cancelar la cita, no borrarla. Una cita cancelada existo
y hay que poder probarlo.

---

## Paso 6 - Consulta

Como profesional, en la ficha de la mascota.

Motivo, examen fisico, diagnóstico, tratamiento y evolucion.

**Lo que se escribe es de la mascota, no del tutor.** Si el animal tiene
diagnóstico de algo, ese texto pertenece a la ficha de ese animal y a ningun
otro.

### Cada apertura queda registrada

`accesos_ficha` anota **quién abrió qué ficha y desde dónde**, y también los
intentos denegados.

Un intento denegado es información: puede ser un error de permisos, un
profesional trabajando con cuentas de otra clinica, o alguien probando.

---

## Paso 7 - Prescripcion

Como profesional, en la misma ficha.

Medicamento, dosis, via, frecuencia y duracion.

### Lo que este sistema NO hace

**No valida la prescripcion.** No comprueba si el farmaco existe, ni la dosis, ni
si corresponde a la especie, ni si ese producto exige receta retenida.

Eso es del profesional que la firma, con su registro profesional vigente. Un
sistema que "validara" la decisión clinica solo le daria al profesional la
ilusion de un aval que el sistema no tiene.

La prescripcion veterinaria se rige en Chile por la **Ley 20.584** y su
reglamento. **Ese marco normativo no esta verificado en este entorno**, y su
cumplimiento es responsabilidad de la clinica y del profesional. Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Paso 8 - Vacuna

Como profesional, en la ficha de la mascota, en el calendario.

Se elige el esquema y se registra la fecha de aplicación, el lote y el
laboratorio.

### La fecha de la proxima dosis se guarda, no se recalcula

Al registrar la aplicación, el sistema **calcula** la proxima dosis leyendo el
esquema, y **guarda** esa fecha.

Despues no se vuelve a tocar. Ni si se edita el esquema, ni si cambia la
normativa, ni con el paso del tiempo.

Por que: es un hecho, igual que la fecha impressa en el certificado que se le
entrega al tutor. Si se recalculara contra el esquema actual, cambiar una regla
moveria hacia atras las fechas de una dosis que ya se aplico, y dejaria
desmentido un certificado ya impreso.

Lo que **si** cambia con el tiempo es la vigencia: si esa fecha ya paso, el
animal aparece en el tablero de atrasos. Eso se deriva en cada lectura.

Ver [02](02-MODELO-DE-DATOS.md).

### El aviso de esquema sin verificar

Si el esquema aplicado no tiene `verificado = true`, la respuesta lo dice y la
interfaz lo marca.

**No es un bloqueo.** Quien decide si la dosis corresponde es el profesional, y
puede aplicar una regla sin confirmar. Lo que hace el sistema es dejar escrito
que la regla no fue revisada.

### ADVERTENCIA

**Los intervalos no son una verdad del sistema.** Dependen de la especie, de la
edad, del fabricante del producto y de la normativa sanitaria de la
jurisdiccion. **Este proyecto no verifico ninguna de esas cuatro cosas contra
ninguna fuente oficial.**

Cada esquema tiene que revisarlo y confirmarlo un profesional veterinario antes
de usarse con animales reales. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Paso 9 - Mostrador

Como mostrador, en **Mostrador**.

Se buscan productos, se arman los renglones y se cobra.

Tres cosas que pasan solas y conviene saber:

1. **El stock se descuenta en la misma transacción que la venta.** Si algo falla
   a mitad, no queda ni venta sin descuento ni descuento sin venta.

2. **El precio se copia al comprobante.** Si manana sube el precio, el
   comprobante de hoy sigue diciendo lo que se cobro hoy.

3. **No se toca ninguna ficha clinica.** Que una persona compre antiparasitario
   para su perro es un dato comercial. Que el perro tenga gusanos es un dato de
   salud. Quien cobra no necesita ver lo segundo.

**El mostrador no tiene agenda.** Cobra y despacha; no necesita saber quien
esta en la lista de espera.

---

## Para comprobarlo, a mano

Con la semilla cargada:

1. Entrar como `recepcion@veterinaria.local`.
2. **Tutores** → Javiera Muñoz, con **dos** mascotas.
3. Abrir Firulais. Ver solo su ficha.
4. Abrir Michi. Ver solo su ficha.
5. Entrar como `mostrador@veterinaria.local` e intentar abrir una ficha.
   Debe dar **404**, no 403.
6. Entrar como `profesional@veterinaria.local` y abrir ambas fichas.
7. En Firulais, una vacuna debe aparecer **ATRASADA**; en Michi, no.

Si cualquiera de estos siete pasos se comporta distinto de lo descrito, **el
sistema no sirve para atender animales reales.**

---

## Que falta para que esto sea real
Para atender animales reales hace falta todo lo que esta en
[07](07-SEGURIDAD-Y-LEY-19628.md) y [10](10-PRUEBAS-Y-ESTADO.md). En resumen:

1. Correr las pruebas (estan escritas, no se ejecutaron).
2. `clinica:verificar-aislamiento` en el servidor.
3. Revision juridica de los textos y del marco de la prescripcion.
4. Confirmar los esquemas de vacunacion con un profesional.
5. Prueba de penetracion por terceros.
6. Un ensayo de restauración de respaldo.
