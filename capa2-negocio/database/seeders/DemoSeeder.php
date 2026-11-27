<?php

namespace Database\Seeders;

use App\Models\Usuario;
use App\Services\ContratoService;
use App\Services\EmisionService;
use App\Services\EscaletaService;
use App\Services\EspacioService;
use App\Services\MaterialService;
use App\Services\ParrillaService;
use App\Services\PautaService;
use App\Services\ProgramaService;
use App\Services\SolicitudService;
use App\Services\ValidacionService;
use App\Support\Bd;
use App\Support\Reloj;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Datos de demostracion coherentes con el flujo del canal. Todo pasa por los servicios (respeta las reglas de negocio
 * y queda en la bitacora) y usa el reloj fijo (CANAL11_AHORA) para "viajar" en el tiempo:
 * Flujo: el Programador arma la parrilla en BORRADOR (CU3), el Encargado de Contenidos revisa los videos de cada
 * bloque y los deja LISTOS (CU5), y recien entonces el Programador la publica.
 *   AYER     validada, publicada y conciliada (CU7); el deportivo se observo a ultima hora.
 *   HOY      validada y publicada: el Programador concilia cada bloque cuando sale al aire.
 *   MANANA   armada en BORRADOR y SIN VALIDAR: el Encargado de Contenidos la valida y luego se publica.
 *   PASADO   en BORRADOR: el Programador la sigue armando (CU3).
 * Cada material tiene un video real (negro, sin audio) con su duracion, generado con ffmpeg en storage/app/private/videos/demo.
 * La contrasena de estas cuentas es DEMO_PASSWORD (.env).
 */
class DemoSeeder extends Seeder
{
    /** clave (nombre del archivo de video) => [titulo, tipo, programa, segundos] */
    private const MATERIALES = [
        'io-01' => ['Nota 1: titulares', 'PROGRAMA', 'Informe Once', 300],
        'io-02' => ['Nota 2: informe central', 'PROGRAMA', 'Informe Once', 900],
        'io-03' => ['Nota 3: cierre', 'PROGRAMA', 'Informe Once', 600],
        'io-04' => ['Nota 4: entrevista', 'PROGRAMA', 'Informe Once', 400],
        'but-01' => ['Película: El último tren', 'PROGRAMA', 'Butaca Once', 6900],
        'but-02' => ['Película: Cielo andino', 'PROGRAMA', 'Butaca Once', 6600],
        'but-03' => ['Película: La ruta del sol', 'PROGRAMA', 'Butaca Once', 6780],
        'dep-01' => ['Deportivo 11: resumen de la fecha', 'PROGRAMA', 'Deportivo 11', 1500],
        'dep-02' => ['Deportivo 11: previa del clásico', 'PROGRAMA', 'Deportivo 11', 1620],
        'doc-01' => ['Mundo Animal: el cóndor', 'PROGRAMA', 'Mundo Animal', 3000],
    ];

    /** id de cada material sembrado, por su clave. */
    private array $m = [];

