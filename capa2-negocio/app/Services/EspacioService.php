<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Reloj;
use App\Support\Validador;

/**
 * CU6 - Espacios publicitarios: los horarios fijos del canal donde salen los spots (p. ej. Noche, 20:00 a 23:00).
 * Cada espacio tiene un cupo de segundos que se vende por dia y su precio por segundo. Reglas:
 *  - nombre unico; hora_fin > hora_inicio; los espacios no se solapan (cada spot pertenece a un solo espacio por su hora);
 *    el cupo no puede pasar la duracion del horario; precio > 0.
 *  - Si un espacio ya esta contratado por pautas vigentes, no cambia su horario ni baja su cupo de lo ya vendido.
 *    El precio si puede cambiar: lo contratado guarda el precio con que se pidio (pauta_espacio).
 *  - No se elimina un espacio que alguna pauta eligio.
 *  - disEsp: lo que queda libre de cada espacio en un rango de fechas (cupo - lo vendido el dia mas cargado),
 *    contando solo pautas APROBADAS.
 */
class EspacioService
{
    private const SELECT = "SELECT e.id_espacio AS id, e.nombre, to_char(e.hora_inicio, 'HH24:MI') AS hora_inicio,"
        . " to_char(e.hora_fin, 'HH24:MI') AS hora_fin, e.segundos_por_dia, e.precio_segundo::float AS precio_segundo,"
        . ' (SELECT count(*) FROM pauta_espacio pe WHERE pe.id_espacio = e.id_espacio) AS pautas FROM espacio e';

    public function __construct(private BitacoraService $bitacora)
    {
    }

    /** lisEsp: todos los espacios, en orden de hora. */
    public function listar(Usuario $quien, array $p): array
    {
        return ['titulo' => 'Espacios publicitarios', 'datos' => Bd::filas(self::SELECT . ' ORDER BY e.hora_inicio')];
    }

