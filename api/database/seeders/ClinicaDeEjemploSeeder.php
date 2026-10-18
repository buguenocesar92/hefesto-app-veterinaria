<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MedioPago;
use App\Enums\RolClinico;
use App\Models\Cita;
use App\Models\Clinica;
use App\Models\Consulta;
use App\Models\Control;
use App\Models\EsquemaVacunacion;
use App\Models\Mascota;
use App\Models\Profesional;
use App\Models\Producto;
use App\Models\Prescripcion;
use App\Models\Tutor;
use App\Models\Usuario;
use App\Models\Vacuna;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Tenancy\ContextoTenant;
use App\Services\Vacunas\CalculadorDeProximaDosis;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * SEMILLA DE EJEMPLO: una clinica, un tutor, DOS mascotas y su historia.
 *
 * ===================================================================
 * POR QUE DOS MASCOTAS Y NO UNA
 * ===================================================================
 *
 * La semilla trae a proposito UN TUTOR con DOS mascotas de especies
 * distintas (un perro y un gato). No es adorno: es el caso que separa un
 * sistema bien hecho de uno que mezcla historiales.
 *
 * Si alguien abre la ficha de Firulais, solo debe ver lo de Firulais. La de
 * Michi sigue ahi, intacta, en su propia ficha. Esa es la prueba a mano, en
 * datos reales, de lo que comprueba
 * `HistorialNoSeMezclaEntreMascotasDelMismoTutorTest`.
 *
 * LOS ESQUEMAS DE VACUNACION VIENEN SIN VERIFICAR
 * -----------------------------------------------
 * Las filas de `esquemas_vacunacion` que carga esta semilla son
 * **EJEMPLOS, NO CALENDARIOS OFICIALES**. Van con `verificado = false` y con
 * la fuente escrita, precisamente para que nadie las tome por comprobadas.
 *
 * **Este proyecto no consulto ninguna fuente oficial de esquemas de
 * vacunacion.** Los intervalos dependen de la especie, de la edad, del
 * fabricante del producto y de la normativa sanitaria de la jurisdiction, y
 * ninguna de esas cuatro cosas se verifico aqui. Ver docs/02 y docs/07.
 *
 * La clinica real debe revisar cada fila antes de usarla, con un profesional
 * que sepa.
 */
class ClinicaDeEjemploSeeder extends Seeder
{
    public function run(): void
    {
        $contexto = app(ContextoTenant::class);

        $clinica = $this->crearClinica();

        // TODO lo demas se crea DENTRO del contexto de la clinica. Si se
        // creara fuera, la RLS rechazaria las filas, porque todas las tablas de
        // negocio tienen la politica puesta.
        $contexto->ejecutarAislado((string) $clinica->id, function () use ($clinica): void {
            $profesional = $this->crearProfesional($clinica);
            $this->crearUsuarios($clinica, $profesional);
            $this->crearCatalogoDeAtenciones($clinica);

            $tutor = $this->crearTutor($clinica);
            $perro = $this->crearPerro($clinica, $tutor);
            $gato = $this->crearGato($clinica, $tutor);

            $esquemas = $this->crearEsquemasDeVacunacion($clinica);
            $this->crearVacunasDelPerro($clinica, $perro, $profesional, $esquemas);
            $this->crearVacunasDelGato($clinica, $gato, $profesional, $esquemas);

            $this->crearConsultas($clinica, $perro, $gato, $profesional);
            $this->crearAgenda($clinica, $perro, $gato, $tutor, $profesional);
            $this->crearVentaDeMostrador($clinica, $tutor);
        });
    }

    private function crearClinica(): Clinica
    {
        return Clinica::query()->create([
            'slug' => 'veterinaria-demo',
            'nombre' => 'Clínica Veterinaria de Ejemplo',
            'zona_horaria' => 'America/Santiago',
            'correlacion_identidad' => 'veterinaria-demo',
            'activo' => true,
        ]);
    }

