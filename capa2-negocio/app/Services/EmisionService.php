<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Reloj;
use App\Support\Validador;
use Illuminate\Support\Facades\DB;

/**
 * CU7 - Gestion de Conciliar Emision Real, Registrar Auditoria. Reglas de negocio:
 *  - insEmi concilia una vez por bloque lo que realmente salio al aire (tabla conciliacion); la parrilla debe estar PUBLICADA
 *    y el bloque ya empezado. La observacion explica un PARCIAL o un NO_EMITIDO; el desvio del inicio lo calcula la BD.
 *  - Solo sale al aire lo validado (CU5): EMITIDO / PARCIAL exigen que el bloque este validado como LISTO;
 *    un bloque SIN VALIDAR u OBSERVADO solo puede registrarse como NO_EMITIDO.
 *  - EMITIDO / PARCIAL: inicio_real y fin_real del mismo dia, fin > inicio. NO_EMITIDO: sin horas.
 *  - Los spots del bloque toman su estado de la emision: EMITIDO / NO_EMITIDO igual que el bloque;
 *    PARCIAL -> EMITIDO los que caen entre inicio_real y fin_real y NO_EMITIDO los demas (en una transaccion).
 *  - modEmi corrige una emision mal cargada (recalcula sus spots); eliEmi la elimina (sus spots vuelven a PROGRAMADO).
 *  - modSpo corrige si un spot salio o no (solo en bloques con la emision registrada): un spot NO_EMITIDO devuelve el pase a la pauta.
 *  - conPar: por bloque, lo planificado (hora_inicio, hora_fin) contra lo transmitido (inicio_real, fin_real), con el
 *    desvio del inicio y la duracion real que calcula la BD, el resultado y la nota; y los spots de cada pausa.
 *  - Cada registro, correccion y eliminacion queda en la bitacora (quien, que, cuando, IP y navegador).
 */
class EmisionService
{
    private const ESTADOS = ['EMITIDO', 'PARCIAL', 'NO_EMITIDO'];
    private const ESTADOS_SPOT = ['EMITIDO', 'NO_EMITIDO'];

    public function __construct(private BitacoraService $bitacora, private ParrillaService $parrillas)
    {
    }

