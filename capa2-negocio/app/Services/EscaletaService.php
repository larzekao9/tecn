<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Reloj;
use App\Support\Validador;
use Illuminate\Support\Facades\DB;

/**
 * Escaleta del noticiero (lisLea, insLea, modLea, eliLea): el orden de las notas ("lead 1 / video 1") dentro de un bloque
 * de un programa NOTICIERO. Reglas de negocio:
 *  - La nota es un material que no sea SPOT, del mismo programa del bloque (si tiene programa).
 *  - Una nota aparece una sola vez por bloque; el orden va de 1 a n sin huecos.
 *  - La suma de las duraciones de las notas no supera la duracion del bloque.
 *  - Se puede cambiar mientras el bloque no haya empezado (tambien con la parrilla PUBLICADA: se avisa al Programador).
 *  - lisLea muestra la hora estimada de entrada de cada nota.
 */
class EscaletaService
{
    private const PROGRAMAS_DE_PRENSA = ['NOTICIERO'];

    public function __construct(
        private BitacoraService $bitacora,
        private NotificacionService $avisos,
        private ParrillaService $parrillas,
        private MaterialService $materiales,
    ) {
    }

    /** lisLea["fecha","hora_bloque"]. */
    public function listar(Usuario $quien, array $p): array
    {
        $c = $this->contexto($p[0] ?? null, $p[1] ?? null);
        return ['titulo' => 'Escaleta de ' . $c['bloque']['programa'] . ' (' . Validador::corta($c['bloque']['hora_inicio']) . ')',
            'datos' => $this->datos($c)];
    }

    /** insLea["fecha","hora_bloque","orden","material"]. */
    public function insertar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $v->fecha($p[0] ?? null, 'fecha');
        $v->hora($p[1] ?? null, 'hora_bloque');
        $orden = $v->entero($p[2] ?? null, 'orden', 1, 500);
        $codigo = $v->texto($p[3] ?? null, 'material', 160);
        $v->verificar();

        $c = $this->contexto($p[0], $p[1]);
        $this->exigirNoIniciado($c);
        $cantidad = (int) Bd::valor('SELECT count(*) FROM escaleta WHERE id_bloque = ?', [$c['id_bloque']]);
        if ($orden > $cantidad + 1) {
            $v->error("El <b>orden</b> {$orden} no es válido: la escaleta tiene {$cantidad} nota(s), el siguiente orden es <b>" . ($cantidad + 1) . '</b>.');
        }
        $idMaterial = $this->materiales->buscarId($codigo);
        $m = null;
        if ($idMaterial === null) {
            $v->error('No existe el material <b>' . Validador::esc($codigo) . '</b>. Consulte la lista de materiales.');
        } else {
            $m = $this->materiales->obtener($idMaterial);
            $cod = Validador::esc($m['titulo']);
            if ($m['tipo'] === 'SPOT') {
                $v->error("El material <b>{$cod}</b> es un SPOT: los spots van en una pausa comercial.");
            }
            if ($m['programa'] !== '' && strcasecmp($m['programa'], $c['bloque']['programa']) !== 0) {
                $v->error("El material <b>{$cod}</b> pertenece al programa <b>" . Validador::esc($m['programa'])
                    . '</b>, pero el bloque es de <b>' . Validador::esc($c['bloque']['programa']) . '</b>.');
            }
            if ($this->ordenDe($c['id_bloque'], $idMaterial) !== null) {
                $v->error("La nota <b>{$cod}</b> ya está en la escaleta de este bloque (cambie su orden).");
            }
        }
        $v->verificar();

        $total = (int) Bd::valor('SELECT coalesce(sum(m.duracion_seg), 0) FROM escaleta e JOIN material m ON m.id_material = e.id_material WHERE e.id_bloque = ?',
            [$c['id_bloque']]) + (int) $m['duracion_seg'];
        if ($total > (int) $c['bloque']['duracion_seg']) {
            throw new NegocioException('Con la nota <b>' . Validador::esc($m['titulo']) . "</b> la escaleta dura {$total} s y el bloque solo "
                . "{$c['bloque']['duracion_seg']} s ({$c['bloque']['hora_inicio']}-{$c['bloque']['hora_fin']}): no cabe.");
        }