    private function crearProfesional(Clinica $clinica): Profesional
    {
        return Profesional::query()->create([
            'tenant_id' => $clinica->id,
            'nombre' => 'Dra. Camila Rios',
            'rut' => '15.555.555-5',
            'registro_profesional' => 'EJEMPLO-NO-VERIFICADO',
            'especialidad' => 'Medicina de animales pequeños',
            'activo' => true,
        ]);
    }

    private function crearUsuarios(Clinica $clinica, Profesional $profesional): void
    {
        $usuarios = [
            ['Administración', 'admin@veterinaria.local', RolClinico::Administrador, null],
            ['Profesional', 'profesional@veterinaria.local', RolClinico::Profesional, $profesional->id],
            ['Recepción', 'recepcion@veterinaria.local', RolClinico::Recepcion, null],
            ['Mostrador', 'mostrador@veterinaria.local', RolClinico::Mostrador, null],
        ];

        foreach ($usuarios as [$nombre, $correo, $rol, $profesionalId]) {
            Usuario::query()->create([
                'tenant_id' => $clinica->id,
                'nombre' => $nombre,
                'correo' => $correo,
                'rol' => $rol,
                'profesional_id' => $profesionalId,
                'activo' => true,
            ]);
        }
    }

    /**
     * El catalogo de la agenda, con el bloque de vacunacion aparte.
     */
    private function crearCatalogoDeAtenciones(Clinica $clinica): void
    {
        $tipos = [
            ['Consulta general', 30, false, 25000],
            ['Control de seguimiento', 15, false, 12000],
            ['Vacunación', 10, true, 15000],
            ['Desparasitación', 10, true, 14000],
            ['Cirugía menor', 60, false, 85000],
            ['Urgencia', 45, false, 45000],
        ];

        foreach ($tipos as [$nombre, $duracion, $bloqueVacunacion, $precio]) {
            \App\Models\TipoAtencion::query()->create([
                'tenant_id' => $clinica->id,
                'nombre' => $nombre,
                'duracion_minutos' => $duracion,
                'bloque_vacunacion' => $bloqueVacunacion,
                'precio' => $precio,
                'activo' => true,
            ]);
        }
    }

    private function crearTutor(Clinica $clinica): Tutor
    {
        return Tutor::query()->create([
            'tenant_id' => $clinica->id,
            'rut' => '12.345.678-9',
            'nombres' => 'Javiera',
            'apellidos' => 'Muñoz',
            'telefono' => '+56987654321',
            'correo' => 'javiera.munoz@ejemplo.local',
            'direccion' => 'Av. Siempre Viva 742',
            'comuna' => 'Ñuñoa',
            'activo' => true,
        ]);
    }

    private function crearPerro(Clinica $clinica, Tutor $tutor): Mascota
    {
        return Mascota::query()->create([
            'tenant_id' => $clinica->id,
            'tutor_id' => $tutor->id,
            'nombre' => 'Firulais',
            'especie' => 'canino',
            'raza' => 'Mestizo',
            'sexo' => 'macho',
            'fecha_nacimiento' => now()->subYears(4)->toDateString(),
            'peso_kg' => 24.8,
            'altura_cm' => 58.0,
            'color' => 'Café con manchas blancas',
            'microchip' => '985112003456789',
            'esterilizado' => true,
            'activo' => true,
        ]);
    }

    private function crearGato(Clinica $clinica, Tutor $tutor): Mascota
    {
        return Mascota::query()->create([
            'tenant_id' => $clinica->id,
            'tutor_id' => $tutor->id,
            'nombre' => 'Michi',
            'especie' => 'felino',
            'raza' => 'Europeo común',
            'sexo' => 'hembra',
            'fecha_nacimiento' => now()->subYears(2)->toDateString(),
            'peso_kg' => 4.3,
            'altura_cm' => 25.0,
            'color' => 'Gris atigrado',
            // Sin microchip: muchos gatos domesticos no lo tienen, y la
            // columna tiene que poder quedar en blanco sin inventar nada.
            'microchip' => null,
            'esterilizado' => true,
            'activo' => true,
        ]);
    }

