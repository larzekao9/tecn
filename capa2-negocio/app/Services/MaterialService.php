<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Validador;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * CU4 - Materiales de Video. Reglas de negocio:
 *  - Un material se identifica por su id y se muestra por su titulo.
 *  - Tipo: PROGRAMA o SPOT. Si es PROGRAMA, el programa es obligatorio (programa agrupa material).
 *  - Formato: MP4, MOV o MXF. Duracion 1 a 86400 segundos. La ruta termina en la extension del formato.
 *  - La ruta es unica: si ya existe se informa el material que la usa (evita archivos duplicados).
 *  - El usuario que lo sube lo registra. Un material registrado ya se puede usar en la parrilla y en pautas
 *    (el control antes de salir al aire es el CU5, que valida cada bloque de la parrilla).
 *  - modMat: si esta en una parrilla PUBLICADA no se permite cambiar ruta, duracion ni tipo.
 *  - eliMat borra el material y NO se permite si esta en uso (bloque, escaleta o pauta).
 */
class MaterialService
{
    public const TIPOS = ['PROGRAMA', 'SPOT'];
    public const NOMBRES_TIPO = ['PROGRAMA' => 'Programa', 'SPOT' => 'Spot publicitario'];
    public const FORMATOS = ['MP4', 'MOV', 'MXF'];

    public const SELECT = "SELECT m.id_material AS id, m.titulo, m.tipo, coalesce(p.nombre, '') AS programa, m.duracion_seg, m.formato, m.ruta,"
        . " m.id_usuario AS id_registrado_por, r.nombre || ' ' || r.apellido AS registrado_por"
        . ' FROM material m'
        . ' LEFT JOIN programa p ON p.id_programa = m.id_programa'
        . ' JOIN usuario r ON r.id_usuario = m.id_usuario';

    public function __construct(private BitacoraService $bitacora)
    {
    }