        DB::transaction(function () use ($c, $orden, $idMaterial) {
            Bd::ejecutar('UPDATE escaleta SET orden = orden + 1 WHERE id_bloque = ? AND orden >= ?', [$c['id_bloque'], $orden]);
            Bd::ejecutar('INSERT INTO escaleta (id_bloque, orden, id_material) VALUES (?, ?, ?)', [$c['id_bloque'], $orden, $idMaterial]);
        });
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'escaleta');
        $this->avisarCambio($quien, $c, 'Se agregó la nota <b>' . NotificacionService::e($m['titulo']) . "</b> en el orden {$orden}.");
        return ['titulo' => 'Nota agregada', 'mensaje' => 'La nota <b>' . Validador::esc($m['titulo']) . "</b> quedó en el orden <b>{$orden}</b>.",
            'datos' => $this->datos($c)];
    }

    /** modLea["fecha","hora_bloque","material","nuevo_orden"]. */
    public function mover(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $v->fecha($p[0] ?? null, 'fecha');
        $v->hora($p[1] ?? null, 'hora_bloque');
        $codigo = $v->texto($p[2] ?? null, 'material', 160);
        $nuevo = $v->entero($p[3] ?? null, 'nuevo_orden', 1, 500);
        $v->verificar();

        $c = $this->contexto($p[0], $p[1]);
        $this->exigirNoIniciado($c);
        $idMaterial = $this->materialDeLaEscaleta($c, $codigo);
        $actual = $this->ordenDe($c['id_bloque'], $idMaterial);
        $cantidad = (int) Bd::valor('SELECT count(*) FROM escaleta WHERE id_bloque = ?', [$c['id_bloque']]);
        if ($nuevo > $cantidad) {
            throw new NegocioException("El <b>nuevo_orden</b> {$nuevo} no es válido: la escaleta tiene {$cantidad} nota(s).");
        }
        if ($nuevo === $actual) {
            throw new NegocioException('La nota <b>' . Validador::esc($this->materiales->obtener($idMaterial)['titulo']) . "</b> ya está en el orden {$actual}.");
        }
        // Una sola sentencia: el UNIQUE (id_bloque, orden) es DEFERRABLE
        DB::transaction(function () use ($c, $idMaterial, $actual, $nuevo) {
            DB::statement('SET CONSTRAINTS uq_escaleta_orden DEFERRED');
            Bd::ejecutar('UPDATE escaleta SET orden = CASE WHEN id_material = ? THEN ? ELSE orden + ? END WHERE id_bloque = ? AND orden BETWEEN ? AND ?',
                [$idMaterial, $nuevo, $nuevo < $actual ? 1 : -1, $c['id_bloque'], min($actual, $nuevo), max($actual, $nuevo)]);
        });
        $cod = $this->materiales->obtener($idMaterial)['titulo'];
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'escaleta');
        $this->avisarCambio($quien, $c, 'La nota <b>' . NotificacionService::e($cod) . "</b> pasó del orden {$actual} al {$nuevo}.");
        return ['titulo' => 'Orden cambiado', 'mensaje' => 'La nota <b>' . Validador::esc($cod) . "</b> pasó del orden {$actual} al <b>{$nuevo}</b>.",
            'datos' => $this->datos($c)];
    }

    /** eliLea["fecha","hora_bloque","material"]. */
    public function eliminar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $v->fecha($p[0] ?? null, 'fecha');
        $v->hora($p[1] ?? null, 'hora_bloque');
        $codigo = $v->texto($p[2] ?? null, 'material', 160);
        $v->verificar();

        $c = $this->contexto($p[0], $p[1]);
        $this->exigirNoIniciado($c);
        $idMaterial = $this->materialDeLaEscaleta($c, $codigo);
        $orden = $this->ordenDe($c['id_bloque'], $idMaterial);
        DB::transaction(function () use ($c, $idMaterial, $orden) {
            Bd::ejecutar('DELETE FROM escaleta WHERE id_bloque = ? AND id_material = ?', [$c['id_bloque'], $idMaterial]);
            Bd::ejecutar('UPDATE escaleta SET orden = orden - 1 WHERE id_bloque = ? AND orden > ?', [$c['id_bloque'], $orden]);
        });
        $cod = $this->materiales->obtener($idMaterial)['titulo'];
        $this->bitacora->accion($quien, BitacoraService::ELIMINAR, 'escaleta');
        $this->avisarCambio($quien, $c, 'Se quitó la nota <b>' . NotificacionService::e($cod) . "</b> (orden {$orden}).");
        return ['titulo' => 'Nota quitada', 'mensaje' => 'La nota <b>' . Validador::esc($cod) . '</b> fue quitada de la escaleta.', 'datos' => $this->datos($c)];
    }

    // ------------------------------------------------------------------ auxiliares

    /** Notas con hora estimada de entrada (inicio del bloque + duracion de las anteriores). */
    public function notas(int $idBloque, string $horaInicio): array
    {
        $entrada = Validador::segundos($horaInicio);
        $filas = Bd::filas('SELECT e.orden, m.id_material AS material, m.titulo, m.duracion_seg'
            . ' FROM escaleta e JOIN material m ON m.id_material = e.id_material WHERE e.id_bloque = ? ORDER BY e.orden', [$idBloque]);
        foreach ($filas as &$n) {
            $n['entrada'] = Validador::aHora($entrada);
            $entrada += (int) $n['duracion_seg'];
        }
        return $filas;
    }

    private function datos(array $c): array
    {
        $notas = $this->notas($c['id_bloque'], $c['bloque']['hora_inicio']);
        $usado = array_sum(array_column($notas, 'duracion_seg'));
        return [
            'fecha' => $c['fecha'],
            'bloque' => $c['bloque'],
            'parrilla_estado' => $this->parrillas->estado($c['id_parrilla']),
            'editable' => Reloj::instante($c['fecha'], $c['bloque']['hora_inicio'])->greaterThan(Reloj::ahora()),
            'duracion_usada' => $usado,
            'duracion_libre' => (int) $c['bloque']['duracion_seg'] - $usado,
            'notas' => $notas,
        ];
    }

    /** Resuelve fecha + hora del bloque y exige que sea de un noticiero o revista. */
    private function contexto(mixed $fechaTxt, mixed $horaTxt): array
    {
        $v = new Validador();
        $fecha = $v->fecha($fechaTxt, 'fecha');
        $hora = $v->hora($horaTxt, 'hora_bloque');
        $v->verificar();
        $idParrilla = $this->parrillas->resolver($fecha);
        $idBloque = $this->parrillas->buscarBloque($idParrilla, $hora, $fecha);
        $b = $this->parrillas->bloque($idBloque);
        if ($b['tipo'] !== 'PROGRAMA' || !in_array($b['tipo_programa'], self::PROGRAMAS_DE_PRENSA, true)) {
            throw new NegocioException('El bloque de las <b>' . Validador::corta($hora) . "</b> del {$fecha} no es de un noticiero ni de una revista: "
                . 'la escaleta solo se arma en bloques de programas tipo NOTICIERO.');
        }
        return ['fecha' => $fecha, 'id_parrilla' => $idParrilla, 'id_bloque' => $idBloque, 'bloque' => $b];
    }

    private function exigirNoIniciado(array $c): void
    {
        if (Reloj::instante($c['fecha'], $c['bloque']['hora_inicio'])->lessThanOrEqualTo(Reloj::ahora())) {
            throw new NegocioException('El bloque de las <b>' . Validador::corta($c['bloque']['hora_inicio']) . "</b> del {$c['fecha']} ya empezó: la escaleta ya no se puede cambiar.");
        }
    }

    private function ordenDe(int $idBloque, int $idMaterial): ?int
    {
        $o = Bd::valor('SELECT orden FROM escaleta WHERE id_bloque = ? AND id_material = ?', [$idBloque, $idMaterial]);
        return $o === null ? null : (int) $o;
    }

    private function materialDeLaEscaleta(array $c, string $codigo): int
    {
        $id = $this->materiales->buscarId($codigo);
        if ($id === null || $this->ordenDe($c['id_bloque'], $id) === null) {
            throw new NegocioException('La nota <b>' . Validador::esc($codigo) . '</b> no está en la escaleta del bloque de las '
                . Validador::corta($c['bloque']['hora_inicio']) . '.');
        }
        return $id;
    }

    /** Con la parrilla PUBLICADA, el cambio del orden se avisa al Programador (y al Administrador). */
    private function avisarCambio(Usuario $quien, array $c, string $detalle): void
    {
        if ($this->parrillas->estado($c['id_parrilla']) !== 'PUBLICADA') {
            return;
        }
        $notas = array_map(fn ($n) => [
            NotificacionService::e((string) $n['orden']), NotificacionService::e(Validador::corta($n['entrada'])),
            NotificacionService::e($n['titulo']),
        ], $this->notas($c['id_bloque'], $c['bloque']['hora_inicio']));
        $this->avisos->enviar($this->avisos->usuariosDeRoles($quien->id_usuario, 'PROGRAMADOR', 'ADMINISTRADOR'), "Cambio en el orden de {$c['bloque']['programa']} ({$c['fecha']})", NotificacionService::e($quien->nombreCompleto()) . ' cambió la escaleta del bloque de las ' . Validador::corta($c['bloque']['hora_inicio'])
            . " del <b>{$c['fecha']}</b> (parrilla publicada). {$detalle}<p>Nuevo orden:</p>"
            . NotificacionService::tabla(['Orden', 'Entrada', 'Nota'], $notas));
    }
}