    /**
     * Esquemas de EJEMPLO, sin verificar.
     *
     * Cada fila trae `verificado = false` y una `fuente` que dice
     * claramente que no salio de ninguna fuente oficial. La etiqueta de la
     * interfaz les pone [SIN CONFIRMAR].
     *
     * @return array<string, EsquemaVacunacion>
     */
    private function crearEsquemasDeVacunacion(Clinica $clinica): array
    {
        $filas = [
            // clave, especie, vacuna, dosis, edad minima, intervalo, refuerzo
            ['perro_1', 'canino', 'Vacuna polivalente (ejemplo)', 1, 8, 3, null],
            ['perro_2', 'canino', 'Vacuna polivalente (ejemplo)', 2, 3, 3, null],
            ['perro_3', 'canino', 'Vacuna polivalente (ejemplo)', 3, 6, 12, null],
            ['perro_parasitos', 'canino', 'Antiparasitario (ejemplo)', 1, 2, 3, null],
            ['gato_1', 'felino', 'Vacuna polivalente felina (ejemplo)', 1, 8, 3, null],
            ['gato_2', 'felino', 'Vacuna polivalente felina (ejemplo)', 2, 4, 3, null],
            ['gato_3', 'felino', 'Vacuna polivalente felina (ejemplo)', 3, 6, 12, null],
        ];

        $esquemas = [];

        foreach ($filas as [$clave, $especie, $vacuna, $dosis, $edadMinima, $intervalo, $refuerzo]) {
            $esquemas[$clave] = EsquemaVacunacion::query()->create([
                'tenant_id' => $clinica->id,
                'especie' => $especie,
                'nombre_vacuna' => $vacuna,
                'dosis_numero' => $dosis,
                'edad_minima_meses' => $edadMinima,
                'intervalo_meses' => $intervalo,
                'refuerzo_meses' => $refuerzo,
                'verificado' => false,
                'fuente' => 'EJEMPLO DE LA SEMILLA. No proviene de ninguna fuente oficial. '
                    . 'Debe confirmarlo un profesional veterinario con la normativa vigente '
                    . 'antes de usarse. Ver docs/07.',
                'notas' => 'Regla de ejemplo cargada por el seeder. No usar sin confirmar.',
                'activo' => true,
            ]);
        }

        return $esquemas;
    }

    /**
     * Vacunas del PERRO: una al dia y una vencida, para que el tablero de
     * atraso tenga algo que mostrar.
     */
    private function crearVacunasDelPerro(Clinica $clinica, Mascota $perro, Profesional $profesional, array $esquemas): void
    {
        // Aplicada hace 5 meses, la proxima cae DENTRO de un mes: al dia.
        $this->crearVacuna(
            $clinica,
            $perro,
            $profesional,
            $esquemas['perro_2'],
            now()->subMonths(5),
            'LOTE-DEMO-0001',
            'Laboratorio de ejemplo',
        );

        // Aplicada hace 16 meses, la proxima VENCIO hace 4 meses: atraso.
        $this->crearVacuna(
            $clinica,
            $perro,
            $profesional,
            $esquemas['perro_3'],
            now()->subMonths(16),
            'LOTE-DEMO-0002',
            'Laboratorio de ejemplo',
        );
    }

    /**
     * Vacunas del GATO: propias, con su propio calendario.
     *
     * Que sean de OTRO esquema y otro lote es lo que permite comprobar a mano
     * que la ficha de Firulais no trae nada de Michi.
     */
    private function crearVacunasDelGato(Clinica $clinica, Mascota $gato, Profesional $profesional, array $esquemas): void
    {
        $this->crearVacuna(
            $clinica,
            $gato,
            $profesional,
            $esquemas['gato_2'],
            now()->subMonths(4),
            'LOTE-DEMO-GATO-0001',
            'Laboratorio de ejemplo',
        );
    }