    public function run(): void
    {
        if (Bd::existe('SELECT 1 FROM usuario WHERE email = ?', ['carla.mendoza@gmail.com'])) {
            return;
        }
        if (!config('canal11.demo_password')) {
            $this->command?->warn('DEMO_PASSWORD no esta definida en .env: no se crean los datos de demostracion.');
            return;
        }
        $this->command?->info('Sembrando datos de demostración...');

        $admin = $this->usuario('1000001', 'Carla', 'Mendoza', 'carla.mendoza@gmail.com', '71000001', 'ADMINISTRADOR');
        $enc = $this->usuario('1000002', 'Jorge', 'Rivas', 'jorge.rivas@gmail.com', '71000002', 'ENCARGADO');
        $prog = $this->usuario('1000003', 'Lucía', 'Vargas', 'lucia.vargas@gmail.com', '71000003', 'PROGRAMADOR');
        // anunciantes: el primero del .env (si hay) y cuentas demo
        $bsol = $this->anunciante('1000011', 'Marcela', 'Suárez', 'marcela.suarez@gmail.com', true);
        $entel = $this->anunciante('1000012', 'Diego', 'Rojas', 'diego.rojas@gmail.com');
        $pil = $this->anunciante('1000013', 'Paola', 'Céspedes', 'paola.cespedes@gmail.com');

        $ayer = Reloj::ahora()->subDay()->format('Y-m-d');
        $hoy = Reloj::ahora()->format('Y-m-d');
        $manana = Reloj::ahora()->addDay()->format('Y-m-d');
        $pasado = Reloj::ahora()->addDays(2)->format('Y-m-d');
        $parrillas = app(ParrillaService::class);
        $escaleta = app(EscaletaService::class);
        $pautas = app(PautaService::class);
        $validacion = app(ValidacionService::class);
        $materiales = app(MaterialService::class);

        try {
            $this->reloj("{$ayer}T08:00");

            // CU2 - Programas (Administrador)
            foreach ([['Informe Once', 'NOTICIERO'], ['Butaca Once', 'PELICULA'], ['Deportivo 11', 'OTRO'], ['Mundo Animal', 'OTRO'], ['Club Infantil', 'SERIE']] as $p) {
                app(ProgramaService::class)->insertar($admin, $p);
            }

            // CU4 - Materiales (Encargado de Contenidos), cada uno con su video real
            foreach (self::MATERIALES as $clave => [$titulo, $tipo, $programa, $segundos]) {
                $this->m[$clave] = (int) $materiales->insertar($enc, [$titulo, $tipo, $programa, (string) $segundos, 'MP4', $this->video($clave, $segundos)])['datos']['id'];
            }
            // los spots son el video de cada anunciante (material SPOT registrado por el Encargado)
            foreach ([['bsol-01', 'Spot Navidad Banco Sol 30 s', 30], ['pil-01', 'Spot Verano PIL 30 s', 30], ['ent-01', 'Spot Entel Fin de Año 15 s', 15]] as [$clave, $titulo, $seg]) {
                $this->m[$clave] = (int) $materiales->insertar($enc, [$titulo, 'SPOT', '', (string) $seg, 'MP4', $this->video($clave, $seg)])['datos']['id'];
            }

            // CU6 - Espacios publicitarios del canal (Administrador): horario fijo, cupo de segundos por dia y precio por segundo
            $espacios = app(EspacioService::class);
            foreach ([['Mañana', '08:00', '09:00', '120', '6'], ['Mediodía', '12:00', '13:00', '180', '8'], ['Noche', '20:00', '23:00', '300', '10']] as $e) {
                $espacios->insertar($admin, $e);
            }

            // CU6 - Pautas aprobadas que el Administrador registra para cada anunciante: salen una vez por dia en la Noche
            // (la pausa comercial de las 21:00), durante su vigencia
            $pautas->insertar($admin, ['Navidad Banco Sol', $bsol->ci, Reloj::ahora()->subDays(3)->format('Y-m-d'), Reloj::ahora()->addDays(40)->format('Y-m-d'),
                'Noche:1', '30', 'CONTADO', '1', (string) $this->m['bsol-01']]);
            $pautas->insertar($admin, ['Verano PIL', $pil->ci, $ayer, Reloj::ahora()->addDays(60)->format('Y-m-d'), 'Noche:1', '30', 'MENSUAL', '3', (string) $this->m['pil-01']]);
            $pautas->insertar($admin, ['Fin de Año Entel', $entel->ci, $ayer, Reloj::ahora()->addDays(30)->format('Y-m-d'), 'Noche:1', '15', 'CONTADO', '1', (string) $this->m['ent-01']]);
            // las paga cada anunciante con tarjeta (Stripe); el Administrador no paga. PIL paga su 1a cuota de 3.
            $contratos = app(ContratoService::class);
            $contratos->sembrarPago('Navidad Banco Sol', 1, 'pi_demo_bsol_1');
            $contratos->sembrarPago('Fin de Año Entel', 1, 'pi_demo_entel_1');
            $contratos->sembrarPago('Verano PIL', 1, 'pi_demo_pil_1');

            // AYER: el Programador la arma en borrador, el Encargado valida cada bloque (CU5) y el Programador la publica (CU3)
            $this->armarDia($parrillas, $escaleta, $prog, $ayer, ['io-01', 'io-02', 'io-03'], 'but-01', 'dep-01');
            $this->validarTodo($validacion, $enc, $ayer);
            $parrillas->publicar($prog, [$ayer]);

            // AYER 22:30: antes de que empiece el deportivo, el Encargado lo observa (no llego la senal)
            $this->reloj("{$ayer}T22:30");
            $validacion->modificar($enc, [$ayer, '23:05', 'OBSERVADO', 'No llegó la señal del resumen deportivo desde el estadio.']);

            // AYER: el Programador concilia lo que realmente salio (CU7). El bloque OBSERVADO no salio al aire.
            $this->reloj("{$ayer}T23:50");
            $emision = app(EmisionService::class);
            $emision->insertar($prog, [$ayer, '20:00', '20:00:05', '21:00:00', 'EMITIDO', '']);
            $emision->insertar($prog, [$ayer, '21:00', '21:00:00', '21:01:15', 'EMITIDO', '']);
            $emision->insertar($prog, [$ayer, '21:05', '21:05:10', '22:50:00', 'PARCIAL', 'Se cortó la película 15 minutos antes por falla del transmisor.']);
            $emision->insertar($prog, [$ayer, '23:05', '', '', 'NO_EMITIDO', 'No llegó la señal del estadio.']);

            // HOY: validada por completo y publicada; se concilia cuando cada bloque sale al aire
            $this->reloj("{$hoy}T08:00");
            $this->armarDia($parrillas, $escaleta, $prog, $hoy, ['io-01', 'io-04', 'io-02'], 'but-02', 'dep-02');
            $this->validarTodo($validacion, $enc, $hoy);
            $parrillas->publicar($prog, [$hoy]);

            // HOY: un anunciante solicita una pauta nueva con su video (queda EN REVISION para el Administrador)
            app(SolicitudService::class)->insertar($entel, ['Promo Verano Entel', $manana, Reloj::ahora()->addDays(20)->format('Y-m-d'), 'Noche:1', 'CONTADO', '1',
                'Spot Promo Verano Entel 20 s', $this->video('ent-02', 20), 'MP4', '20']);

            // MANANA: armada en BORRADOR y SIN VALIDAR: el Encargado de Contenidos la revisa (CU5) y despues se publica
            $this->armarDia($parrillas, $escaleta, $prog, $manana, ['io-02', 'io-03', 'io-04'], 'but-03', 'dep-01');

            // PASADO MANANA: en BORRADOR (el Programador la sigue armando en el CU3)
            $parrillas->insertar($prog, [$pasado]);
            $parrillas->insertarBloque($prog, [$pasado, '20:00', '21:00', 'PROGRAMA', 'Informe Once', '']);
            $parrillas->insertarBloque($prog, [$pasado, '21:00', '21:01:15', 'PAUSA_COMERCIAL', '', '']);
            $parrillas->insertarBloque($prog, [$pasado, '21:05', '22:05', 'PROGRAMA', 'Mundo Animal', (string) $this->m['doc-01']]);
        } finally {
            $this->reloj(null);
        }

        $this->command?->info('Datos de demostración listos. La contraseña de las cuentas demo es DEMO_PASSWORD (.env).');
    }

