<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\BusquedaService;
use App\Services\ContratoService;
use App\Services\EstadisticaService;
use App\Services\MaterialService;
use App\Services\NotificacionService;
use App\Services\ParrillaService;
use App\Services\ProgramaService;
use App\Support\Bd;
use App\Support\Catalogo;
use App\Support\Reloj;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Notificaciones, contador de visitas, busqueda global, subida de videos y opciones de formularios. */
class GeneralController extends Controller
{
    /**
     * Salud del sistema (publico, sin datos sensibles): el servidor responde y la base de datos acepta conexiones
     * y tiene las tablas. 200 si todo esta bien; 503 con el motivo si la BD no responde o esta vacia.
     * Lo usan el despliegue (antes y despues de subir) y la vigilancia programada de GitHub Actions.
     */
    public function salud(): JsonResponse
    {
        try {
            Bd::filas('SELECT 1 FROM usuario LIMIT 1');
            Bd::filas('SELECT 1 FROM parrilla LIMIT 1');
            return response()->json(['servidor' => 'ok', 'bd' => 'ok']);
        } catch (\Throwable $e) {
            $estado = $e instanceof \PDOException || $e->getPrevious() instanceof \PDOException
                ? (string) ($e->getPrevious()?->getCode() ?: $e->getCode()) : '';
            $motivo = match (true) {
                in_array($estado, ['42P01', '3F000'], true) => 'La base de datos responde pero faltan el esquema o las tablas (¿la borraron?).',
                str_starts_with($estado, '28') => 'La base de datos rechaza el usuario o la contraseña del .env.',
                default => 'La base de datos no responde (caída o sin conexión).',
            };
            return response()->json(['servidor' => 'ok', 'bd' => 'error', 'motivo' => $motivo, 'codigo' => $estado ?: null], 503);
        }
    }

    public function notificaciones(Request $r, NotificacionService $n): JsonResponse
    {
        return response()->json(['ok' => true, 'datos' => $n->listar($r->user(), $r->boolean('no_leidas')), 'sin_leer' => $n->noLeidas($r->user())]);
    }

    public function contador(Request $r, NotificacionService $n): JsonResponse
    {
        return response()->json(['ok' => true, 'sin_leer' => $n->noLeidas($r->user())]);
    }

    public function leer(Request $r, NotificacionService $n, int $id): JsonResponse
    {
        $n->marcarLeida($r->user(), $id);
        return response()->json(['ok' => true, 'sin_leer' => $n->noLeidas($r->user())]);
    }

    public function leerTodas(Request $r, NotificacionService $n): JsonResponse
    {
        $n->marcarTodas($r->user());
        return response()->json(['ok' => true, 'sin_leer' => 0]);
    }

    /** Contador de visitas por pagina (publico: tambien cuenta la pagina de login). */
    public function visita(Request $r, EstadisticaService $e): JsonResponse
    {
        $usuario = auth('sanctum')->user();
        return response()->json(['ok' => true, 'visitas' => $e->visitar((string) $r->input('pagina', '/'), $usuario?->id_usuario)]);
    }

    public function buscar(Request $r, BusquedaService $b): JsonResponse
    {
        return response()->json(['ok' => true, 'datos' => $b->buscar($r->user(), (string) $r->query('q', ''))]);
    }

    /** Sube un video al proyecto (capa2-negocio/storage/app/videos) y devuelve su ruta y formato. */
    public function subirVideo(Request $r): JsonResponse
    {
        $r->validate(
            ['video' => ['required', 'file', 'max:307200']],
            ['video.required' => 'Elija un archivo de video.', 'video.file' => 'El archivo subido no es válido.', 'video.max' => 'El video no puede superar los 300 MB.']
        );
        $file = $r->file('video');
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $formato = ['mp4' => 'MP4', 'mov' => 'MOV', 'mxf' => 'MXF'][$ext] ?? null;
        if ($formato === null) {
            return response()->json(['ok' => false, 'message' => 'El video debe ser .mp4, .mov o .mxf.', 'errores' => ['El video debe ser .mp4, .mov o .mxf.']], 422);
        }
        // Forzamos la extensión real (store() la adivina por el contenido); el nombre es único.
        $ruta = $file->storeAs('videos', \Illuminate\Support\Str::random(40) . '.' . $ext, 'local');
        return response()->json(['ok' => true, 'datos' => [
            'ruta' => $ruta,
            'formato' => $formato,
            'duracion_seg' => $this->duracionDelVideo(\Illuminate\Support\Facades\Storage::disk('local')->path($ruta)),
            'nombre' => $file->getClientOriginalName(),
            'tamano_mb' => round($file->getSize() / 1048576, 1),
        ]]);
    }