    /**
     * Registra una aplicacion y CALCULA la proxima dosis con el servicio real.
     *
     * Usa `CalculadorDeProximaDosis`, el mismo que usa la aplicacion. Si la
     * semilla calculara la fecha a mano, podria diferir de lo que el sistema
     * hace de verdad, y la semilla dejaria de servir para comprobar nada.
     */
    private function crearVacuna(
        Clinica $clinica,
        Mascota $mascota,
        Profesional $profesional,
        EsquemaVacunacion $esquema,
        Carbon $fechaAplicacion,
        string $lote,
        string $laboratorio,
    ): void {
        $proxima = CalculadorDeProximaDosis::calcular(
            $esquema,
            \DateTimeImmutable::createFromFormat('Y-m-d', $fechaAplicacion->toDateString()),
        );

        Vacuna::query()->create([
            'tenant_id' => $clinica->id,
            'mascota_id' => $mascota->id,
            'esquema_id' => $esquema->id,
            'profesional_id' => $profesional->id,
            'fecha_aplicacion' => $fechaAplicacion->toDateString(),
            'proxima_dose_en' => $proxima?->format('Y-m-d'),
            'lote' => $lote,
            'laboratorio' => $laboratorio,
            'estado' => 'aplicada',
        ]);
    }

    /**
     * Historial clinico de CADA mascota, por separado.
     *
     * El texto de cada una es distinto y reconocible a propósito: al abrir la
     * ficha de una, tiene que verse solo el texto de esa.
     */
    private function crearConsultas(Clinica $clinica, Mascota $perro, Mascota $gato, Profesional $profesional): void
    {
        $consultaPerro = Consulta::query()->create([
            'tenant_id' => $clinica->id,
            'mascota_id' => $perro->id,
            'profesional_id' => $profesional->id,
            'fecha' => now()->subMonths(5),
            'motivo_consulta' => 'Control general anual',
            'examen_fisico' => 'Temperatura 38,4 °C. FC 92. Pulmones limpios.',
            'diagnostico' => 'SANO. Sin hallazgos.',
            'tratamiento' => 'Desparasitación interna y externa.',
            'evolucion' => 'Peso estable. Buen estado general.',
        ]);

        Control::query()->create([
            'tenant_id' => $clinica->id,
            'mascota_id' => $perro->id,
            'consulta_id' => $consultaPerro->id,
            'profesional_id' => $profesional->id,
            'fecha' => now()->subMonths(5),
            'descripcion' => 'Revisar piel y estado del pelaje.',
            'proximo_control_en' => now()->addMonths(6)->toDateString(),
            'cumplido' => false,
        ]);

        $consultaGato = Consulta::query()->create([
            'tenant_id' => $clinica->id,
            'mascota_id' => $gato->id,
            'profesional_id' => $profesional->id,
            'fecha' => now()->subMonths(4),
            'motivo_consulta' => 'No come desde ayer',
            'examen_fisico' => 'Temperatura 38,9 °C. Abdomen sensible a la palpación.',
            'diagnostico' => 'Gastritis aguda, probable cambio de alimento.',
            'tratamiento' => 'Dieta blanda 3 días. Antiemético por 2 días.',
            'evolucion' => 'Continuar con dieta y control en 5 días.',
        ]);

        Control::query()->create([
            'tenant_id' => $clinica->id,
            'mascota_id' => $gato->id,
            'consulta_id' => $consultaGato->id,
            'profesional_id' => $profesional->id,
            'fecha' => now()->subMonths(4),
            'descripcion' => 'Control de la dieta y del sintoma.',
            'proximo_control_en' => now()->addDays(5)->toDateString(),
            'cumplido' => false,
        ]);

        Prescripcion::query()->create([
            'tenant_id' => $clinica->id,
            'mascota_id' => $gato->id,
            'consulta_id' => $consultaGato->id,
            'profesional_id' => $profesional->id,
            'fecha' => now()->subMonths(4),
            'medicamento' => 'Antiemético de ejemplo',
            'dosis' => 'Media dosis por tomado',
            'via' => 'Oral',
            'frecuencia' => 'Cada 8 horas',
            'duracion_dias' => 2,
            'indicaciones' => 'Mezclar con algo de comida.',
            'estado' => 'emitida',
        ]);
    }