    /** lisMat: "*", un tipo (PROGRAMA / SPOT) u otro texto (titulo o programa). */
    public function listar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $f = $v->texto($p[0] ?? '*', 'filtro', 60);
        $v->verificar();
        $mayus = strtoupper($f);
        if ($f === '*') {
            $datos = Bd::filas(self::SELECT . ' ORDER BY m.titulo');
        } elseif (in_array($mayus, self::TIPOS, true)) {
            $datos = Bd::filas(self::SELECT . ' WHERE m.tipo = ? ORDER BY m.titulo', [$mayus]);
        } else {
            $like = "%{$f}%";
            $datos = Bd::filas(self::SELECT . ' WHERE m.titulo ILIKE ? OR p.nombre ILIKE ? ORDER BY m.titulo', [$like, $like]);
        }
        return ['titulo' => 'Materiales', 'datos' => $datos];
    }

    public function mostrar(Usuario $quien, array $p): array
    {
        $id = $this->resolver($p[0] ?? null);
        $m = $this->obtener($id);
        $m['posibles_duplicados'] = $this->posiblesDuplicados($id);
        $m['uso'] = Bd::filas("SELECT to_char(pr.fecha, 'YYYY-MM-DD') AS fecha, to_char(b.hora_inicio, 'HH24:MI') AS hora, pr.estado,"
            . " 'BLOQUE' AS como FROM bloque b JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla WHERE b.id_material = ?"
            . " UNION ALL SELECT to_char(pr.fecha, 'YYYY-MM-DD'), to_char(b.hora_inicio, 'HH24:MI'), pr.estado, 'ESCALETA'"
            . ' FROM escaleta e JOIN bloque b ON b.id_bloque = e.id_bloque JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla WHERE e.id_material = ?'
            . " UNION ALL SELECT to_char(pr.fecha, 'YYYY-MM-DD'), to_char(s.hora, 'HH24:MI:SS'), s.estado, 'SPOT'"
            . ' FROM spot s JOIN pauta pa ON pa.id_pauta = s.id_pauta JOIN bloque b ON b.id_bloque = s.id_bloque'
            . ' JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla WHERE pa.id_material = ?'
            . ' ORDER BY 1, 2', [$id, $id, $id]);
        $m['video_url'] = self::urlVideo($id, $m['ruta']);
        return ['titulo' => 'Material', 'datos' => $m];
    }

    /** Enlace firmado y temporal (relativo: pasa por el proxy /api del front) para ver el video; null si el archivo no esta cargado. */
    public static function urlVideo(int $id, ?string $ruta): ?string
    {
        return $ruta && Storage::disk('local')->exists($ruta)
            ? URL::temporarySignedRoute('material.video', now()->addMinutes(config('canal11.video_enlace_minutos')), ['id' => $id], false)
            : null;
    }

    /** insMat["titulo","tipo","programa","duracion_seg","formato","ruta"]. */
    public function insertar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $titulo = $v->texto($p[0] ?? null, 'titulo', 160);
        $tipo = $v->opcion($p[1] ?? null, 'tipo', self::TIPOS);
        $programa = $v->textoOpcional($p[2] ?? null, 'programa', 120);
        $duracion = $v->entero($p[3] ?? null, 'duracion_seg', 1, 86400);
        $formato = $v->opcion($p[4] ?? null, 'formato', self::FORMATOS);
        $ruta = $v->texto($p[5] ?? null, 'ruta', 255);
        if ($tipo === 'PROGRAMA' && $programa === null) {
            $v->error('Un material de tipo <b>PROGRAMA</b> necesita el nombre del programa al que pertenece.');
        }
        $v->verificar();

        $this->validarRuta($v, $ruta, 0);
        $this->validarExtension($v, $ruta, $formato);
        $idPrograma = $tipo === 'PROGRAMA' ? $this->resolverPrograma($v, $programa) : null;
        $v->verificar();

        $id = Bd::insertar('INSERT INTO material (id_programa, id_usuario, titulo, tipo, duracion_seg, formato, ruta) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$idPrograma, $quien->id_usuario, $titulo, $tipo, $duracion, $formato, $ruta], 'id_material');
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'material');

        $mensaje = 'El material <b>' . Validador::esc($titulo) . '</b> fue registrado. Ya puede usarse en la parrilla.';
        $dup = $this->posiblesDuplicados($id);
        if ($dup !== []) {
            $mensaje .= '<br>Atención: hay otros materiales con el mismo título, tipo y duración (posibles duplicados): <b>'
                . Validador::esc(implode(', ', $dup)) . '</b>.';
        }
        return ['titulo' => 'Material registrado', 'mensaje' => $mensaje, 'datos' => $this->obtener($id)];
    }

    /** modMat["material (id)","titulo","tipo","programa","duracion_seg","formato","ruta"]. */
    public function modificar(Usuario $quien, array $p): array
    {
        $id = $this->resolver($p[0] ?? null);
        $v = new Validador();
        $titulo = $v->texto($p[1] ?? null, 'titulo', 160);
        $tipo = $v->opcion($p[2] ?? null, 'tipo', self::TIPOS);
        $programa = $v->textoOpcional($p[3] ?? null, 'programa', 120);
        $duracion = $v->entero($p[4] ?? null, 'duracion_seg', 1, 86400);
        $formato = $v->opcion($p[5] ?? null, 'formato', self::FORMATOS);
        $ruta = $v->texto($p[6] ?? null, 'ruta', 255);
        if ($tipo === 'PROGRAMA' && $programa === null) {
            $v->error('Un material de tipo <b>PROGRAMA</b> necesita el nombre del programa al que pertenece.');
        }
        $v->verificar();

        $actual = $this->obtener($id);
        $this->validarRuta($v, $ruta, $id);
        $this->validarExtension($v, $ruta, $formato);
        $idPrograma = $tipo === 'PROGRAMA' ? $this->resolverPrograma($v, $programa) : null;
        $v->verificar();

        $cambioContenido = strcasecmp($actual['ruta'], $ruta) !== 0 || (int) $actual['duracion_seg'] !== $duracion;
        $enPublicadas = $this->contarEnParrillasPublicadas($id);
        if ($enPublicadas > 0 && ($cambioContenido || $actual['tipo'] !== $tipo)) {
            throw new NegocioException('El material <b>' . Validador::esc($actual['titulo']) . "</b> está en {$enPublicadas} bloque(s) de parrillas PUBLICADAS: "
                . 'no se puede cambiar su ruta, duración ni tipo (la parrilla publicada quedaría inconsistente). '
                . 'Registre un material nuevo y cámbielo en la parrilla.');
        }

        Bd::ejecutar('UPDATE material SET id_programa = ?, titulo = ?, tipo = ?, duracion_seg = ?, formato = ?, ruta = ? WHERE id_material = ?',
            [$idPrograma, $titulo, $tipo, $duracion, $formato, $ruta, $id]);
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'material');
        return ['titulo' => 'Material modificado',
            'mensaje' => 'El material fue actualizado.' . ($cambioContenido ? ' Si estaba en un bloque ya validado (CU5), ese bloque debe validarse de nuevo.' : ''),
            'datos' => $this->obtener($id)];
    }

    /** eliMat["material (id)"]. */
    public function eliminar(Usuario $quien, array $p): array
    {
        $id = $this->resolver($p[0] ?? null);
        $actual = $this->obtener($id);
        $titulo = Validador::esc($actual['titulo']);
        $v = new Validador();
        $usos = [
            'bloque WHERE id_material = ?' => 'bloque(s) de parrilla',
            'escaleta WHERE id_material = ?' => 'nota(s) de escaleta',
            'pauta WHERE id_material = ?' => 'pauta(s) publicitaria(s)',
        ];
        foreach ($usos as $desde => $donde) {
            $n = (int) Bd::valor("SELECT count(*) FROM {$desde}", array_fill(0, substr_count($desde, '?'), $id));
            if ($n > 0) {
                $v->error("El material <b>{$titulo}</b> está en {$n} {$donde}.");
            }
        }
        $v->verificar();

        Bd::ejecutar('DELETE FROM material WHERE id_material = ?', [$id]);
        $this->bitacora->accion($quien, BitacoraService::ELIMINAR, 'material');
        return ['titulo' => 'Material eliminado', 'mensaje' => "El material <b>{$titulo}</b> fue eliminado.", 'datos' => $actual];
    }

    // ------------------------------------------------------------------ auxiliares (tambien los usan otros servicios)

    public function obtener(int $id): array
    {
        $f = Bd::fila(self::SELECT . ' WHERE m.id_material = ?', [$id]);
        if ($f === null) {
            throw new NegocioException('El material no existe.', 404);
        }
        return $f;
    }

    public function resolver(mixed $referencia): int
    {
        $v = new Validador();
        $ref = $v->texto($referencia, 'material', 120);
        $v->verificar();
        $id = $this->buscarId($ref);
        if ($id === null) {
            throw new NegocioException('No existe el material <b>' . Validador::esc($ref) . '</b>. Consulte la lista de materiales.', 404);
        }
        return $id;
    }

    /** Por id (o por titulo exacto, si hay uno solo con ese titulo). */
    public function buscarId(string $ref): ?int
    {
        if (preg_match('/^\d{1,9}$/', $ref)) {
            $id = Bd::valor('SELECT id_material FROM material WHERE id_material = ?', [(int) $ref]);
            return $id === null ? null : (int) $id;
        }
        $ids = Bd::filas('SELECT id_material FROM material WHERE lower(titulo) = lower(?)', [$ref]);
        return count($ids) === 1 ? (int) $ids[0]['id_material'] : null;
    }

    public function contarEnParrillasPublicadas(int $id): int
    {
        return (int) Bd::valor("SELECT (SELECT count(*) FROM bloque b JOIN parrilla p ON p.id_parrilla = b.id_parrilla WHERE b.id_material = ? AND p.estado = 'PUBLICADA')"
            . " + (SELECT count(*) FROM escaleta e JOIN bloque b ON b.id_bloque = e.id_bloque JOIN parrilla p ON p.id_parrilla = b.id_parrilla"
            . " WHERE e.id_material = ? AND p.estado = 'PUBLICADA')", [$id, $id]);
    }

    /** Otros materiales con el mismo titulo, tipo y duracion (posibles duplicados con otra ruta). */
    public function posiblesDuplicados(int $id): array
    {
        $m = Bd::fila('SELECT titulo, tipo, duracion_seg FROM material WHERE id_material = ?', [$id]);
        $filas = Bd::filas("SELECT '#' || id_material || ' ' || titulo AS nombre FROM material"
            . ' WHERE lower(titulo) = lower(?) AND tipo = ? AND duracion_seg = ? AND id_material <> ? ORDER BY id_material',
            [$m['titulo'], $m['tipo'], $m['duracion_seg'], $id]);
        return array_column($filas, 'nombre');
    }

    private function validarExtension(Validador $v, ?string $ruta, ?string $formato): void
    {
        if ($ruta !== null && $formato !== null && !str_ends_with(strtolower($ruta), '.' . strtolower($formato))) {
            $v->error('La ruta <b>' . Validador::esc($ruta) . '</b> debe terminar en <b>.' . strtolower($formato)
                . "</b>: el archivo y el formato ({$formato}) deben coincidir.");
        }
    }

    private function validarRuta(Validador $v, ?string $ruta, int $excluir): void
    {
        $otro = Bd::valor('SELECT titulo FROM material WHERE lower(ruta) = lower(?) AND id_material <> ?', [$ruta, $excluir]);
        if ($otro !== null) {
            $v->error('La ruta <b>' . Validador::esc($ruta) . '</b> ya está registrada en el material <b>' . Validador::esc($otro)
                . '</b> (archivo duplicado). Use ese material.');
        }
    }

    private function resolverPrograma(Validador $v, ?string $nombre): ?int
    {
        if ($nombre === null) {
            return null;
        }
        $id = Bd::valor('SELECT id_programa FROM programa WHERE lower(nombre) = lower(?)', [$nombre]);
        if ($id === null) {
            $v->error('No existe el programa <b>' . Validador::esc($nombre) . '</b>. Consulte el catálogo de programas.');
            return null;
        }
        return (int) $id;
    }
}