    /** insEmi["fecha","hora_inicio","inicio_real","fin_real","estado","observacion"]. */
    public function insertar(Usuario $quien, array $p): array
    {
        [$fecha, $horaInicio, $inicioReal, $finReal, $estado, $observacion] = $this->entrada($p);
        $idBloque = $this->bloquePublicado($fecha, $horaInicio);
        if (Bd::existe('SELECT 1 FROM conciliacion WHERE id_bloque = ?', [$idBloque])) {
            throw new NegocioException('El bloque de las <b>' . Validador::corta($horaInicio) . "</b> del {$fecha} ya está conciliado: use Corregir.");
        }
        if (Reloj::instante($fecha, $horaInicio)->greaterThan(Reloj::ahora())) {
            throw new NegocioException('El bloque de las <b>' . Validador::corta($horaInicio) . "</b> del {$fecha} todavía no empezó: se registra lo que ya salió al aire.");
        }
        $this->exigirValidado($idBloque, $horaInicio, $estado);
        [$inicio, $fin] = $this->instantes($fecha, $horaInicio, $inicioReal, $finReal);
        $id = DB::transaction(function () use ($idBloque, $inicio, $fin, $estado, $observacion, $quien, $inicioReal, $finReal) {
            $id = Bd::insertar('INSERT INTO conciliacion (id_bloque, id_usuario, inicio_real, fin_real, estado, observacion) VALUES (?, ?, ?, ?, ?, ?)',
                [$idBloque, $quien->id_usuario, $inicio, $fin, $estado, $observacion], 'id_conciliacion');
            $this->actualizarSpots($idBloque, $estado, $inicioReal, $finReal);
            return $id;
        });
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'conciliacion');
        return ['titulo' => 'Bloque conciliado', 'mensaje' => 'Se concilió el bloque de las <b>' . Validador::corta($horaInicio) . "</b> como <b>{$estado}</b>.",
            'datos' => $this->conciliacion($fecha)];
    }

    /** modEmi["fecha","hora_inicio","inicio_real","fin_real","estado","observacion"]: corrige una conciliacion. */
    public function modificar(Usuario $quien, array $p): array
    {
        [$fecha, $horaInicio, $inicioReal, $finReal, $estado, $observacion] = $this->entrada($p);
        $idBloque = $this->bloquePublicado($fecha, $horaInicio);
        $antes = $this->emisionDe($idBloque, $fecha, $horaInicio);
        $this->exigirValidado($idBloque, $horaInicio, $estado);
        [$inicio, $fin] = $this->instantes($fecha, $horaInicio, $inicioReal, $finReal);
        DB::transaction(function () use ($antes, $idBloque, $inicio, $fin, $estado, $observacion, $quien, $inicioReal, $finReal) {
            Bd::ejecutar('UPDATE conciliacion SET inicio_real = ?, fin_real = ?, estado = ?, observacion = ?, id_usuario = ? WHERE id_conciliacion = ?',
                [$inicio, $fin, $estado, $observacion, $quien->id_usuario, $antes['id']]);
            Bd::ejecutar("UPDATE spot SET estado = 'PROGRAMADO' WHERE id_bloque = ?", [$idBloque]);
            $this->actualizarSpots($idBloque, $estado, $inicioReal, $finReal);
        });
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'conciliacion');
        return ['titulo' => 'Conciliación corregida', 'mensaje' => 'La conciliación del bloque de las <b>' . Validador::corta($horaInicio) . "</b> quedó como <b>{$estado}</b>."
            . ' El estado de sus spots se recalculó.', 'datos' => $this->conciliacion($fecha)];
    }

    /** eliEmi["fecha","hora_inicio"]: elimina un registro de emision equivocado; sus spots vuelven a PROGRAMADO. */
    public function eliminar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $horaInicio = $v->hora($p[1] ?? null, 'hora_inicio');
        $v->verificar();
        $idBloque = $this->bloquePublicado($fecha, $horaInicio);
        $antes = $this->emisionDe($idBloque, $fecha, $horaInicio);
        DB::transaction(function () use ($antes, $idBloque) {
            Bd::ejecutar('DELETE FROM conciliacion WHERE id_conciliacion = ?', [$antes['id']]);
            Bd::ejecutar("UPDATE spot SET estado = 'PROGRAMADO' WHERE id_bloque = ?", [$idBloque]);
        });
        $this->bitacora->accion($quien, BitacoraService::ELIMINAR, 'conciliacion');
        return ['titulo' => 'Conciliación eliminada', 'mensaje' => 'Se eliminó la conciliación del bloque de las <b>' . Validador::corta($horaInicio)
            . '</b>: queda SIN REGISTRO y sus spots vuelven a PROGRAMADO.', 'datos' => $this->conciliacion($fecha)];
    }

    /** modSpo["fecha","hora_spot","estado"]: registra si un spot salio o no al aire. */
    public function spot(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $hora = $v->hora($p[1] ?? null, 'hora_spot');
        $estado = $v->opcion($p[2] ?? null, 'estado', self::ESTADOS_SPOT);
        $v->verificar();
        $s = Bd::fila("SELECT s.id_spot, s.estado, s.id_bloque, m.titulo, pa.nombre AS pauta, (c.id_conciliacion IS NOT NULL) AS con_emision"
            . ' FROM spot s JOIN bloque b ON b.id_bloque = s.id_bloque JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla'
            . ' JOIN pauta pa ON pa.id_pauta = s.id_pauta JOIN material m ON m.id_material = pa.id_material'
            . ' LEFT JOIN conciliacion c ON c.id_bloque = s.id_bloque WHERE pr.fecha = ? AND s.hora = ?', [$fecha, $hora]);
        if ($s === null) {
            throw new NegocioException("No hay un spot el <b>{$fecha}</b> a las <b>{$hora}</b>.", 404);
        }
        if (!$s['con_emision']) {
            throw new NegocioException("Primero concilie la pausa comercial del spot de las <b>{$hora}</b>.");
        }
        if ($s['estado'] === $estado) {
            throw new NegocioException("El spot de las <b>{$hora}</b> ya está <b>{$estado}</b>.");
        }
        Bd::ejecutar('UPDATE spot SET estado = ? WHERE id_spot = ?', [$estado, $s['id_spot']]);
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'spot');
        return ['titulo' => 'Spot actualizado', 'mensaje' => 'El spot <b>' . Validador::esc($s['titulo']) . "</b> de las {$hora} quedó <b>{$estado}</b>."
            . ($estado === 'NO_EMITIDO' ? ' El pase vuelve a quedar disponible en la pauta.' : ''), 'datos' => $this->conciliacion($fecha)];
    }

    /** conPar["fecha"]. */
    public function conciliar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $v->verificar();
        return ['titulo' => "Conciliación del {$fecha}", 'datos' => $this->conciliacion($fecha)];
    }

    public function conciliacion(string $fecha): array
    {
        $id = $this->parrillas->resolver($fecha);
        $bloques = Bd::filas("SELECT b.id_bloque AS id, to_char(b.hora_inicio, 'HH24:MI:SS') AS hora_inicio, to_char(b.hora_fin, 'HH24:MI:SS') AS hora_fin,"
            . " b.tipo, coalesce(p.nombre, '') AS programa, coalesce(c.estado, 'SIN REGISTRO') AS estado,"
            . " coalesce(to_char(c.inicio_real, 'HH24:MI:SS'), '') AS inicio_real, coalesce(to_char(c.fin_real, 'HH24:MI:SS'), '') AS fin_real,"
            . " c.desvio_inicio AS desviacion_seg, c.duracion_real AS duracion_seg, coalesce(c.observacion, '') AS observacion,"
            . " b.estado_validacion AS validacion,"
            . " (b.estado = 'CANCELADO' AND c.id_conciliacion IS NULL) AS cancelado"
            . ' FROM bloque b JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla LEFT JOIN programa p ON p.id_programa = b.id_programa'
            . ' LEFT JOIN conciliacion c ON c.id_bloque = b.id_bloque'
            . ' WHERE b.id_parrilla = ? ORDER BY b.hora_inicio', [$id]);
        $spots = Bd::filas("SELECT s.id_spot AS id, s.id_bloque, to_char(s.hora, 'HH24:MI:SS') AS hora, m.id_material AS material, m.titulo, m.duracion_seg,"
            . " pa.nombre AS pauta, ua.nombre || ' ' || ua.apellido AS anunciante, s.estado FROM spot s JOIN pauta pa ON pa.id_pauta = s.id_pauta"
            . ' JOIN material m ON m.id_material = pa.id_material JOIN usuario ua ON ua.id_usuario = pa.id_usuario'
            . ' JOIN bloque b ON b.id_bloque = s.id_bloque WHERE b.id_parrilla = ? ORDER BY s.hora', [$id]);
        $ahora = Reloj::ahora();
        foreach ($bloques as &$b) {
            $b['empezo'] = Reloj::instante($fecha, $b['hora_inicio'])->lessThanOrEqualTo($ahora);
            $b['registrada'] = $b['estado'] !== 'SIN REGISTRO';
        }
        unset($b);
        return [
            'fecha' => $fecha,
            'parrilla_estado' => $this->parrillas->estado($id),
            'bloques' => $bloques,
            'spots' => $spots,
        ];
    }

    // ------------------------------------------------------------------ auxiliares

    /** [fecha, hora_inicio, inicio_real, fin_real, estado, observacion] validados. */
    private function entrada(array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $horaInicio = $v->hora($p[1] ?? null, 'hora_inicio');
        $estado = $v->opcion($p[4] ?? null, 'estado', self::ESTADOS);
        $inicioReal = null;
        $finReal = null;
        if ($estado !== 'NO_EMITIDO') {
            $inicioReal = $v->hora($p[2] ?? null, 'inicio_real');
            $finReal = $v->hora($p[3] ?? null, 'fin_real');
            if ($inicioReal !== null && $finReal !== null && $finReal <= $inicioReal) {
                $v->error("El <b>fin_real</b> ({$finReal}) debe ser posterior al <b>inicio_real</b> ({$inicioReal}); ambas horas son del mismo día.");
            }
        }
        $observacion = $v->textoOpcional($p[5] ?? null, 'observacion', 255);
        if ($estado !== 'EMITIDO' && $observacion === null) {
            $v->error("Un bloque <b>{$estado}</b> necesita la <b>observacion</b>: indique qué pasó.");
        }
        $v->verificar();
        return [$fecha, $horaInicio, $inicioReal, $finReal, $estado, $observacion];
    }

    private function bloquePublicado(string $fecha, string $horaInicio): int
    {
        if ($fecha > Reloj::hoy()) {
            throw new NegocioException("La parrilla del <b>{$fecha}</b> es de una fecha futura (hoy es " . Reloj::hoy()
                . '): solo se registra la emisión de lo que ya salió al aire.');
        }
        $idParrilla = $this->parrillas->resolver($fecha);
        if ($this->parrillas->estado($idParrilla) !== 'PUBLICADA') {
            throw new NegocioException("La parrilla del <b>{$fecha}</b> sigue en BORRADOR: solo se concilian parrillas publicadas.");
        }
        return $this->parrillas->buscarBloque($idParrilla, $horaInicio, $fecha);
    }

    /** Para salir al aire (EMITIDO / PARCIAL) el bloque tiene que estar validado como LISTO en el CU5. */
    private function exigirValidado(int $idBloque, string $horaInicio, string $estado): void
    {
        if ($estado === 'NO_EMITIDO') {
            return;
        }
        // cancelado a mano: CANCELADO sin conciliacion
        if (Bd::existe("SELECT 1 FROM bloque b WHERE b.id_bloque = ? AND b.estado = 'CANCELADO'"
            . ' AND NOT EXISTS (SELECT 1 FROM conciliacion c WHERE c.id_bloque = b.id_bloque)', [$idBloque])) {
            throw new NegocioException('El bloque de las <b>' . Validador::corta($horaInicio) . '</b> fue <b>CANCELADO</b>'
                . ': su contenido no salió al aire. Regístrelo como NO_EMITIDO.');
        }
        $validacion = Bd::valor('SELECT estado_validacion FROM bloque WHERE id_bloque = ?', [$idBloque]);
        if ($validacion !== 'LISTO') {
            throw new NegocioException('El bloque de las <b>' . Validador::corta($horaInicio) . "</b> está <b>{$validacion}</b> en la validación de emisión (CU5): "
                . 'solo sale al aire un bloque validado como LISTO. Regístrelo como NO_EMITIDO.');
        }
    }

    private function emisionDe(int $idBloque, string $fecha, string $horaInicio): array
    {
        $e = Bd::fila("SELECT id_conciliacion AS id, estado, coalesce(to_char(inicio_real, 'HH24:MI:SS') || '-' || to_char(fin_real, 'HH24:MI:SS'), '') AS real"
            . ' FROM conciliacion WHERE id_bloque = ?', [$idBloque]);
        if ($e === null) {
            throw new NegocioException('El bloque de las <b>' . Validador::corta($horaInicio) . "</b> del {$fecha} no está conciliado.", 404);
        }
        return $e;
    }

    /** NO_EMITIDO: sin horas reales. */
    private function instantes(string $fecha, string $horaInicio, ?string $inicioReal, ?string $finReal): array
    {
        return [$inicioReal === null ? null : "{$fecha} {$inicioReal}", $finReal === null ? null : "{$fecha} {$finReal}"];
    }

    private function actualizarSpots(int $idBloque, string $estado, ?string $inicioReal, ?string $finReal): void
    {
        if ($estado === 'PARCIAL') {
            Bd::ejecutar("UPDATE spot SET estado = CASE WHEN hora >= ? AND hora < ? THEN 'EMITIDO' ELSE 'NO_EMITIDO' END"
                . " WHERE id_bloque = ? AND estado = 'PROGRAMADO'", [$inicioReal, $finReal, $idBloque]);
        } else {
            Bd::ejecutar("UPDATE spot SET estado = ? WHERE id_bloque = ? AND estado = 'PROGRAMADO'", [$estado, $idBloque]);
        }
    }
}