    /**
     * Agenda: dos citas para el mismo tutor, en dias distintos.
     */
    private function crearAgenda(Clinica $clinica, Mascota $perro, Mascota $gato, Tutor $tutor, Profesional $profesional): void
    {
        $tipos = \App\Models\TipoAtencion::query()
            ->where('tenant_id', $clinica->id)
            ->get()
            ->keyBy('nombre');

        // Mañana: el perro, en el bloque de vacunacion (10 minutos).
        $mananaVacunacion = now()->addDay()->setTime(10, 0);

        Cita::query()->create([
            'tenant_id' => $clinica->id,
            'mascota_id' => $perro->id,
            'tutor_id' => $tutor->id,
            'profesional_id' => $profesional->id,
            'tipo_atencion_id' => $tipos['Vacunación']->id,
            'inicio' => $mananaVacunacion,
            'fin' => $mananaVacunacion->copy()->addMinutes($tipos['Vacunación']->duracion_minutos),
            'estado' => 'confirmada',
            'motivo' => 'Vacunación anual',
        ]);

        // Pasado mañana: el gato, consulta general (30 minutos).
        $pasadoManana = now()->addDays(2)->setTime(16, 30);

        Cita::query()->create([
            'tenant_id' => $clinica->id,
            'mascota_id' => $gato->id,
            'tutor_id' => $tutor->id,
            'profesional_id' => $profesional->id,
            'tipo_atencion_id' => $tipos['Consulta general']->id,
            'inicio' => $pasadoManana,
            'fin' => $pasadoManana->copy()->addMinutes($tipos['Consulta general']->duracion_minutos),
            'estado' => 'pendiente',
            'motivo' => 'Control del peso y del apetito',
        ]);
    }

    /**
     * Una venta de mostrador, con stock descontado.
     */
    private function crearVentaDeMostrador(Clinica $clinica, Tutor $tutor): void
    {
        $productos = [
            ['ALIM-001', 'Alimento para perro adulto 3 kg', 'Alimento', 18500, 24, 6],
            ['ANTIP-001', 'Antiparasitario (ejemplo)', 'Antiparasitario', 12900, 15, 4],
            ['COLLAR-001', 'Collar antipulgas y garrapatas', 'Accesorios', 9900, 8, 3],
        ];

        $creados = [];

        foreach ($productos as [$sku, $nombre, $categoria, $precio, $stock, $stockMinimo]) {
            $creados[$sku] = Producto::query()->create([
                'tenant_id' => $clinica->id,
                'sku' => $sku,
                'nombre' => $nombre,
                'categoria' => $categoria,
                'precio' => $precio,
                'stock' => $stock,
                'stock_minimo' => $stockMinimo,
                'unidad' => 'unidad',
                'requiere_receta' => false,
                'activo' => true,
            ]);
        }

        $usuario = Usuario::query()
            ->where('tenant_id', $clinica->id)
            ->where('correo', 'mostrador@veterinaria.local')
            ->firstOrFail();

        $lineas = [
            [$creados['ALIM-001'], 2],
            [$creados['ANTIP-001'], 1],
        ];

        $total = 0;

        foreach ($lineas as [$producto, $cantidad]) {
            $subtotal = $producto->precio * $cantidad;
            $total += $subtotal;

            // Stock descontado, con guarda para no bajar de cero.
            $producto->stock = max(0, $producto->stock - $cantidad);
            $producto->save();
        }

        $venta = Venta::query()->create([
            'tenant_id' => $clinica->id,
            'tutor_id' => $tutor->id,
            'usuario_id' => $usuario->id,
            'fecha' => now(),
            'total' => $total,
            'medio_pago' => MedioPago::Efectivo,
            'anulada' => false,
        ]);

        foreach ($lineas as [$producto, $cantidad]) {
            VentaItem::query()->create([
                'tenant_id' => $clinica->id,
                'venta_id' => $venta->id,
                'producto_id' => $producto->id,
                'nombre_producto' => $producto->nombre,
                'cantidad' => $cantidad,
                'precio_unitario' => $producto->precio,
                'subtotal' => $producto->precio * $cantidad,
            ]);
        }
    }
}