    /** insEsp["nombre","hora_inicio","hora_fin","segundos_por_dia","precio_segundo"]. */
    public function insertar(Usuario $quien, array $p): array
    {
        $e = $this->leer($p, null);
        $id = Bd::insertar('INSERT INTO espacio (nombre, hora_inicio, hora_fin, segundos_por_dia, precio_segundo) VALUES (?, ?, ?, ?, ?)',
            [$e['nombre'], $e['inicio'], $e['fin'], $e['segundos'], $e['precio']], 'id_espacio');
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'espacio', $e['nombre']);
        return ['titulo' => 'Espacio registrado', 'mensaje' => 'El espacio <b>' . Validador::esc($e['nombre']) . '</b> quedó registrado.',
            'datos' => $this->obtener($id)];
    }

    /** modEsp["espacio","nombre","hora_inicio","hora_fin","segundos_por_dia","precio_segundo"]. */
    public function modificar(Usuario $quien, array $p): array
    {
        $id = $this->resolver($p[0] ?? null);
        $actual = $this->obtener($id);
        $e = $this->leer(array_slice($p, 1), $id);
        $v = new Validador();
        $vigentes = (int) Bd::valor("SELECT count(*) FROM pauta_espacio pe JOIN pauta pa ON pa.id_pauta = pe.id_pauta"
            . " WHERE pe.id_espacio = ? AND pa.estado IN ('EN REVISION', 'APROBADA') AND pa.fecha_fin >= ?", [$id, Reloj::hoy()]);
        if ($vigentes > 0 && (Validador::corta($e['inicio']) !== $actual['hora_inicio'] || Validador::corta($e['fin']) !== $actual['hora_fin'])) {
            $v->error("El espacio está contratado por {$vigentes} pauta(s) vigente(s): no se puede cambiar su horario.");
        }
        $vendido = $this->vendidoMaximo($id, Reloj::hoy(), null, null);
        if ($e['segundos'] < $vendido) {
            $v->error("Ya hay {$vendido} s vendidos en un mismo día de este espacio: el cupo no puede ser menor.");
        }
        $v->verificar();
        Bd::ejecutar('UPDATE espacio SET nombre = ?, hora_inicio = ?, hora_fin = ?, segundos_por_dia = ?, precio_segundo = ? WHERE id_espacio = ?',
            [$e['nombre'], $e['inicio'], $e['fin'], $e['segundos'], $e['precio'], $id]);
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'espacio', $e['nombre']);
        return ['titulo' => 'Espacio modificado', 'mensaje' => 'El espacio <b>' . Validador::esc($e['nombre']) . '</b> fue actualizado.'
            . ($e['precio'] !== $actual['precio_segundo'] ? ' El precio nuevo vale para las pautas que se pidan desde ahora.' : ''),
            'datos' => $this->obtener($id)];
    }

    /** eliEsp["espacio"]: solo si ninguna pauta lo eligio. */
    public function eliminar(Usuario $quien, array $p): array
    {
        $id = $this->resolver($p[0] ?? null);
        $e = $this->obtener($id);
        if ((int) $e['pautas'] > 0) {
            throw new NegocioException('El espacio <b>' . Validador::esc($e['nombre']) . "</b> fue elegido por {$e['pautas']} pauta(s): no se puede eliminar.");
        }
        Bd::ejecutar('DELETE FROM espacio WHERE id_espacio = ?', [$id]);
        $this->bitacora->accion($quien, BitacoraService::ELIMINAR, 'espacio', $e['nombre']);
        return ['titulo' => 'Espacio eliminado', 'mensaje' => 'El espacio <b>' . Validador::esc($e['nombre']) . '</b> fue eliminado.', 'datos' => null];
    }

    /** disEsp["fecha_inicio","fecha_fin"]: cada espacio con los segundos libres por dia en ese rango. */
    public function disponibilidad(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $inicio = $v->fecha($p[0] ?? null, 'fecha_inicio');
        $fin = $v->fecha($p[1] ?? null, 'fecha_fin');
        if ($inicio !== null && $fin !== null && $fin < $inicio) {
            $v->error("La <b>fecha_fin</b> ({$fin}) no puede ser anterior a la <b>fecha_inicio</b> ({$inicio}).");
        }
        $v->verificar();
        return ['titulo' => 'Espacios disponibles', 'datos' => $this->disponibles($inicio, $fin, null)];
    }

    // ------------------------------------------------------------------ usado por las pautas

    /** Los espacios con lo libre por dia (cupo - lo vendido el dia mas cargado del rango), sin contar la pauta $excluir. */
    public function disponibles(string $inicio, string $fin, ?int $excluir): array
    {
        return array_map(function (array $e) use ($inicio, $fin, $excluir) {
            $e['libre'] = max(0, (int) $e['segundos_por_dia'] - $this->vendidoMaximo((int) $e['id'], $inicio, $fin, $excluir));
            return $e;
        }, Bd::filas(self::SELECT . ' ORDER BY e.hora_inicio'));
    }

    /** Segundos vendidos (pautas APROBADAS) en el dia mas cargado del espacio entre $desde y $hasta (sin $hasta: desde $desde en adelante). */
    public function vendidoMaximo(int $idEspacio, string $desde, ?string $hasta, ?int $excluir): int
    {
        return (int) Bd::valor('SELECT coalesce(max(v.segundos), 0) FROM (SELECT d::date AS dia, sum(pe.salidas_por_dia * pa.duracion_spot) AS segundos'
            . ' FROM pauta_espacio pe JOIN pauta pa ON pa.id_pauta = pe.id_pauta'
            . ' CROSS JOIN LATERAL generate_series(greatest(pa.fecha_inicio, ?::date), least(pa.fecha_fin, coalesce(?::date, pa.fecha_fin)), interval \'1 day\') d'
            . " WHERE pe.id_espacio = ? AND pa.estado = 'APROBADA' AND pa.id_pauta IS DISTINCT FROM ?::int GROUP BY 1) v",
            [$desde, $hasta, $idEspacio, $excluir]);
    }

    // ------------------------------------------------------------------ auxiliares

    /** [nombre, hora_inicio, hora_fin, segundos_por_dia, precio_segundo] validados ($id: el espacio que se modifica). */
    private function leer(array $p, ?int $id): array
    {
        $v = new Validador();
        $e = [
            'nombre' => $v->texto($p[0] ?? null, 'nombre', 60),
            'inicio' => $v->hora($p[1] ?? null, 'hora_inicio'),
            'fin' => $v->horaFin($p[2] ?? null, 'hora_fin'),
            'segundos' => $v->entero($p[3] ?? null, 'segundos_por_dia', 1, 86400),
            'precio' => $v->monto($p[4] ?? null, 'precio_segundo', 0.01, 100000),
        ];
        $v->verificar();
        if ($e['fin'] <= $e['inicio']) {
            $v->error('La <b>hora_fin</b> debe ser posterior a la <b>hora_inicio</b>.');
        } elseif ($e['segundos'] > Validador::segundos($e['fin']) - Validador::segundos($e['inicio'])) {
            $v->error('El cupo (' . $e['segundos'] . ' s) no puede pasar la duración del horario ('
                . (Validador::segundos($e['fin']) - Validador::segundos($e['inicio'])) . ' s).');
        }
        if (Bd::existe('SELECT 1 FROM espacio WHERE lower(nombre) = lower(?) AND id_espacio IS DISTINCT FROM ?::int', [$e['nombre'], $id])) {
            $v->error('Ya existe un espacio llamado <b>' . Validador::esc($e['nombre']) . '</b>.');
        }
        $choca = Bd::fila("SELECT nombre, to_char(hora_inicio, 'HH24:MI') AS i, to_char(hora_fin, 'HH24:MI') AS f FROM espacio"
            . ' WHERE hora_inicio < ?::time AND ?::time < hora_fin AND id_espacio IS DISTINCT FROM ?::int LIMIT 1', [$e['fin'], $e['inicio'], $id]);
        if ($choca) {
            $v->error('El horario se cruza con el espacio <b>' . Validador::esc($choca['nombre']) . "</b> ({$choca['i']} a {$choca['f']}).");
        }
        $v->verificar();
        return $e;
    }

    public function obtener(int $id): array
    {
        $f = Bd::fila(self::SELECT . ' WHERE e.id_espacio = ?', [$id]);
        if ($f === null) {
            throw new NegocioException('El espacio no existe.', 404);
        }
        return $f;
    }

    /** Espacio por nombre o por id. */
    public function buscarId(string $ref): ?int
    {
        $id = Bd::valor('SELECT id_espacio FROM espacio WHERE lower(nombre) = lower(?)', [$ref]);
        if ($id === null && preg_match('/^\d{1,9}$/', $ref)) {
            $id = Bd::valor('SELECT id_espacio FROM espacio WHERE id_espacio = ?', [(int) $ref]);
        }
        return $id === null ? null : (int) $id;
    }

    private function resolver(mixed $ref): int
    {
        $v = new Validador();
        $r = $v->texto($ref, 'espacio', 60);
        $v->verificar();
        $id = $this->buscarId($r);
        if ($id === null) {
            throw new NegocioException('No existe el espacio <b>' . Validador::esc($r) . '</b>.', 404);
        }
        return $id;
    }
}
