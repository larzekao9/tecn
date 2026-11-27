<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Reloj;
use App\Support\Validador;

/**
 * CU5 - Gestion de Validar Estado de Emision. El Administrador arma la parrilla en BORRADOR (CU3); el Encargado de
 * Contenidos revisa el contenido de cada bloque (ve sus videos: el material, las notas de la escaleta o los spots de
 * la pausa comercial) y lo marca LISTO u OBSERVADO. Recien con TODOS los bloques LISTO la parrilla se puede publicar.
 * Reglas de negocio:
 *  - lisVal: por bloque muestra su contenido (con el enlace a cada video), la verificacion automatica y la validacion.
 *    La parrilla esta VALIDADA cuando todos sus bloques estan LISTO.
 *  - Verificacion automatica:
 *      todo material: con su video cargado (si no hay video no se puede revisar).
 *      PROGRAMA con material: asignado y que quepa en el bloque.
 *      PROGRAMA con escaleta: que la escaleta quepa en el bloque.
 *      PAUSA_COMERCIAL: spots que terminen dentro de la pausa, que entren en la pausa, con su pauta activa, y que la
 *        llenen: el minimo establecido es la duracion de la pausa (sin aire vacio).
 *  - insVal: en bloques que no empezaron y sin emision registrada (en BORRADOR, o en PUBLICADA si un cambio anulo
 *    la validacion). LISTO exige que la verificacion no tenga problemas; OBSERVADO exige la observacion (el motivo).
 *  - La validacion se guarda en el propio bloque (usuario valida bloque): estado_validacion, observacion y validado_por.
 *  - modVal cambia una validacion existente; eliVal la anula (el bloque vuelve a PENDIENTE).
 *  - Si cambia el contenido del bloque (material, escaleta o spots), un trigger lo deja PENDIENTE.
 *  - Un bloque OBSERVADO se avisa al Administrador y al Programador; la parrilla completa LISTA se avisa al Administrador.
 *  - canBlo: con la parrilla PUBLICADA, un bloque EN COLA que no termino se puede CANCELAR (p. ej. breaking news) y,
 *    opcionalmente, indicar el material que sale en su lugar (de programa y que entre en el bloque).
 *    reaBlo lo reanuda mientras no tenga emision registrada. Un bloque cancelado solo se registra NO_EMITIDO (CU7).
 */
class ValidacionService
{
    public const LISTO = 'LISTO';
    public const OBSERVADO = 'OBSERVADO';
    public const SIN_VALIDAR = 'PENDIENTE';
    public const ESTADOS = [self::LISTO, self::OBSERVADO];

    public function __construct(
        private BitacoraService $bitacora,
        private NotificacionService $avisos,
        private ParrillaService $parrillas,
        private MaterialService $materiales,
    ) {
    }