    /** Noticiero con escaleta, pausa comercial (sus spots se programan solos), pelicula y deportivo. */
    private function armarDia(ParrillaService $parrillas, EscaletaService $escaleta, Usuario $prog, string $fecha, array $notas, string $pelicula, string $deportivo): void
    {
        $parrillas->insertar($prog, [$fecha]);
        $parrillas->insertarBloque($prog, [$fecha, '20:00', '21:00', 'PROGRAMA', 'Informe Once', '']);
        $parrillas->insertarBloque($prog, [$fecha, '21:00', '21:01:15', 'PAUSA_COMERCIAL', '', ''] /* llena con los spots demo: 30 + 30 + 15 s */);
        $parrillas->insertarBloque($prog, [$fecha, '21:05', '23:05', 'PROGRAMA', 'Butaca Once', (string) $this->m[$pelicula]]);
        $parrillas->insertarBloque($prog, [$fecha, '23:05', '23:35', 'PROGRAMA', 'Deportivo 11', (string) $this->m[$deportivo]]);
        foreach ($notas as $i => $clave) {
            $escaleta->insertar($prog, [$fecha, '20:00', (string) ($i + 1), (string) $this->m[$clave]]);
        }
    }

    /** El Encargado deja LISTO cada bloque de la parrilla (en borrador, antes de publicarla). */
    private function validarTodo(ValidacionService $validacion, Usuario $enc, string $fecha): void
    {
        foreach (['20:00', '21:00', '21:05', '23:05'] as $h) {
            $validacion->insertar($enc, [$fecha, $h, 'LISTO', '']);
        }
    }

    /** Video negro sin audio con la duracion del material (si ffmpeg no esta, el material queda sin video reproducible). */
    private function video(string $clave, int $segundos): string
    {
        $ruta = "videos/demo/{$clave}.mp4";
        $disco = Storage::disk('local');
        if (!$disco->exists($ruta)) {
            $disco->makeDirectory('videos/demo');
            @shell_exec('ffmpeg -y -loglevel error -f lavfi -i ' . escapeshellarg('color=c=black:s=320x240:r=1') . " -t {$segundos}"
                . ' -an -c:v libx264 -preset ultrafast -pix_fmt yuv420p -g 10 -movflags +faststart ' . escapeshellarg($disco->path($ruta)) . ' 2>&1');
        }
        if (!$disco->exists($ruta)) {
            $disco->put($ruta, ''); // sin ffmpeg: archivo vacio para que la ruta exista
        }
        return $ruta;
    }

    private function usuario(string $ci, string $nombre, string $apellido, string $email, string $telefono, string $rol): Usuario
    {
        $id = Bd::insertar('INSERT INTO usuario (id_rol, ci, nombre, apellido, email, telefono, contrasena) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Usuario::idRol($rol), $ci, $nombre, $apellido, $email, $telefono, Hash::make(config('canal11.demo_password'))], 'id_usuario');
        return Usuario::find($id);
    }

    /** Un anunciante: con $delEnv usa la primera cuenta de anunciante del .env si existe; si no, crea la cuenta demo. */
    private function anunciante(string $ci, string $nombre, string $apellido, string $email, bool $delEnv = false): Usuario
    {
        if ($delEnv) {
            $id = Bd::valor("SELECT u.id_usuario FROM usuario u JOIN rol r ON r.id_rol = u.id_rol WHERE r.nombre = 'ANUNCIANTE' AND u.activo ORDER BY u.id_usuario LIMIT 1");
            if ($id) {
                return Usuario::find($id);
            }
        }
        return $this->usuario($ci, $nombre, $apellido, $email, '7' . substr($ci, -7), 'ANUNCIANTE');
    }

    private function reloj(?string $ahora): void
    {
        config(['canal11.ahora' => $ahora]);
    }
}
