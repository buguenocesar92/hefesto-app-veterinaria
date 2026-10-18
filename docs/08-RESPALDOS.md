# 08 - Respaldos

Como respaldar, como restaurar, y por que un respaldo que nunca se probo no es
un respaldo.

> **En este entorno no se ejecuto ninguno de los comandos de este documento.**
> Ver [10](10-PRUEBAS-Y-ESTADO.md).

## Que hay que respaldar

| Volumen | Que contiene | Se puede regenerar |
|---|---|---|
| `veterinaria_postgres` | **Todo lo importante** | **No.** Fichas, tutores, vacunas, ventas |
| `veterinaria_redis` | Cache y colas | **Si.** Se pierde sin daño |
| `veterinaria_storage` | Registros de Laravel, archivos generados | **Parte.** El `storage/logs` no |
| `veterinaria_bootstrap` | Cache de configuración | **Si.** Se regenera |
| `veterinaria_caddy_datos` | Certificados | **Si.** Se piden de nuevo |

**Redis no se respalda, y no es un error.** Es cache. Perderla significa que la
agenda se recalcula y que un trabajo encolado se pierde. La agenda se regenera
sola.

## El respaldo

```bash
docker compose -f deploy/docker-compose.prod.yml exec -T postgres \
  pg_dump -U veterinaria -Fc veterinaria > respaldo-$(date +%F).dump
```

`-Fc` es el formato **personalizado**: comprimido y restaurable con
`pg_restore`, que permite restaurar tablas sueltas si algo sale mal.

Por que no el formato SQL plano (`-Fp`): con una clinica con fichas de diez
años, el SQL plano es mucho mas grande y no permite restaurar por partes.

### Cifrado

**El respaldo sale sin cifrar y contiene todo: datos personales de tutores y
historiales de salud de animales.**

Hay que cifrarlo antes de que salga del servidor:

```bash
docker compose -f deploy/docker-compose.prod.yml exec -T postgres \
  pg_dump -U veterinaria -Fc veterinaria \
  | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
      -out respaldo-$(date +%F).dump.enc
```

La clave **no va en el respaldo** y **no va en el repositorio**. Si se pierde
la clave, el respaldo se pierde con ella.

## Cuando respaldar

| Frecuencia | Por que |
|---|---|
| **Todos los dias**, y el volumen tiene que ser automático | Una ficha que se pierde es un animal sin historial |
| Antes de cada migración | Una migración puede fallar a mitad |
| Antes de cambiar algo en el servidor | El respaldo es el punto de vuelta |
| Copia semanal fuera del servidor | Un respaldo en el mismo disco que se quemo no es un respaldo |

## La restauración

**Es el paso que casi nunca se hace, y el que mas importa.**

```bash
docker compose -f deploy/docker-compose.prod.yml exec -T postgres \
  pg_restore -U veterinaria -d veterinaria --clean --if-exists \
  < respaldo-2026-10-17.dump
```

Con un respaldo cifrado:

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
  -in respaldo-2026-10-17.dump.enc \
  | docker compose -f deploy/docker-compose.prod.yml exec -T postgres \
      pg_restore -U veterinaria -d veterinaria --clean --if-exists
```

### Que comprobar despues de restaurar

No basta con que el comando termine sin error.

```bash
# 1. Las tablas existen y tienen filas.
docker compose -f deploy/docker-compose.prod.yml exec -T postgres \
  psql -U veterinaria -d veterinaria \
  -c "SELECT (SELECT count(*) FROM tutores) AS tutores,
             (SELECT count(*) FROM mascotas) AS mascotas,
             (SELECT count(*) FROM consultas) AS consultas,
             (SELECT count(*) FROM vacunas) AS vacunas,
             (SELECT count(*) FROM ventas) AS ventas;"

# 2. LAS POLITICAS SIGUEN PUESTAS.
#    Un `pg_dump -Fc` NO incluye las politicas por defecto si se hizo con una
#    version distinta, y una base restaurada sin RLS es una base SIN AISLAMIENTO
#    que parece funcionar con normalidad.
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan clinica:verificar-aislamiento
```

**El paso 2 es obligatorio.** Una base restaurada sin RLS es el peor escenario
posible: la aplicación funciona, las pantallas se ven bien, y cada clinica ve
los datos de las demas.

## `pg_dump` y la RLS: una trampa

`pg_dump` incluye las definiciones de las políticas, pero **el contenido que
guarda depende del rol con el que se ejecuta**.

Un `pg_dump` hecho con un rol que tiene `BYPASSRLS` guarda **todas** las filas,
de todas las clinicas. Uno hecho con el rol de la aplicación guarda también
todo, porque es el dueno y `FORCE` lo aplica.

Eso es lo que uno quiere en un respaldo: **todo**.

Lo que uno NO quiere es que el respaldo quede en un lugar donde alguien con
acceso a la maquina pueda leerlo sin clave. De ahi el cifrado, y de ahi
`veterinaria_caddy_datos` fuera del volumen del respaldo.

## Bitacora de accesos

`accesos_ficha` esta en la misma base, así que viaja con el respaldo.

Puede separarse con `DB_BITACORA_*`, que es una de las separaciones que suele
pedir una auditoria externa. **No viene separada por omision.** Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

La bitacora es inmutable desde el modelo: `AccesoFicha` lanza `LogicException`
si alguien intenta modificar o borrar una fila.

## Purgar la bitacora

`VETERINARIA_RETENCION_BITACORA_DIAS` existe en la configuración.

**La purga NO esta implementada.** La tarea de mantenimiento esta declarada en
`routes/console.php` como gancho, pero no hace nada. Ver
[10](10-PRUEBAS-Y-ESTADO.md).

Ademas, el valor por omision (3650 dias) **no fue verificado** contra ninguna
normativa. El plazo que corresponda lo define la ley aplicable y la política de
la institucion.

## Lista de comprobacion antes de recibir animales reales

1. [ ] Respaldo automático diario, verificado con **una alerta que llega**.
2. [ ] Respaldo **cifrado**, con la clave en un lugar distinto al respaldo.
3. [ ] Copia semanal **fuera del servidor**.
4. [ ] **Restauración probada de verdad**, al menos una vez.
5. [ ] Tras restaurar, `clinica:verificar-aislamiento` en verde.
6. [ ] Alguien de la clinica sabe **donde esta el último respaldo** y como
      restaurarlo, sin necesitar a Hefesto.

El punto 6 es el que se olvida y el que duele cuando pasa.