    /** lisVal["fecha"]: estado de emision de cada bloque de la parrilla. */
    public function listar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $v->verificar();
        return ['titulo' => "Validación de la parrilla del {$fecha}", 'datos' => $this->estadoDeParrilla($fecha)];
    }

    /** insVal["fecha","hora_bloque","estado","observacion"]. */
    public function insertar(Usuario $quien, array $p): array
    {
        [$fecha, $b, $estado, $observacion] = $this->entrada($p);
        if ($b['estado'] !== self::SIN_VALIDAR) {
            throw new NegocioException('El bloque de las <b>' . Validador::corta($b['hora_inicio']) . "</b> ya está validado como <b>{$b['estado']}</b>: use Modificar.");
        }
        $this->exigirCoherencia($b, $estado);
        // usuario valida bloque
        Bd::ejecutar('UPDATE bloque SET estado_validacion = ?, observacion = ?, validado_por = ? WHERE id_bloque = ?',
            [$estado, $observacion, $quien->id_usuario, $b['id']]);
        $this->bitacora->accion($quien, BitacoraService::VALIDAR, 'bloque');
        return $this->despues($quien, $fecha, $b, $estado, $observacion, 'Bloque validado');
    }

    /** modVal["fecha","hora_bloque","estado","observacion"]. */
    public function modificar(Usuario $quien, array $p): array
    {
        [$fecha, $b, $estado, $observacion] = $this->entrada($p);
        if ($b['estado'] === self::SIN_VALIDAR) {
            throw new NegocioException('El bloque de las <b>' . Validador::corta($b['hora_inicio']) . '</b> todavía no está validado: use Validar.');
        }
        $this->exigirCoherencia($b, $estado);
        Bd::ejecutar('UPDATE bloque SET estado_validacion = ?, observacion = ?, validado_por = ? WHERE id_bloque = ?',
            [$estado, $observacion, $quien->id_usuario, $b['id']]);
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'bloque');
        return $this->despues($quien, $fecha, $b, $estado, $observacion, 'Validación modificada');
    }

    /** eliVal["fecha","hora_bloque"]: anula la validacion del bloque. */
    public function eliminar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $hora = $v->hora($p[1] ?? null, 'hora_bloque');
        $v->verificar();
        $b = $this->bloqueEditable($fecha, $hora);
        if ($b['estado'] === self::SIN_VALIDAR) {
            throw new NegocioException('El bloque de las <b>' . Validador::corta($hora) . '</b> no tiene una validación que anular.');
        }
        Bd::ejecutar("UPDATE bloque SET estado_validacion = 'PENDIENTE', observacion = NULL, validado_por = NULL WHERE id_bloque = ?", [$b['id']]);
        $this->bitacora->accion($quien, BitacoraService::ANULAR, 'bloque');
        return ['titulo' => 'Validación anulada',
            'mensaje' => 'La validación del bloque de las <b>' . Validador::corta($hora) . '</b> fue anulada: el bloque queda <b>PENDIENTE</b>.',
            'datos' => $this->estadoDeParrilla($fecha)];
    }

    /** canBlo["fecha","hora_bloque","reemplazo"]: cancela un bloque de la parrilla publicada (p. ej. breaking news). */
    public function cancelar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $hora = $v->hora($p[1] ?? null, 'hora_bloque');
        $codigo = $v->textoOpcional($p[2] ?? null, 'reemplazo', 160); // id del material
        $v->verificar();
        [$b, $parrilla] = $this->bloqueDe($fecha, $hora);
        $hc = Validador::corta($b['hora_inicio']);
        if (!$b['puede_cancelar']) {
            throw new NegocioException("No se puede cancelar el bloque de las <b>{$hc}</b>: " . match (true) {
                $parrilla !== 'PUBLICADA' => 'la parrilla está en BORRADOR (ahí el bloque se quita o se cambia en la parrilla).',
                $b['cancelacion'] !== null => 'ya está CANCELADO.',
                $b['estado_bloque'] !== 'EN COLA' => "ya está conciliado ({$b['estado_bloque']}).",
                default => 'el bloque ya terminó.',
            });
        }
        $idReemplazo = null;
        $reemplazo = '';
        if ($codigo !== null && $b['tipo'] === 'PAUSA_COMERCIAL') {
            throw new NegocioException("El bloque de las <b>{$hc}</b> es una pausa comercial: se cancela sin video de reemplazo.");
        }
        if ($codigo !== null) {
            $idReemplazo = $this->materiales->buscarId($codigo);
            if ($idReemplazo === null) {
                throw new NegocioException('No existe el material <b>' . Validador::esc($codigo) . '</b>. Consulte la lista de materiales.');
            }
            $m = $this->materiales->obtener($idReemplazo);
            $cod = Validador::esc($m['titulo']);
            if ($m['tipo'] === 'SPOT') {
                $v->error("El material <b>{$cod}</b> es un spot publicitario: el reemplazo debe ser un material de programa.");
            }
            $duracionBloque = Validador::segundos($b['hora_fin']) - Validador::segundos($b['hora_inicio']);
            if ((int) $m['duracion_seg'] > $duracionBloque) {
                $v->error("El material <b>{$cod}</b> dura {$m['duracion_seg']} s y el bloque {$duracionBloque} s: no entra en su lugar.");
            }
            $v->verificar();
            $reemplazo = $m['titulo'];
        }

        // el bloque cambia su video por el de reemplazo (o queda sin video si no hay reemplazo); el original queda en la bitacora
        $original = Bd::valor('SELECT m.titulo FROM bloque b JOIN material m ON m.id_material = b.id_material WHERE b.id_bloque = ?', [$b['id']]);
        Bd::ejecutar("UPDATE bloque SET estado = 'CANCELADO', id_material = ? WHERE id_bloque = ?", [$idReemplazo, $b['id']]);
        $this->bitacora->accion($quien, BitacoraService::ANULAR, 'bloque', "{$fecha} {$hc} cancelado; video original: "
            . ($original ?? 'ninguno') . '; reemplazo: ' . ($reemplazo !== '' ? $reemplazo : 'ninguno'));
        $enSuLugar = $reemplazo !== '' ? ' En su lugar sale <b>' . NotificacionService::e($reemplazo) . '</b>.' : '';
        $this->avisos->enviar($this->avisos->usuariosDeRoles($quien->id_usuario, 'ADMINISTRADOR', 'PROGRAMADOR', 'ENCARGADO'), "Bloque cancelado: {$fecha} {$hc}", NotificacionService::e($quien->nombreCompleto()) . " canceló el bloque de las <b>{$hc}</b> (" . NotificacionService::e($b['nombre'])
            . ") del <b>{$fecha}</b>." . $enSuLugar);
        return ['titulo' => 'Bloque cancelado',
            'mensaje' => "El bloque de las <b>{$hc}</b> (" . Validador::esc($b['nombre']) . ') quedó <b>CANCELADO</b>.'
                . ($reemplazo !== '' ? ' En su lugar sale <b>' . Validador::esc($reemplazo) . '</b>.' : '') . ' Se avisó al Programador y a Prensa.',
            'datos' => $this->estadoDeParrilla($fecha)];
    }

    /** reaBlo["fecha","hora_bloque"]: deshace la cancelacion (vuelve a EN COLA) mientras no tenga emision registrada. */
    public function reanudar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $hora = $v->hora($p[1] ?? null, 'hora_bloque');
        $v->verificar();
        [$b] = $this->bloqueDe($fecha, $hora);
        $hc = Validador::corta($b['hora_inicio']);
        if (!$b['puede_reanudar']) {
            throw new NegocioException("No se puede reanudar el bloque de las <b>{$hc}</b>: "
                . ($b['cancelacion'] === null ? 'no está cancelado.' : ($b['bloqueo'] === 'Ya está conciliado'
                    ? 'ya está conciliado (NO_EMITIDO).' : 'el bloque ya terminó.')));
        }
        // vuelve a EN COLA con el video que tiene ahora (el de reemplazo, si se eligio uno); el original esta en la bitacora
        Bd::ejecutar("UPDATE bloque SET estado = 'EN COLA' WHERE id_bloque = ?", [$b['id']]);
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'bloque', "{$fecha} {$hc} reanudado");
        $this->avisos->enviar($this->avisos->usuariosDeRoles($quien->id_usuario, 'ADMINISTRADOR', 'PROGRAMADOR', 'ENCARGADO'), "Bloque reanudado: {$fecha} {$hc}", NotificacionService::e($quien->nombreCompleto()) . " reanudó el bloque de las <b>{$hc}</b> (" . NotificacionService::e($b['nombre'])
            . ") del <b>{$fecha}</b>: vuelve a salir con el video que tiene asignado.");
        app(PautaService::class)->programarTodas($quien); // si era una pausa, vuelven sus spots
        return ['titulo' => 'Bloque reanudado',
            'mensaje' => "El bloque de las <b>{$hc}</b> volvió a <b>EN COLA</b> con el video que tiene asignado. Si quiere otro, use <b>Cambiar material</b> en la parrilla.",
            'datos' => $this->estadoDeParrilla($fecha)];
    }

    // ------------------------------------------------------------------ consulta (tambien la usan los avisos automaticos)

    /** Bloques con su contenido, su verificacion automatica y su validacion, mas el resumen de la parrilla. */
    public function estadoDeParrilla(string $fecha): array
    {
        $idParrilla = $this->parrillas->resolver($fecha);
        $estadoParrilla = $this->parrillas->estado($idParrilla);
        $emitidos = array_flip(array_map('intval', array_column(Bd::filas('SELECT c.id_bloque FROM conciliacion c JOIN bloque b ON b.id_bloque = c.id_bloque'
            . ' WHERE b.id_parrilla = ?', [$idParrilla]), 'id_bloque')));

        $bloques = [];
        foreach ($this->parrillas->bloques($idParrilla) as $b) {
            $id = (int) $b['id'];
            [$contenido, $problemas, $videos] = $this->verificar($b);
            $empezo = Reloj::instante($fecha, $b['hora_inicio'])->lessThanOrEqualTo(Reloj::ahora());
            $termino = Reloj::instante($fecha, $b['hora_fin'])->lessThanOrEqualTo(Reloj::ahora());
            $emitido = isset($emitidos[$id]);
            $cancelacion = null;
            if ($b['estado_bloque'] === 'CANCELADO' && !$emitido) { // cancelado a mano (sin conciliacion todavia)
                $r = Bd::fila('SELECT m.id_material, m.ruta FROM bloque b JOIN material m ON m.id_material = b.id_material WHERE b.id_bloque = ?', [$id]);
                $cancelacion = [
                    'reemplazo' => $b['reemplazo'],
                    'titulo_reemplazo' => $b['titulo_reemplazo'],
                    'reemplazo_video_url' => $r ? MaterialService::urlVideo((int) $r['id_material'], $r['ruta']) : null,
                ];
            }
            $bloques[] = [
                'id' => $id,
                'hora_inicio' => $b['hora_inicio'],
                'hora_fin' => $b['hora_fin'],
                'tipo' => $b['tipo'],
                'estado_bloque' => $b['estado_bloque'], // EN COLA / TRANSMITIDO / CANCELADO
                'nombre' => $b['tipo'] === 'PAUSA_COMERCIAL' ? 'Pausa comercial' : $b['programa'],
                'contenido' => $contenido,
                'videos' => $videos,
                'problemas' => $problemas,
                'estado' => $b['estado_validacion'],
                'observacion' => $b['observacion'],
                'validado_por' => $b['validado_por'],
                'editable' => !$empezo && !$emitido && $cancelacion === null,
                // por que no se puede validar (se muestra en lugar de los botones)
                'bloqueo' => $emitido ? 'Ya está conciliado' : ($cancelacion !== null ? 'Bloque cancelado' : ($empezo ? 'Ya salió al aire' : '')),
                'cancelacion' => $cancelacion,
                // breaking news: se cancela un bloque de la parrilla publicada que todavia no termino
                'puede_cancelar' => $estadoParrilla === 'PUBLICADA' && $cancelacion === null && !$emitido && !$termino,
                'puede_reanudar' => $cancelacion !== null && !$emitido && !$termino,
            ];
        }
        $listos = count(array_filter($bloques, fn ($x) => $x['estado'] === self::LISTO));
        $observados = count(array_filter($bloques, fn ($x) => $x['estado'] === self::OBSERVADO));
        return [
            'fecha' => $fecha,
            'parrilla_estado' => $estadoParrilla,
            'bloques' => $bloques,
            'resumen' => ['total' => count($bloques), 'listos' => $listos, 'observados' => $observados, 'sin_validar' => count($bloques) - $listos - $observados],
            'validada' => $bloques !== [] && $listos === count($bloques),
        ];
    }

    // ------------------------------------------------------------------ auxiliares

    /** [descripcion del contenido, problemas que impiden marcarlo LISTO, videos a revisar (en orden de salida)]. */
    private function verificar(array $b): array
    {
        $problemas = [];
        $videos = [];
        $duracionBloque = (int) $b['duracion_seg'];
        $fin = Validador::segundos($b['hora_fin']);

        if ($b['tipo'] === 'PAUSA_COMERCIAL') {
            $spots = Bd::filas("SELECT to_char(s.hora, 'HH24:MI:SS') AS hora, m.id_material, m.titulo, m.duracion_seg, m.ruta,"
                . " pa.nombre AS pauta, ua.nombre || ' ' || ua.apellido AS anunciante, (pa.estado = 'APROBADA') AS pauta_activa"
                . ' FROM spot s JOIN pauta pa ON pa.id_pauta = s.id_pauta JOIN material m ON m.id_material = pa.id_material'
                . ' JOIN usuario ua ON ua.id_usuario = pa.id_usuario'
                . " WHERE s.id_bloque = ? AND s.estado <> 'NO_EMITIDO' ORDER BY s.hora", [$b['id']]);
            $total = 0;
            foreach ($spots as $s) {
                $total += (int) $s['duracion_seg'];
                $videos[] = $this->video($s, $s['hora'], "Pauta {$s['pauta']} · {$s['anunciante']}", $problemas);
                if (Validador::segundos($s['hora']) + (int) $s['duracion_seg'] > $fin) {
                    $problemas[] = "El spot {$s['titulo']} ({$s['hora']}) termina después del fin de la pausa.";
                }
                if (!$s['pauta_activa']) {
                    $problemas[] = "La pauta {$s['pauta']} ya no está aprobada.";
                }
            }
            if ($total > $duracionBloque) {
                $problemas[] = "Los spots suman {$total} s y la pausa dura {$duracionBloque} s.";
            } elseif ($total < $duracionBloque) {
                // la pausa comercial debe salir llena: el minimo establecido es su duracion (no puede quedar aire vacio)
                $problemas[] = "La pausa comercial debe cumplir el mínimo establecido de {$duracionBloque} s y sus spots suman {$total} s (faltan "
                    . ($duracionBloque - $total) . ' s).';
            }
            $contenido = $spots === [] ? 'Sin spots' : count($spots) . " spot(s) · {$total} s de {$duracionBloque} s";
            return [$contenido, array_values(array_unique($problemas)), $videos];
        }

        if ((int) $b['notas'] > 0) {
            $notas = Bd::filas('SELECT e.orden, m.id_material, m.titulo, m.duracion_seg, m.ruta'
                . ' FROM escaleta e JOIN material m ON m.id_material = e.id_material WHERE e.id_bloque = ? ORDER BY e.orden', [$b['id']]);
            $entrada = Validador::segundos($b['hora_inicio']);
            foreach ($notas as $n) {
                $videos[] = $this->video($n, Validador::aHora($entrada), "Nota {$n['orden']} de la escaleta", $problemas);
                $entrada += (int) $n['duracion_seg'];
            }
            $total = array_sum(array_column($notas, 'duracion_seg'));
            if ($total > $duracionBloque) {
                $problemas[] = "La escaleta dura {$total} s y el bloque {$duracionBloque} s.";
            }
            return ['Escaleta de ' . count($notas) . " nota(s) · {$total} s de {$duracionBloque} s", $problemas, $videos];
        }

        $m = Bd::fila('SELECT m.id_material, m.titulo, m.duracion_seg, m.ruta'
            . ' FROM bloque b JOIN material m ON m.id_material = b.id_material WHERE b.id_bloque = ?', [$b['id']]);
        if ($m === null) {
            return ['Sin material', ['El bloque no tiene material asignado.'], []];
        }
        $videos[] = $this->video($m, $b['hora_inicio'], 'Material del programa', $problemas);
        if ((int) $m['duracion_seg'] > $duracionBloque) {
            $problemas[] = "El material {$m['titulo']} dura {$m['duracion_seg']} s y el bloque {$duracionBloque} s.";
        }
        return [$m['titulo'], $problemas, $videos];
    }

    /** Un video a revisar; anota si el material no tiene su archivo. */
    private function video(array $m, string $hora, string $detalle, array &$problemas): array
    {
        $url = MaterialService::urlVideo((int) $m['id_material'], $m['ruta']);
        if ($url === null) {
            $problemas[] = "El material {$m['titulo']} no tiene su video cargado: no se puede revisar.";
        }
        return [
            'hora' => $hora,
            'hora_fin' => Validador::aHora(min(Validador::segundos($hora) + (int) $m['duracion_seg'], 86399)),
            'id_material' => (int) $m['id_material'],
            'titulo' => $m['titulo'],
            'duracion_seg' => (int) $m['duracion_seg'],
            'detalle' => $detalle,
            'video_url' => $url,
        ];
    }

    /** Valida los parametros comunes de insVal / modVal y devuelve [fecha, bloque, estado, observacion]. */
    private function entrada(array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $hora = $v->hora($p[1] ?? null, 'hora_bloque');
        $estado = $v->opcion($p[2] ?? null, 'estado', self::ESTADOS);
        $observacion = $v->textoOpcional($p[3] ?? null, 'observacion', 255);
        if ($estado === self::OBSERVADO && $observacion === null) {
            $v->error('Al marcar un bloque como <b>OBSERVADO</b> la <b>observacion</b> es obligatoria: indique qué falta o qué está mal.');
        }
        $v->verificar();
        return [$fecha, $this->bloqueEditable($fecha, $hora), $estado, $observacion];
    }

    /** [bloque de la vista de validacion, estado de la parrilla]. */
    private function bloqueDe(string $fecha, string $hora): array
    {
        $estado = $this->estadoDeParrilla($fecha);
        $hc = Validador::corta($hora);
        $b = collect($estado['bloques'])->first(fn ($x) => Validador::corta($x['hora_inicio']) === $hc);
        if ($b === null) {
            throw new NegocioException("No hay un bloque que empiece a las <b>{$hc}</b> en la parrilla del {$fecha}.", 404);
        }
        return [$b, $estado['parrilla_estado']];
    }

    /** El bloque de la vista de validacion, exigiendo que todavia se pueda validar. */
    private function bloqueEditable(string $fecha, string $hora): array
    {
        [$b] = $this->bloqueDe($fecha, $hora);
        $hc = Validador::corta($hora);
        if (!$b['editable']) {
            throw new NegocioException("El bloque de las <b>{$hc}</b>: " . mb_strtolower($b['bloqueo']) . '. Su validación ya no se puede cambiar.');
        }
        return $b;
    }

    private function exigirCoherencia(array $b, string $estado): void
    {
        if ($estado === self::LISTO && $b['problemas'] !== []) {
            throw new NegocioException('No se puede validar el bloque de las <b>' . Validador::corta($b['hora_inicio']) . '</b>: '
                . Validador::esc(implode(' ', $b['problemas'])) . ' Corríjalo o márquelo como OBSERVADO.');
        }
    }

    /** Respuesta de insVal / modVal: avisa lo que corresponda y devuelve el estado actualizado. */
    private function despues(Usuario $quien, string $fecha, array $b, string $estado, ?string $observacion, string $titulo): array
    {
        $datos = $this->estadoDeParrilla($fecha);
        $mensaje = 'El bloque de las <b>' . Validador::corta($b['hora_inicio']) . '</b> (' . Validador::esc($b['nombre']) . ") quedó <b>{$estado}</b>.";
        if ($estado === self::OBSERVADO) {
            $this->avisos->enviar($this->avisos->usuariosDeRoles($quien->id_usuario, 'ADMINISTRADOR', 'PROGRAMADOR'), "Bloque observado: {$fecha} " . Validador::corta($b['hora_inicio']), NotificacionService::e($quien->nombreCompleto()) . ' marcó como <b>OBSERVADO</b> el bloque de las ' . Validador::corta($b['hora_inicio'])
                . ' (' . NotificacionService::e($b['nombre']) . ") del <b>{$fecha}</b>.<br><b>Motivo:</b> " . NotificacionService::e((string) $observacion)
                . ($datos['parrilla_estado'] === 'BORRADOR' ? '<br>La parrilla no se podrá publicar hasta que el bloque quede LISTO.' : ''));
        } elseif ($datos['validada'] && $datos['parrilla_estado'] === 'BORRADOR') {
            $mensaje .= ' <b>Todos los bloques están LISTOS</b>: la parrilla ya se puede publicar. Se avisó al Administrador.';
            $this->avisos->enviar($this->avisos->usuariosDeRoles($quien->id_usuario, 'ADMINISTRADOR'), "Parrilla validada: {$fecha}", NotificacionService::e($quien->nombreCompleto()) . " validó todos los bloques de la parrilla del <b>{$fecha}</b>: están LISTOS. Ya puede publicarse.");
        }
        return ['titulo' => $titulo, 'mensaje' => $mensaje, 'datos' => $datos];
    }
}
