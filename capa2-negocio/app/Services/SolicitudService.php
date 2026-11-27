<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Reloj;
use App\Support\Validador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * CU6 - Solicitudes de pauta del anunciante. La solicitud ES la pauta mientras esta EN REVISION (usuario solicita pauta).
 *  - insSol (anunciante): campaña (nombre de la pauta, unico), su video (MP4 o MOV, 1 a 300 s, subido antes con
 *    solicitudes/video; se registra como material SPOT del anunciante), vigencia desde hoy, los espacios publicitarios
 *    con sus salidas por dia (con cupo libre) y el plan de pago. El costo se calcula con el precio de cada espacio.
 *    Queda EN REVISION y se avisa al Administrador.
 *  - canSol (anunciante): cancela su solicitud mientras siga EN REVISION.
 *  - apbSol (Administrador): vuelve a revisar el cupo de cada espacio; la pauta queda APROBADA (cerrada) y se crean sus
 *    cuotas (estado de pago PENDIENTE hasta la 1a).
 *  - recSol (Administrador): la rechaza; se avisa al anunciante.
 *  - lisSol: el anunciante ve todas las suyas; el Administrador, todas (primero las que esperan respuesta).
 */
class SolicitudService
{
    public function __construct(
        private BitacoraService $bitacora,
        private NotificacionService $avisos,
        private PautaService $pautas,
        private ContratoService $contratos,
    ) {
    }

    /** lisSol: las del anunciante, o todas para el Administrador. */
    public function listar(Usuario $quien, array $p): array
    {
        $filas = $quien->rol === 'ANUNCIANTE'
            ? Bd::filas(PautaService::SELECT . ' WHERE pa.id_usuario = ? ORDER BY pa.id_pauta DESC', [$quien->id_usuario])
            : Bd::filas(PautaService::SELECT . " ORDER BY (pa.estado = 'EN REVISION') DESC, pa.id_pauta DESC");
        return ['titulo' => 'Solicitudes de pauta', 'datos' => array_map([$this, 'completar'], $filas)];
    }

