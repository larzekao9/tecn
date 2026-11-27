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

    /** Duración del video en segundos, leída con ffprobe. null si no se pudo determinar. */
    private function duracionDelVideo(string $path): ?int
    {
        $salida = @shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null');
        $seg = is_string($salida) ? (float) trim($salida) : 0.0;
        return $seg >= 1 ? (int) round($seg) : null;
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