    /** Reproduce el video de un material. La URL viene firmada desde mosMat (el <video> no puede mandar el token). */
    public function verVideo(int $id)
    {
        $ruta = Bd::valor('SELECT ruta FROM material WHERE id_material = ?', [$id]);
        $disco = \Illuminate\Support\Facades\Storage::disk('local');
        if (!$ruta || !$disco->exists($ruta)) {
            abort(404, 'El archivo de video no existe.');
        }
        $tipos = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'mxf' => 'application/mxf'];
        $tipo = $tipos[strtolower(pathinfo($ruta, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        // BinaryFileResponse atiende peticiones Range: se puede adelantar y retroceder sin bajar todo el archivo
        return response()->file($disco->path($ruta), ['Content-Type' => $tipo]);
    }

    /**
     * Duración del video en segundos. null si no se pudo determinar.
     * Primero lee la cabecera MP4/MOV en PHP puro (funciona en hosting sin ffprobe ni shell_exec);
     * si no sirve (p. ej. MXF), intenta con ffprobe cuando el servidor lo tiene.
     */
    private function duracionDelVideo(string $path): ?int
    {
        $seg = $this->duracionMp4($path);
        if ($seg === null && function_exists('shell_exec')) {
            $salida = @shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null');
            $seg = is_string($salida) ? (float) trim($salida) : null;
        }
        return $seg !== null && $seg >= 1 ? (int) round($seg) : null;
    }

    /** Lee la duración del átomo moov/mvhd de un MP4/MOV (ISO BMFF). null si no lo encuentra. */
    private function duracionMp4(string $path): ?float
    {
        $f = @fopen($path, 'rb');
        if (!$f) {
            return null;
        }
        try {
            $tamano = filesize($path);
            $moov = $this->buscarCaja($f, 0, $tamano, 'moov');
            if ($moov === null) {
                return null;
            }
            $mvhd = $this->buscarCaja($f, $moov[0], $moov[1], 'mvhd');
            if ($mvhd === null) {
                return null;
            }
            fseek($f, $mvhd[0]);
            $version = ord((string) fread($f, 4)[0] ?? "\0");
            if ($version === 1) {
                fseek($f, 16, SEEK_CUR); // creation(8) + modification(8)
                $escala = unpack('N', (string) fread($f, 4))[1] ?? 0;
                $d = unpack('J', (string) fread($f, 8))[1] ?? 0;
            } else {
                fseek($f, 8, SEEK_CUR); // creation(4) + modification(4)
                $escala = unpack('N', (string) fread($f, 4))[1] ?? 0;
                $d = unpack('N', (string) fread($f, 4))[1] ?? 0;
            }
            return $escala > 0 ? $d / $escala : null;
        } finally {
            fclose($f);
        }
    }

    /** Busca una caja por tipo entre [$inicio, $fin). Devuelve [inicio del contenido, fin de la caja] o null. */
    private function buscarCaja($f, int $inicio, int $fin, string $tipo): ?array
    {
        $pos = $inicio;
        while ($pos + 8 <= $fin) {
            fseek($f, $pos);
            $cab = fread($f, 8);
            if ($cab === false || strlen($cab) < 8) {
                return null;
            }
            $tam = unpack('N', substr($cab, 0, 4))[1];
            $t = substr($cab, 4, 4);
            $cabecera = 8;
            if ($tam === 1) { // tamaño de 64 bits
                $tam = unpack('J', (string) fread($f, 8))[1] ?? 0;
                $cabecera = 16;
            } elseif ($tam === 0) { // hasta el final
                $tam = $fin - $pos;
            }
            if ($tam < $cabecera) {
                return null;
            }
            if ($t === $tipo) {
                return [$pos + $cabecera, min($pos + $tam, $fin)];
            }
            $pos += $tam;
        }
        return null;
    }

    /** Listas para los selectores de los formularios (solo datos que el rol puede consultar). */
    public function opciones(Request $r): JsonResponse
    {
        $rol = $r->user()->rol;
        $d = [
            'roles' => Usuario::NOMBRES_ROL,
            'tipos_programa' => ProgramaService::TIPOS,
            'tipos_material' => MaterialService::NOMBRES_TIPO,
            'formatos' => MaterialService::FORMATOS,
            'tipos_bloque' => ParrillaService::TIPOS_BLOQUE,
            'pautas' => [
                // el precio por segundo es de cada espacio publicitario (tabla espacio)
                'simbolo' => config('canal11.pautas.simbolo'),
                'max_cuotas' => (int) config('canal11.pautas.max_cuotas'),
                // pago por QR (Cash App de Stripe): se cobra en esta moneda al tipo de cambio
                'qr_moneda' => strtoupper((string) config('canal11.stripe.qr_moneda')),
                'tipo_cambio' => (float) config('canal11.stripe.tipo_cambio'),
                'frecuencias' => ContratoService::FRECUENCIAS,
                'metodos' => ContratoService::METODOS,
                'minimo' => (float) config('canal11.stripe.minimo'),
            ],
            'hoy' => Reloj::hoy(),
            'ahora' => Reloj::ahora()->format('Y-m-d H:i:s'),
        ];
        if (Catalogo::permite('lisPro', $rol)) {
            $d['programas'] = Bd::filas('SELECT nombre, tipo FROM programa ORDER BY nombre');
        }
        if (Catalogo::permite('lisMat', $rol)) {
            $d['materiales'] = Bd::filas("SELECT m.id_material AS id, m.titulo, m.tipo, m.duracion_seg, coalesce(p.nombre, '') AS programa"
                . ' FROM material m LEFT JOIN programa p ON p.id_programa = m.id_programa ORDER BY m.titulo');
        }
        if (Catalogo::permite('insPau', $rol)) {
            // los anunciantes son usuarios con rol ANUNCIANTE
            $d['anunciantes'] = Bd::filas("SELECT u.id_usuario AS id, u.ci, u.nombre || ' ' || u.apellido AS nombre, u.email FROM usuario u JOIN rol r ON r.id_rol = u.id_rol"
                . " WHERE r.nombre = 'ANUNCIANTE' AND u.activo ORDER BY u.nombre, u.apellido");
        }
        return response()->json(['ok' => true, 'datos' => $d]);
    }
}