    /**
     * insSol["nombre","fecha_inicio","fecha_fin","espacios","frecuencia","cuotas","titulo_spot","ruta","formato","duracion_seg"].
     * espacios: "espacio:salidas por dia" separados por coma, p. ej. "Noche:3,Mañana:2" (nombre o id del espacio).
     */
    public function insertar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $nombre = $v->texto($p[0] ?? null, 'campaña', 150);
        $c = $this->pautas->leerPauta($v, $p[1] ?? null, $p[2] ?? null, $p[4] ?? null, $p[5] ?? null);
        $titulo = $v->texto($p[6] ?? null, 'título del spot', 160);
        $ruta = $v->texto($p[7] ?? null, 'video', 255);
        $formato = $v->opcion($p[8] ?? null, 'formato', ['MP4', 'MOV']);
        $duracion = $v->entero($p[9] ?? null, 'duración del spot', 1, 300);
        if ($c['inicio'] !== null && $c['inicio'] < Reloj::hoy()) {
            $v->error('La vigencia no puede empezar en una fecha pasada.');
        }
        $v->verificar();
        $elegidos = $this->pautas->elegirEspacios($v, $p[3] ?? null, $duracion, $c['inicio'], $c['fin'], null);
        if (!str_starts_with($ruta, 'videos/') || !Storage::disk('local')->exists($ruta)) {
            $v->error('El video no se subió correctamente: vuelva a elegirlo.');
        } elseif (Bd::existe('SELECT 1 FROM material WHERE ruta = ?', [$ruta])) {
            $v->error('Ese video ya está usado en otra solicitud o material.');
        }
        $this->pautas->verificarNombre($v, $nombre);
        $v->verificar();
        $id = DB::transaction(function () use ($quien, $nombre, $c, $titulo, $ruta, $formato, $duracion, $elegidos) {
            // pauta usa material: el video del anunciante queda registrado como material SPOT
            $idMaterial = Bd::insertar("INSERT INTO material (id_usuario, titulo, tipo, duracion_seg, formato, ruta) VALUES (?, ?, 'SPOT', ?, ?, ?)",
                [$quien->id_usuario, $titulo, $duracion, $formato, $ruta], 'id_material');
            return $this->pautas->crear((int) $quien->id_usuario, $idMaterial, $nombre, $c, $duracion, $elegidos, PautaService::EN_REVISION);
        });
        $f = $this->obtener($id);
        $costo = $this->contratos->dinero($f['costo']);
        $quienEs = NotificacionService::e($quien->nombreCompleto());
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'pauta', $nombre);
        $this->avisos->enviar($this->avisos->usuariosDeRoles(null, 'ADMINISTRADOR'), "Nueva solicitud de pauta: {$nombre}", "{$quienEs} solicitó la pauta <b>" . NotificacionService::e($nombre)
            . "</b>: {$f['salidas']} salidas de un spot de {$duracion} s en " . PautaService::textoEspacios($elegidos) . ", {$costo}. Revísela para aceptarla o rechazarla.");
        return ['titulo' => 'Solicitud enviada',
            'mensaje' => 'Su solicitud <b>' . Validador::esc($nombre) . '</b> quedó <b>EN REVISIÓN</b>. El canal revisará su video y le responderá.',
            'datos' => $f];
    }

    /** canSol["id"]: el anunciante cancela su solicitud en revision. */
    public function cancelar(Usuario $quien, array $p): array
    {
        $s = $this->enRevision($p[0] ?? null);
        if ((int) $s['id_usuario'] !== (int) $quien->id_usuario) {
            throw new NegocioException('Solo puede cancelar sus propias solicitudes.', 403);
        }
        Bd::ejecutar("UPDATE pauta SET estado = 'CANCELADA' WHERE id_pauta = ?", [$s['id']]);
        $this->bitacora->accion($quien, BitacoraService::ANULAR, 'pauta');
        return ['titulo' => 'Solicitud cancelada', 'mensaje' => 'La solicitud <b>' . Validador::esc($s['nombre']) . '</b> fue cancelada.', 'datos' => $this->obtener($s['id'])];
    }

    /** apbSol["id"]: la pauta queda APROBADA con sus cuotas. */
    public function aprobar(Usuario $quien, array $p): array
    {
        $s = $this->enRevision($p[0] ?? null);
        if ($s['fecha_fin'] < Reloj::hoy()) {
            throw new NegocioException('La vigencia de la solicitud ya terminó (' . $s['fecha_fin'] . '): recházela para que el anunciante envíe otra.');
        }
        // el cupo pudo ocuparse mientras estaba en revision: se vuelve a revisar cada espacio
        $libres = array_column(app(EspacioService::class)->disponibles($s['fecha_inicio'], $s['fecha_fin'], (int) $s['id']), 'libre', 'id');
        $sinCupo = [];
        foreach ($s['espacios'] as $e) {
            $pide = (int) $e['salidas_por_dia'] * (int) $s['duracion_spot'];
            if ($pide > (int) ($libres[$e['id']] ?? 0)) {
                $sinCupo[] = 'el espacio <b>' . Validador::esc($e['nombre']) . "</b> pide {$pide} s por día y solo quedan " . (int) ($libres[$e['id']] ?? 0) . ' s libres';
            }
        }
        if ($sinCupo) {
            throw new NegocioException('No se puede aceptar: ' . implode('; ', $sinCupo) . '. Recházela para que el anunciante elija otros espacios.');
        }
        DB::transaction(function () use ($s) {
            Bd::ejecutar("UPDATE pauta SET estado = 'APROBADA' WHERE id_pauta = ?", [$s['id']]);
            $this->contratos->crearCuotas((int) $s['id']);
        });
        $plan = PautaService::plan_texto($s['frecuencia'], (int) $s['numero_cuotas']);
        $this->bitacora->accion($quien, BitacoraService::VALIDAR, 'pauta');
        $this->avisos->enviar([(int) $s['id_usuario']], "Pauta aceptada: {$s['nombre']}", 'El canal aceptó su solicitud <b>' . NotificacionService::e($s['nombre']) . '</b>. Pague la primera cuota para que su spot empiece a salir al aire.');
        return ['titulo' => 'Pauta aceptada',
            'mensaje' => 'La pauta <b>' . Validador::esc($s['nombre']) . "</b> quedó APROBADA ({$plan}): su pago queda PENDIENTE hasta la primera cuota. Se avisó al anunciante.",
            'datos' => $this->obtener($s['id'])];
    }

    /** recSol["id"]. */
    public function rechazar(Usuario $quien, array $p): array
    {
        $s = $this->enRevision($p[0] ?? null);
        Bd::ejecutar("UPDATE pauta SET estado = 'RECHAZADA' WHERE id_pauta = ?", [$s['id']]);
        $this->bitacora->accion($quien, BitacoraService::ANULAR, 'pauta');
        $this->avisos->enviar([(int) $s['id_usuario']], "Pauta rechazada: {$s['nombre']}", 'El canal rechazó su solicitud <b>' . NotificacionService::e($s['nombre']) . '</b>.');
        return ['titulo' => 'Pauta rechazada', 'mensaje' => 'La solicitud <b>' . Validador::esc($s['nombre']) . '</b> fue rechazada. Se avisó al anunciante.',
            'datos' => $this->obtener($s['id'])];
    }

    // ------------------------------------------------------------------ auxiliares

    private function obtener(int $id): array
    {
        $f = Bd::fila(PautaService::SELECT . ' WHERE pa.id_pauta = ?', [$id]);
        if ($f === null) {
            throw new NegocioException('La solicitud no existe.', 404);
        }
        return $this->completar($f);
    }

    /** Sus espacios y el enlace firmado al video (el <video> no manda el token). */
    private function completar(array $f): array
    {
        $f['espacios'] = $this->pautas->espaciosDe((int) $f['id']);
        $f['spot'] = $this->pautas->spotAsignado((int) $f['id']);
        $f['video_url'] = $f['spot']['video_url'] ?? null;
        return $f;
    }

    /** La solicitud exigiendo que siga EN REVISION. */
    private function enRevision(mixed $ref): array
    {
        $v = new Validador();
        $id = $v->entero($ref, 'solicitud', 1, PHP_INT_MAX);
        $v->verificar();
        $s = $this->obtener($id);
        if ($s['estado'] !== PautaService::EN_REVISION) {
            throw new NegocioException('La solicitud <b>' . Validador::esc($s['nombre']) . "</b> ya está <b>{$s['estado']}</b>.");
        }
        return $s;
    }
}
